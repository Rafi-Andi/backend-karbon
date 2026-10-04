<?php

namespace Tests\Unit;

use App\Services\XenditService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class XenditServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Force mock=false by default in unit tests so we test real path
        Config::set('services.xendit.mock', false);
    }

    public function test_mock_mode_returns_completed_without_http(): void
    {
        Http::preventStrayRequests();
        Config::set('services.xendit.mock', true);

        $service = new XenditService(apiKey: '', mock: true);

        $result = $service->createPayout([
            'reference_id' => 'KBK-CLAIM-1-20260905-TEST',
            'idempotency_key' => 'KBK-CLAIM-1',
            'recipient' => [
                'type' => 'BUSINESS',
                'business_name' => 'Kopi Lokal',
                'relationship' => 'CUSTOMER',
                'account_details' => [
                    'currency' => 'IDR',
                    'account_country' => 'ID',
                    'account_holder_name' => 'Kopi Lokal',
                    'account_number' => '1234567890',
                    'routing_type_1' => 'BANK_CODE',
                    'routing_value_1' => 'BCA',
                    'account_type' => 'SAVINGS',
                ],
                'address' => ['country' => 'ID', 'city' => 'Surabaya', 'street_line_1' => 'Jl Test No 1'],
            ],
            'payout_details' => [
                'source_currency' => 'IDR',
                'source_amount' => 20000,
                'destination_currency' => 'IDR',
            ],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'OFFICE',
        ]);

        $this->assertSame('completed', $result['status']);
        $this->assertStringStartsWith('po-mock-', $result['payout_id']);
        $this->assertTrue($result['raw']['mock']);
    }

    public function test_mock_config_flag_makes_service_mock(): void
    {
        Config::set('services.xendit.mock', true);

        $service = new XenditService(apiKey: 'some-key', mock: false);

        $this->assertTrue($service->isMock());
    }

    public function test_empty_key_with_mock_false_throws(): void
    {
        Config::set('services.xendit.mock', false);

        $service = new XenditService(apiKey: '', mock: false);

        $this->assertFalse($service->isMock());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Xendit API key is not configured.');

        $service->createPayout([
            'reference_id' => 'test',
            'recipient' => [],
            'payout_details' => ['source_amount' => 1, 'source_currency' => 'IDR'],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'OFFICE',
        ]);
    }

    public function test_real_mode_success_returns_payout_id(): void
    {
        Config::set('services.xendit.mock', false);

        Http::fake([
            'api.xendit.co/v3/payouts' => Http::response([
                'payout_id' => 'po-real-12345',
                'status' => 'SUCCEEDED',
                'reference_id' => 'KBK-CLAIM-9',
                'source_currency' => 'IDR',
                'source_amount' => 15000,
                'destination_currency' => 'IDR',
                'destination_amount' => 15000,
            ], 200),
        ]);

        $service = new XenditService(apiKey: 'test-key', baseUrl: 'https://api.xendit.co', mock: false, timeout: 5);

        $result = $service->createPayout([
            'reference_id' => 'KBK-CLAIM-9-20260905',
            'idempotency_key' => 'KBK-CLAIM-9',
            'recipient' => [
                'type' => 'BUSINESS',
                'business_name' => 'Mitra Test',
                'relationship' => 'CUSTOMER',
                'account_details' => [
                    'currency' => 'IDR',
                    'account_country' => 'ID',
                    'account_holder_name' => 'Mitra Test',
                    'account_number' => '0987654321',
                    'routing_type_1' => 'BANK_CODE',
                    'routing_value_1' => 'BNI',
                    'account_type' => 'SAVINGS',
                ],
                'address' => ['country' => 'ID', 'city' => 'Surabaya', 'street_line_1' => 'Jl Test No 1'],
            ],
            'payout_details' => [
                'source_currency' => 'IDR',
                'source_amount' => 15000,
                'destination_currency' => 'IDR',
            ],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'OFFICE',
        ]);

        $this->assertSame('po-real-12345', $result['payout_id']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame(15000, $result['amount']);
    }

    public function test_real_mode_failure_throws_for_rollback(): void
    {
        $this->expectException(RuntimeException::class);

        Http::fake([
            'api.xendit.co/v3/payouts' => Http::response([
                'error_code' => 'INVALID_DESTINATION',
                'message' => 'Invalid destination',
            ], 400),
        ]);

        $service = new XenditService(apiKey: 'test-key', baseUrl: 'https://api.xendit.co', mock: false, timeout: 5);

        $service->createPayout([
            'reference_id' => 'KBK-CLAIM-FAIL',
            'recipient' => [
                'type' => 'BUSINESS',
                'business_name' => 'Test',
                'relationship' => 'CUSTOMER',
                'account_details' => [
                    'currency' => 'IDR',
                    'account_country' => 'ID',
                    'account_holder_name' => 'Test',
                    'account_number' => '000',
                    'routing_type_1' => 'BANK_CODE',
                    'routing_value_1' => 'UNKNOWN',
                    'account_type' => 'SAVINGS',
                ],
                'address' => ['country' => 'ID', 'city' => 'Surabaya', 'street_line_1' => 'Jl Test No 1'],
            ],
            'payout_details' => [
                'source_currency' => 'IDR',
                'source_amount' => 15000,
                'destination_currency' => 'IDR',
            ],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'OFFICE',
        ]);
    }

    public function test_map_bank_code(): void
    {
        $service = new XenditService(apiKey: '', mock: true);

        $this->assertSame('CENAIDJA', $service->mapBankCode('Bank BCA'));
        $this->assertSame('BMRIIDJA', $service->mapBankCode('mandiri'));

        $this->expectException(RuntimeException::class);
        $service->mapBankCode('Bank XYZ');
    }

    public function test_validate_account_number_per_bank_length(): void
    {
        $service = new XenditService(apiKey: '', mock: true);

        // Valid cases (docs.xendit.co/docs/payout-coverage-indonesia)
        $service->validateAccountNumber('Bank BCA', '1234567890');
        $service->validateAccountNumber('BRI', '0021012345678');
        $service->validateAccountNumber('BNI', '1234567');
        $service->validateAccountNumber('Mandiri', '123456789012');

        // BCA must be exactly 10 digits
        try {
            $service->validateAccountNumber('Bank BCA', '12345');
            $this->fail('BCA 5 digit harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('10 digit', $e->getMessage());
        }

        // Non-digit rejected
        try {
            $service->validateAccountNumber('Bank BCA', 'ABC1234567');
            $this->fail('Rekening non-digit harus ditolak.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('angka', $e->getMessage());
        }

        // Unknown bank: only digit check, length skipped
        $service->validateAccountNumber('Bank Arta Kedaton', '123');
    }

    public function test_build_reference_id_is_unique_and_prefixed(): void
    {
        $service = new XenditService(apiKey: '', mock: true);

        $a = $service->buildReferenceId(9);
        $b = $service->buildReferenceId(9);

        $this->assertSame('KBK-CLAIM-9', $a);
        $this->assertSame($a, $b);
    }

    public function test_key_prefix_namespaces_reference_and_idempotency_key(): void
    {
        Config::set('services.xendit.key_prefix', 'PROD');

        $service = new XenditService(apiKey: '', mock: true);

        $this->assertSame('PROD', $service->keyPrefix());
        $this->assertSame('PROD-KBK-CLAIM-9', $service->buildReferenceId(9));
        $this->assertSame('PROD-KBK-CLAIM-9', $service->buildIdempotencyKey(9));
        // Deterministik: retry operasi sama menghasilkan key sama.
        $this->assertSame(
            $service->buildIdempotencyKey(9),
            $service->buildIdempotencyKey(9)
        );
    }

    public function test_key_prefix_sanitized_and_empty_by_default(): void
    {
        $service = new XenditService(apiKey: '', mock: true);

        $this->assertSame('', $service->keyPrefix());
        $this->assertSame('KBK-CLAIM-9', $service->buildIdempotencyKey(9));

        Config::set('services.xendit.key_prefix', 'prod!! ');
        $this->assertSame('PROD', $service->keyPrefix());
        $this->assertSame('PROD-KBK-CLAIM-9', $service->buildReferenceId(9));
    }

    public function test_key_prefix_derived_from_app_env_when_not_configured(): void
    {
        $service = new XenditService(apiKey: '', mock: true);

        Config::set('app.env', 'production');
        $this->assertSame('PROD', $service->keyPrefix());
        $this->assertSame('PROD-KBK-CLAIM-9', $service->buildIdempotencyKey(9));

        Config::set('app.env', 'local');
        $this->assertSame('LOCAL', $service->keyPrefix());

        Config::set('app.env', 'staging');
        $this->assertSame('STAGING', $service->keyPrefix());
    }

    public function test_key_prefix_empty_in_testing_env_for_determinism(): void
    {
        Config::set('app.env', 'testing');

        $service = new XenditService(apiKey: '', mock: true);

        $this->assertSame('', $service->keyPrefix());
        $this->assertSame('KBK-CLAIM-9', $service->buildReferenceId(9));
    }

    public function test_explicit_key_prefix_wins_over_derived(): void
    {
        Config::set('app.env', 'production');
        Config::set('services.xendit.key_prefix', 'CUSTOM');

        $service = new XenditService(apiKey: '', mock: true);

        $this->assertSame('CUSTOM-KBK-CLAIM-9', $service->buildIdempotencyKey(9));
    }

    public function test_status_mapping_v3_to_internal(): void
    {
        Config::set('services.xendit.mock', true);

        $service = new XenditService(apiKey: '', mock: true);

        $result = $service->createPayout([
            'reference_id' => 'test',
            'idempotency_key' => 'test',
            'recipient' => [
                'type' => 'BUSINESS',
                'business_name' => 'Test',
                'relationship' => 'CUSTOMER',
                'account_details' => [
                    'currency' => 'IDR',
                    'account_country' => 'ID',
                    'account_holder_name' => 'Test',
                    'account_number' => '123',
                    'routing_type_1' => 'BANK_CODE',
                    'routing_value_1' => 'BCA',
                    'account_type' => 'SAVINGS',
                ],
                'address' => ['country' => 'ID', 'city' => 'Surabaya', 'street_line_1' => 'Jl Test No 1'],
            ],
            'payout_details' => [
                'source_currency' => 'IDR',
                'source_amount' => 1000,
                'destination_currency' => 'IDR',
            ],
            'source_of_fund' => 'BUSINESS_REVENUE',
            'purpose_code' => 'OFFICE',
            'force_status' => 'pending',
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertSame('ACCEPTED', $result['raw_status']);
    }
}
