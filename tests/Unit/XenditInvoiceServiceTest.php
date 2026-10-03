<?php

namespace Tests\Unit;

use App\Services\XenditInvoiceService;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class XenditInvoiceServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Config::set('services.xendit.mock', false);
    }

    public function test_mock_mode_returns_invoice_url_without_http(): void
    {
        Http::preventStrayRequests();
        Config::set('services.xendit.mock', true);

        $service = new XenditInvoiceService(apiKey: '', mock: true);

        $result = $service->createInvoice([
            'external_id' => 'DN-TEST-001',
            'amount' => 50000,
            'description' => 'Donasi test',
            'currency' => 'IDR',
            'invoice_duration' => 86400,
        ]);

        $this->assertSame('pending', $result['status']);
        $this->assertStringStartsWith('inv-mock-', $result['invoice_id']);
        $this->assertStringStartsWith('https://', $result['invoice_url']);
        $this->assertTrue($result['raw']['mock']);
    }

    public function test_mock_config_flag_makes_service_mock(): void
    {
        Config::set('services.xendit.mock', true);

        $service = new XenditInvoiceService(apiKey: 'some-key', mock: false);

        $this->assertTrue($service->isMock());
    }

    public function test_empty_key_with_mock_false_throws(): void
    {
        Config::set('services.xendit.mock', false);

        $service = new XenditInvoiceService(apiKey: '', mock: false);

        $this->assertFalse($service->isMock());
        $this->expectException(RuntimeException::class);

        $service->createInvoice([
            'external_id' => 'DN-TEST-002',
            'amount' => 50000,
            'description' => 'Donasi test',
        ]);
    }

    public function test_real_mode_success_returns_invoice(): void
    {
        Config::set('services.xendit.mock', false);

        Http::fake([
            'api.xendit.co/v2/invoices' => Http::response([
                'id' => 'inv-real-123',
                'external_id' => 'DN-TEST-003',
                'invoice_url' => 'https://checkout.xendit.co/web/inv-real-123',
                'status' => 'PENDING',
                'expiry_date' => '2026-09-09T10:00:00Z',
                'amount' => 50000,
            ], 200),
        ]);

        $service = new XenditInvoiceService(apiKey: 'test-key', mock: false);

        $result = $service->createInvoice([
            'external_id' => 'DN-TEST-003',
            'amount' => 50000,
            'description' => 'Donasi test',
            'currency' => 'IDR',
        ]);

        $this->assertSame('inv-real-123', $result['invoice_id']);
        $this->assertSame('pending', $result['status']);
        $this->assertStringStartsWith('https://', $result['invoice_url']);
    }

    public function test_real_mode_failure_throws(): void
    {
        $this->expectException(RuntimeException::class);

        Http::fake([
            'api.xendit.co/v2/invoices' => Http::response(['message' => 'Invalid amount'], 400),
        ]);

        $service = new XenditInvoiceService(apiKey: 'test-key', mock: false);

        $service->createInvoice([
            'external_id' => 'DN-TEST-004',
            'amount' => 50000,
            'description' => 'Donasi test',
        ]);
    }

    public function test_invalid_payload_throws_before_http(): void
    {
        Http::preventStrayRequests();
        Config::set('services.xendit.mock', false);

        $service = new XenditInvoiceService(apiKey: 'test-key', mock: false);

        $this->expectException(RuntimeException::class);
        $service->createInvoice(['external_id' => 'DN-BAD', 'amount' => 0, 'description' => 'x']);
    }

    public function test_build_external_id_prefixed(): void
    {
        $service = new XenditInvoiceService(apiKey: '', mock: true);

        $this->assertStringStartsWith('DN-', $service->buildExternalId());
    }
}
