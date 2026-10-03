<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Xendit Invoice API v2 (money-in) untuk donasi.
 * Referensi: xendit-node/xendit-php SDK docs (InvoiceApi) +
 * docs.xendit.co payment-links-api-overview (2026).
 *
 * - POST {base}/v2/invoices {external_id, amount, description,
 *   payer_email?, invoice_duration, currency, customer?,
 *   success_redirect_url?, failure_redirect_url?}
 *   -> {id, external_id, invoice_url, status PENDING, expiry_date}
 * - GET {base}/v2/invoices/{id}
 * - POST {base}/invoices/{id}/expire!
 * - Webhook: flat object {id, external_id, status PAID|EXPIRED,
 *   paid_amount, payment_channel, paid_at}, header x-callback-token.
 *
 * Auth: Basic (api key sebagai username). Mode mock mengikuti
 * XENDIT_MOCK agar dev/test tidak menyentuh API asli.
 */
class XenditInvoiceService
{
    public const DEFAULT_BASE_URL = 'https://api.xendit.co';

    public const TIMEOUT_SECONDS = 20;

    protected string $apiKey;

    protected string $baseUrl;

    protected int $timeout;

    protected bool $mock;

    protected int $duration;

    public function __construct(
        ?string $apiKey = null,
        ?string $baseUrl = null,
        ?bool $mock = null,
        ?int $timeout = null,
        ?int $duration = null,
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.xendit.key', '');
        $this->baseUrl = rtrim(
            $baseUrl ?? (string) config('services.xendit.base_url', self::DEFAULT_BASE_URL),
            '/'
        );
        $this->mock = $mock ?? (bool) config('services.xendit.mock', false);
        $this->timeout = $timeout ?? (int) config('services.xendit.timeout', self::TIMEOUT_SECONDS);
        $this->duration = $duration ?? (int) config('services.xendit.invoice_duration', 86400);
    }

    public static function fromConfig(): self
    {
        return new self;
    }

    public function isMock(): bool
    {
        return $this->mock || (bool) config('services.xendit.mock', false);
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function buildExternalId(): string
    {
        return 'DN-'.now()->format('YmdHis').'-'.strtoupper(Str::random(6));
    }

    /**
     * @return array{invoice_id: ?string, external_id: ?string, invoice_url: ?string, status: string, raw_status: ?string, expiry: ?string, amount: mixed, raw: array}
     */
    public function createInvoice(array $payload): array
    {
        if ($this->isMock()) {
            return $this->mockCreateInvoice($payload);
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Xendit API key is not configured.');
        }

        $this->validateInvoicePayload($payload);

        try {
            $response = Http::withBasicAuth($this->apiKey, '')
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->post($this->baseUrl.'/v2/invoices', $payload);
        } catch (\Throwable $e) {
            throw new RuntimeException('Xendit API timeout or unreachable: '.$e->getMessage(), 0, $e);
        }

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            $message = (string) ($body['message'] ?? "HTTP {$response->status()}");
            $errors = $body['errors'] ?? $body['error_code'] ?? null;

            Log::error('Xendit Invoice API request failed', [
                'external_id' => $payload['external_id'] ?? null,
                'http_status' => $response->status(),
                'error_message' => $message,
                'errors' => $errors,
                'response' => $body,
            ]);

            throw new RuntimeException(sprintf(
                'Xendit Invoice API failed (HTTP %d): %s',
                $response->status(),
                $message
            ));
        }

        return $this->normalizeInvoiceResponse($body);
    }

    public function getInvoice(string $invoiceId): array
    {
        if ($this->isMock()) {
            throw new RuntimeException('Get invoice is not available in mock mode.');
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Xendit API key is not configured.');
        }

        $response = Http::withBasicAuth($this->apiKey, '')
            ->acceptJson()
            ->timeout($this->timeout)
            ->get($this->baseUrl.'/v2/invoices/'.rawurlencode($invoiceId));

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Xendit Get Invoice failed (HTTP %d): %s',
                $response->status(),
                $body['message'] ?? $response->body()
            ));
        }

        return $this->normalizeInvoiceResponse($body);
    }

    public function expireInvoice(string $invoiceId): array
    {
        if ($this->isMock()) {
            return [
                'invoice_id' => $invoiceId,
                'external_id' => null,
                'invoice_url' => null,
                'status' => 'expired',
                'raw_status' => 'EXPIRED',
                'expiry' => now()->toISOString(),
                'amount' => null,
                'raw' => ['mock' => true, 'id' => $invoiceId, 'status' => 'EXPIRED'],
            ];
        }

        if ($this->apiKey === '') {
            throw new RuntimeException('Xendit API key is not configured.');
        }

        $response = Http::withBasicAuth($this->apiKey, '')
            ->acceptJson()
            ->asJson()
            ->timeout($this->timeout)
            ->post($this->baseUrl.'/invoices/'.rawurlencode($invoiceId).'/expire!');

        $body = $response->json();
        $body = is_array($body) ? $body : [];

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Xendit Expire Invoice failed (HTTP %d): %s',
                $response->status(),
                $body['message'] ?? $response->body()
            ));
        }

        return $this->normalizeInvoiceResponse($body);
    }

    protected function normalizeInvoiceResponse(array $data): array
    {
        // Invoice lifecycle: PENDING -> PAID|SETTLED (lunas) atau EXPIRED.
        $rawStatus = strtoupper((string) ($data['status'] ?? 'PENDING'));
        $status = match ($rawStatus) {
            'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            default => 'pending',
        };

        return [
            'invoice_id' => $data['id'] ?? null,
            'external_id' => $data['external_id'] ?? null,
            'invoice_url' => $data['invoice_url'] ?? null,
            'status' => $status,
            'raw_status' => $rawStatus,
            'expiry' => $data['expiry_date'] ?? null,
            'amount' => $data['amount'] ?? null,
            'raw' => $data,
        ];
    }

    protected function mockCreateInvoice(array $payload): array
    {
        // Allow tests to simulate paid/expired without HTTP.
        $force = strtolower((string) ($payload['force_status'] ?? ''));
        $map = [
            'paid' => ['status' => 'paid', 'raw' => 'PAID'],
            'expired' => ['status' => 'expired', 'raw' => 'EXPIRED'],
        ];
        $status = $map[$force]['status'] ?? 'pending';
        $rawStatus = $map[$force]['raw'] ?? 'PENDING';

        $invoiceId = 'inv-mock-'.Str::random(12);

        return [
            'invoice_id' => $invoiceId,
            'external_id' => $payload['external_id'] ?? null,
            'invoice_url' => 'https://mock.xendit.co/invoice/'.$invoiceId,
            'status' => $status,
            'raw_status' => $rawStatus,
            'expiry' => now()->addSeconds($payload['invoice_duration'] ?? $this->duration)->toISOString(),
            'amount' => $payload['amount'] ?? null,
            'raw' => [
                'mock' => true,
                'id' => $invoiceId,
                'external_id' => $payload['external_id'] ?? null,
                'status' => $rawStatus,
                'amount' => $payload['amount'] ?? null,
            ],
        ];
    }

    protected function validateInvoicePayload(array $payload): void
    {
        foreach (['external_id', 'amount', 'description'] as $field) {
            if (! isset($payload[$field]) || trim((string) $payload[$field]) === '') {
                throw new RuntimeException("Xendit Invoice payload missing required key: {$field}.");
            }
        }

        if (! is_numeric($payload['amount']) || (float) $payload['amount'] <= 0) {
            throw new RuntimeException('Xendit Invoice amount must be greater than zero.');
        }

        if (($payload['currency'] ?? 'IDR') !== 'IDR') {
            throw new RuntimeException('This KarbonKita donation flow only supports IDR.');
        }

        if (isset($payload['payer_email']) && $payload['payer_email'] !== ''
            && ! filter_var($payload['payer_email'], FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Xendit Invoice payer_email is not a valid email.');
        }

        if (mb_strlen((string) $payload['external_id']) > 255) {
            throw new RuntimeException('Xendit external_id must not exceed 255 characters.');
        }
    }
}
