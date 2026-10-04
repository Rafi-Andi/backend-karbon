<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class XenditService
{
    public const DEFAULT_BASE_URL = 'https://api.xendit.co';

    public const TIMEOUT_SECONDS = 20;

    public const API_VERSION = '2025-09-01';

    protected string $apiKey;

    protected string $baseUrl;

    protected int $timeout;

    protected bool $mock;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?bool $mock = null,
        ?int $timeout = null,
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.xendit.key', '');
        $this->baseUrl = rtrim(
            $baseUrl ?? (string) config('services.xendit.base_url', self::DEFAULT_BASE_URL),
            '/'
        );
        $this->mock = $mock ?? (bool) config('services.xendit.mock', false);
        $this->timeout = $timeout ?? (int) config(
            'services.xendit.timeout',
            self::TIMEOUT_SECONDS
        );
    }

    public static function fromConfig(): self
    {
        return new self;
    }

    public function isMock(): bool
    {
        // Respect both instance flag and global config so tests can flip via Config::set()
        return $this->mock || (bool) config('services.xendit.mock', false);
    }

    public function buildReferenceId(int $claimId): string
    {
        // Stable reference is preferable for retries.
        $prefix = $this->keyPrefix();

        return $prefix === '' ? 'KBK-CLAIM-'.$claimId : $prefix.'-KBK-CLAIM-'.$claimId;
    }

    /**
     * Idempotency key untuk satu operasi redeem. Deterministik per claim
     * (retry yang identik aman dari double payout) dan ter-namespace per
     * environment via XENDIT_KEY_PREFIX agar tidak bertabrakan dengan
     * operasi beda body dari environment lain yang memakai API key sama.
     */
    public function buildIdempotencyKey(int $claimId): string
    {
        return $this->buildReferenceId($claimId);
    }

    /**
     * Prefix namespace, dinormalisasi ke [A-Z0-9-] agar aman untuk
     * reference_id & idempotency key Xendit.
     *
     * Prioritas: XENDIT_KEY_PREFIX eksplisit dulu; bila kosong, turunkan
     * otomatis dari APP_ENV (production→PROD, local→LOCAL, env lain→
     * uppercase-nya) sehingga tidak perlu konfigurasi per environment.
     * Environment testing sengaja tanpa prefix (format lawas) agar test
     * deterministik. Override manual tetap tersedia untuk kasus khusus
     * (mis. dua VPS production berbagi satu API key).
     */
    public function keyPrefix(): string
    {
        $explicit = strtoupper(trim((string) config('services.xendit.key_prefix', '')));

        if ($explicit === '') {
            $env = strtolower((string) config('app.env', ''));

            if ($env === '' || $env === 'testing') {
                return '';
            }

            $explicit = match ($env) {
                'production' => 'PROD',
                'local' => 'LOCAL',
                default => strtoupper($env),
            };
        }

        $prefix = preg_replace('/[^A-Z0-9-]/', '', $explicit) ?? '';

        return trim($prefix, '-');
    }

    /**
     * Returns the SWIFT/BIC routing value used by Payout v3
     * for supported Indonesian bank accounts.
     *
     * Do not fall back to the legacy Xendit bank code: Payout v3
     * does not define a generic "BANK" routing type.
     */
    public function mapBankRouting(string $bankName): array
    {
        $normalized = $this->normalizeBankName($bankName);

        $map = [
            'BCA' => ['routing_type' => 'SWIFT', 'routing_value' => 'CENAIDJA'],
            'BNI' => ['routing_type' => 'SWIFT', 'routing_value' => 'BNINIDJA'],
            'BRI' => ['routing_type' => 'SWIFT', 'routing_value' => 'BRINIDJA'],
            'MANDIRI' => ['routing_type' => 'SWIFT', 'routing_value' => 'BMRIIDJA'],
            'CIMB' => ['routing_type' => 'SWIFT', 'routing_value' => 'BNIAIDJA'],
            'CIMBNIAGA' => ['routing_type' => 'SWIFT', 'routing_value' => 'BNIAIDJA'],
            'DANAMON' => ['routing_type' => 'SWIFT', 'routing_value' => 'BDINIDJA'],
            'PERMATA' => ['routing_type' => 'SWIFT', 'routing_value' => 'BBBAIDJA'],
            'BTN' => ['routing_type' => 'SWIFT', 'routing_value' => 'BTANIDJA'],
            'BSI' => ['routing_type' => 'SWIFT', 'routing_value' => 'BSMDIDJA'],
            'BANKSYARIAHINDONESIA' => ['routing_type' => 'SWIFT', 'routing_value' => 'BSMDIDJA'],
        ];

        if (! isset($map[$normalized])) {
            throw new RuntimeException(
                "Unsupported Indonesian bank for Xendit Payout v3: {$bankName}. ".
                'Add its supported SWIFT/BIC routing value before enabling payout.'
            );
        }

        return $map[$normalized];
    }

    /**
     * Validasi format nomor rekening per bank Indonesia.
     * Aturan panjang dari docs.xendit.co/docs/payout-coverage-indonesia.
     * Bank di luar daftar: lewati cek panjang (hanya pastikan digit).
     *
     * @throws RuntimeException jika format tidak valid.
     */
    public function validateAccountNumber(string $bankName, string $accountNumber): void
    {
        $digits = preg_replace('/[\s\-_.]/', '', trim($accountNumber)) ?? '';

        if ($digits === '' || ! ctype_digit($digits)) {
            throw new RuntimeException('Nomor rekening harus berupa angka.');
        }

        $lengthRanges = [
            'BCA' => [10, 10],
            'BRI' => [13, 17],
            'BNI' => [7, 11],
            'MANDIRI' => [12, 17],
            'PERMATA' => [7, 16],
        ];

        $normalized = $this->normalizeBankName($bankName);

        if (! isset($lengthRanges[$normalized])) {
            return;
        }

        [$min, $max] = $lengthRanges[$normalized];
        $len = strlen($digits);

        if ($len < $min || $len > $max) {
            $expected = $min === $max ? "{$min} digit" : "{$min}-{$max} digit";
            throw new RuntimeException(
                "Nomor rekening {$normalized} harus {$expected} (diterima {$len} digit)."
            );
        }
    }

    /**
     * Backward-compatible helpers. New code should use mapBankRouting().
     */
    public function mapBankCode(string $bankName): string
    {
        return $this->mapBankRouting($bankName)['routing_value'];
    }

    public function mapRoutingType(string $bankName): string
    {
        return $this->mapBankRouting($bankName)['routing_type'];
    }

    public function createPayout(array $payload, ?string $idempotencyKey = null): array
    {
        if ($this->isMock()) {
            return $this->mockCreatePayout($payload);
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Xendit API key is not configured.');
        }

        $this->validatePayoutPayload($payload);

        $idempotencyKey ??= $this->buildIdempotencyKey(
            (int) (preg_replace('/\D+/', '', (string) ($payload['reference_id'] ?? '')) ?: 0)
        );

        if (strlen($idempotencyKey) > 100) {
            throw new RuntimeException('Xendit idempotency key must not exceed 100 characters.');
        }

        // Catat request (nomor rekening dimask agar aman di log).
        Log::info('Xendit payout request', [
            'reference_id' => $payload['reference_id'] ?? null,
            'idempotency_key' => $idempotencyKey,
            'source_amount' => $payload['payout_details']['source_amount'] ?? null,
            'bank' => $payload['recipient']['account_details']['routing_value_1'] ?? null,
            'account_last4' => substr(
                (string) ($payload['recipient']['account_details']['account_number'] ?? ''),
                -4
            ),
        ]);

        try {
            $response = $this->sendPayoutRequest($payload, $idempotencyKey);
        } catch (\Throwable $e) {
            throw new RuntimeException(
                'Xendit API timeout or unreachable: '.$e->getMessage(),
                0,
                $e
            );
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            $errorCode = (string) ($body['error_code'] ?? $body['code'] ?? 'UNKNOWN');
            $message = (string) ($body['message'] ?? "HTTP {$response->status()}");
            $errors = $body['errors'] ?? null;

            Log::error('Xendit Payout API request failed', [
                'reference_id' => $payload['reference_id'] ?? null,
                'http_status' => $response->status(),
                'error_code' => $errorCode,
                'error_message' => $message,
                'errors' => $errors,
                'response' => $body,
            ]);

            $detail = $message;
            if (is_array($errors) && $errors !== []) {
                $detail .= ' | errors: '.json_encode($errors, JSON_UNESCAPED_SLASHES);
            }

            throw new RuntimeException(sprintf(
                'Xendit Payout API failed (HTTP %d, code: %s): %s',
                $response->status(),
                $errorCode,
                $detail
            ));
        }

        return $this->normalizePayoutResponse($body);
    }

    protected function sendPayoutRequest(array $payload, string $idempotencyKey)
    {
        $headers = [
            'api-version' => self::API_VERSION,
            'idempotency-key' => $idempotencyKey,
        ];

        // Never send the idempotency key inside JSON body.
        unset($payload['idempotency_key']);

        return Http::withBasicAuth($this->apiKey, '')
            ->acceptJson()
            ->asJson()
            ->withHeaders($headers)
            ->timeout($this->timeout)
            ->post($this->baseUrl.'/v3/payouts', $payload);
    }

    protected function normalizePayoutResponse(array $data): array
    {
        // Payout v3 lifecycle: https://docs.xendit.co/docs/payout-status-lifecycle
        // Accept all documented variants (APIdocs vs lifecycle page naming differs)
        $statusMap = [
            'ACCEPTED' => 'pending',
            'REQUESTED' => 'pending',
            'READY' => 'pending',
            'LOCKED' => 'pending',
            'ROUTING' => 'pending',
            'PENDING_COMPLIANCE_REVIEW' => 'pending',
            'PENDING_COMPLIANCE_ASSESSMENT' => 'pending',
            'SUCCEEDED' => 'completed',
            'FAILED' => 'failed',
            'REVERSED' => 'reversed',
            'REJECTED' => 'rejected',
            'COMPLIANCE_REJECTED' => 'rejected',
            'CANCELLED' => 'cancelled',
            'EXPIRED' => 'expired',
        ];

        $rawStatus = strtoupper((string) ($data['status'] ?? 'ACCEPTED'));

        return [
            'payout_id' => $data['payout_id'] ?? null,
            'reference_id' => $data['reference_id'] ?? null,
            'status' => $statusMap[$rawStatus] ?? strtolower($rawStatus),
            'raw_status' => $rawStatus,
            'amount' => $data['source_amount'] ?? null,
            'currency' => $data['source_currency'] ?? null,
            'destination_amount' => $data['destination_amount'] ?? null,
            'destination_currency' => $data['destination_currency'] ?? null,
            'failure_code' => $data['failure_code'] ?? null,
            'estimated_arrival_time' => $data['estimated_arrival_time'] ?? null,
            'raw' => $data,
        ];
    }

    protected function mockCreatePayout(array $payload): array
    {
        $amount = (int) ($payload['payout_details']['source_amount'] ?? 0);

        // Allow tests to simulate non-completed states via force_status
        $force = strtolower((string) ($payload['force_status'] ?? ''));
        $map = [
            'pending' => ['status' => 'pending', 'raw' => 'ACCEPTED'],
            'failed' => ['status' => 'failed', 'raw' => 'FAILED'],
            'reversed' => ['status' => 'reversed', 'raw' => 'REVERSED'],
            'rejected' => ['status' => 'rejected', 'raw' => 'REJECTED'],
            'cancelled' => ['status' => 'cancelled', 'raw' => 'CANCELLED'],
            'expired' => ['status' => 'expired', 'raw' => 'EXPIRED'],
        ];

        if (isset($map[$force])) {
            $status = $map[$force]['status'];
            $rawStatus = $map[$force]['raw'];
        } else {
            $status = 'completed';
            $rawStatus = 'SUCCEEDED';
        }

        return [
            'payout_id' => 'po-mock-'.Str::random(12),
            'reference_id' => $payload['reference_id'] ?? null,
            'status' => $status,
            'raw_status' => $rawStatus,
            'amount' => $amount,
            'currency' => $payload['payout_details']['source_currency'] ?? 'IDR',
            'destination_amount' => $amount,
            'destination_currency' => $payload['payout_details']['destination_currency'] ?? 'IDR',
            'failure_code' => null,
            'estimated_arrival_time' => now()->addMinutes(5)->toISOString(),
            'raw' => [
                'mock' => true,
                'payout_id' => 'po-mock',
                'reference_id' => $payload['reference_id'] ?? null,
                'status' => $rawStatus,
                'amount' => $amount,
            ],
        ];
    }

    protected function validatePayoutPayload(array $payload): void
    {
        foreach (['reference_id', 'recipient', 'payout_details', 'source_of_fund', 'purpose_code'] as $field) {
            if (! isset($payload[$field])) {
                throw new RuntimeException("Xendit Payout payload missing required key: {$field}.");
            }
        }

        $recipient = $payload['recipient'];
        $account = $recipient['account_details'] ?? [];
        $payout = $payload['payout_details'];

        foreach ([
            'currency',
            'account_country',
            'account_holder_name',
            'account_number',
            'routing_type_1',
            'routing_value_1',
        ] as $field) {
            if (! isset($account[$field]) || trim((string) $account[$field]) === '') {
                throw new RuntimeException("Xendit recipient.account_details missing required key: {$field}.");
            }
        }

        if (($account['currency'] ?? null) !== 'IDR' || ($account['account_country'] ?? null) !== 'ID') {
            throw new RuntimeException('This KarbonKita payout flow only supports IDR bank accounts in Indonesia.');
        }

        if (! isset($payout['source_amount']) || (int) $payout['source_amount'] <= 0) {
            throw new RuntimeException('Xendit Payout source_amount must be greater than zero.');
        }

        if (($payout['source_currency'] ?? null) !== 'IDR'
            || ($payout['destination_currency'] ?? null) !== 'IDR') {
            throw new RuntimeException('This KarbonKita payout flow only supports IDR to IDR.');
        }

        if (! in_array($recipient['type'] ?? null, ['INDIVIDUAL', 'BUSINESS'], true)) {
            throw new RuntimeException('Xendit recipient.type must be INDIVIDUAL or BUSINESS.');
        }

        if (($recipient['type'] ?? null) === 'INDIVIDUAL') {
            foreach (['given_name', 'surname'] as $field) {
                if (trim((string) ($recipient[$field] ?? '')) === '') {
                    throw new RuntimeException("Xendit recipient missing required field: {$field}.");
                }
            }
        }

        if (($recipient['type'] ?? null) === 'BUSINESS'
            && trim((string) ($recipient['business_name'] ?? '')) === '') {
            throw new RuntimeException('Xendit business recipient requires business_name.');
        }

        if (! isset($recipient['address']) || ! is_array($recipient['address'])) {
            throw new RuntimeException('Xendit recipient.address is required.');
        }

        $address = $recipient['address'];
        foreach (['country', 'city', 'street_line_1'] as $field) {
            if (! isset($address[$field]) || trim((string) $address[$field]) === '') {
                throw new RuntimeException("Xendit recipient.address missing required key: {$field}.");
            }
        }

        if (mb_strlen((string) $payload['reference_id']) > 255) {
            throw new RuntimeException('Xendit reference_id must not exceed 255 characters.');
        }

        if (mb_strlen((string) ($payload['description'] ?? '')) > 100) {
            throw new RuntimeException('Xendit description must not exceed 100 characters.');
        }
    }

    protected function normalizeBankName(string $bankName): string
    {
        $normalized = strtoupper(trim($bankName));
        $normalized = preg_replace('/^BANK\s+/u', '', $normalized) ?? $normalized;
        $normalized = str_replace([' ', '.', '-', '_'], '', $normalized);

        return match ($normalized) {
            'BANKCENTRALASIA', 'BCA' => 'BCA',
            'BANKNEGARAINDONESIA', 'BNI' => 'BNI',
            'BANKRAKYATINDONESIA', 'BRI' => 'BRI',
            'BANKMANDIRI', 'MANDIRI' => 'MANDIRI',
            'BANKCIMBNIAGA', 'CIMBNIAGA', 'CIMB' => 'CIMB',
            'BANKDANAMON', 'DANAMON' => 'DANAMON',
            'BANKPERMATA', 'PERMATA' => 'PERMATA',
            'BANKTABUNGANNEGARA', 'BTN' => 'BTN',
            'BANKSYARIAHINDONESIA', 'BSI' => 'BSI',
            default => $normalized,
        };
    }

    public function getPayout(string $payoutId): array
    {
        if ($this->isMock()) {
            throw new RuntimeException('Get payout is not available in mock mode.');
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Xendit API key is not configured.');
        }

        $response = Http::withBasicAuth($this->apiKey, '')
            ->acceptJson()
            ->withHeaders(['api-version' => self::API_VERSION])
            ->timeout($this->timeout)
            ->get($this->baseUrl.'/v3/payouts/'.rawurlencode($payoutId));

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            throw new RuntimeException(
                sprintf(
                    'Xendit Get Payout failed (HTTP %d): %s',
                    $response->status(),
                    $body['message'] ?? $response->body()
                )
            );
        }

        return $this->normalizePayoutResponse($body);
    }

    /**
     * Legacy method kept only so older callers fail explicitly instead of
     * silently continuing to use the old /v2/disbursements API.
     */
    public function createDisbursement(array $payload): array
    {
        throw new RuntimeException(
            'Legacy Xendit disbursement is disabled. Use createPayout() with Payout API v3.'
        );
    }
}
