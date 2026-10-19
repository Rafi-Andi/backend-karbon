<?php

namespace App\Services;

use App\Models\Mission;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GeminiService
{
    public const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com';

    public const DEFAULT_MIN_CONFIDENCE = 85;

    public const TIMEOUT_SECONDS = 20;

    public const CONNECT_TIMEOUT_SECONDS = 10;

    protected string $apiKey;

    protected string $model;

    protected int $minConfidence;

    protected bool $mock;

    protected string $baseUrl;

    protected ?string $proxy;

    protected ?string $relayKey;

    public function __construct(
        ?string $apiKey = null,
        ?string $model = null,
        ?int $minConfidence = null,
        ?bool $mock = null,
        ?string $baseUrl = null,
        ?string $proxy = null,
        ?string $relayKey = null,
    ) {
        $this->apiKey = $apiKey ?? (string) config('services.gemini.key', '');
        $this->model = $model ?? (string) config('services.gemini.model', self::DEFAULT_MODEL);
        $this->minConfidence = $minConfidence ?? (int) config('services.gemini.min_confidence', self::DEFAULT_MIN_CONFIDENCE);
        $this->mock = $mock ?? (bool) config('services.gemini.mock', false);
        $this->baseUrl = rtrim((string) ($baseUrl ?? config('services.gemini.base_url', self::DEFAULT_BASE_URL)), '/') ?: self::DEFAULT_BASE_URL;
        $this->proxy = $this->nullableString($proxy ?? config('services.gemini.proxy'));
        $this->relayKey = $this->nullableString($relayKey ?? config('services.gemini.relay_key'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public static function fromConfig(): self
    {
        return new self;
    }

    public function getMinConfidence(): int
    {
        return $this->minConfidence;
    }

    public function isMock(): bool
    {
        return $this->mock || $this->apiKey === '';
    }

    public function passesThreshold(array $result): bool
    {
        return ($result['confidence'] ?? 0) >= $this->minConfidence;
    }

    /**
     * Validate a waste-sorting photo via Gemini Vision.
     *
     * When a mission is given, the prompt is built from that mission's own
     * validation criteria (missions.validation_prompt), so each mission has
     * different acceptance rules. Without a mission, a generic fallback
     * prompt is used.
     *
     * @return array{is_valid: bool, confidence: float, waste_category: string, reason: string, raw: array}
     *
     * @throws RuntimeException When the image is unreadable or the API call/parsing fails.
     */
    public function verifyWaste(string $imagePath, string $mime, ?Mission $mission = null): array
    {
        if (! is_file($imagePath) || ! is_readable($imagePath)) {
            throw new RuntimeException('Image file not found or unreadable.');
        }

        if ($this->isMock()) {
            return $this->mockResponse();
        }

        $base64 = base64_encode((string) file_get_contents($imagePath));

        $url = "{$this->baseUrl}/v1beta/models/{$this->model}:generateContent";

        $request = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS);

        if ($this->proxy !== null) {
            $request = $request->withOptions(['proxy' => $this->proxy]);
        }

        if ($this->relayKey !== null) {
            // Relay pribadi (host yang tidak diblokir Google) meneruskan
            // request ke Gemini. Key Gemini tetap tidak dikirim ke relay.
            $request = $request->withHeaders(['X-Relay-Key' => $this->relayKey]);
        }

        try {
            $response = $request->post($url.'?key='.$this->apiKey, [
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $this->buildPrompt($mission)],
                            ['inline_data' => ['mime_type' => $mime, 'data' => $base64]],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.1,
                ],
            ]);
        } catch (\Throwable $e) {
            throw new RuntimeException('Gemini API timeout or unreachable: '.$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $detail = $this->errorDetail($response);
            $status = $response->status();

            Log::error('Gemini API request failed', [
                'status' => $status,
                'model' => $this->model,
                'base_url' => $this->baseUrl,
                'via_proxy' => $this->proxy !== null,
                'via_relay' => $this->relayKey !== null,
                'detail' => $detail,
            ]);

            if ($this->isLocationBlocked($detail)) {
                // IP server (VPS/cloud) diblokir Google. Bukan salah key/model.
                Log::warning(
                    'Gemini menolak lokasi IP server. Arahkan GEMINI_PROXY ke proxy residensial, '
                    .'atau GEMINI_BASE_URL ke relay di host yang diizinkan Google.'
                );
            }

            throw new RuntimeException("Gemini API request failed (HTTP {$status}): {$detail}");
        }

        $text = (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');

        $parsed = $this->parseJsonText($text);

        return [
            'is_valid' => (bool) ($parsed['is_valid'] ?? false),
            'confidence' => $this->clampConfidence($parsed['confidence'] ?? 0),
            'waste_category' => (string) ($parsed['waste_category'] ?? 'unknown'),
            'reason' => (string) ($parsed['reason'] ?? ''),
            'raw' => $response->json(),
        ];
    }

    protected function mockResponse(): array
    {
        return [
            'is_valid' => true,
            'confidence' => 92.0,
            'waste_category' => 'plastic',
            'reason' => 'Mock mode: validation skipped.',
            'raw' => ['mock' => true, 'model' => $this->model],
        ];
    }

    /**
     * Ambil alasan asli dari body error Google (bukan sekadar "HTTP 400").
     */
    protected function errorDetail(Response $response): string
    {
        $message = (string) data_get($response->json(), 'error.message', '');

        if ($message === '') {
            $body = trim((string) $response->body());
            $message = $body !== '' ? mb_substr($body, 0, 300) : 'no error body';
        }

        return $message;
    }

    /**
     * Google memblokir IP datacenter/VPS tertentu dengan kode ini.
     */
    protected function isLocationBlocked(string $detail): bool
    {
        return str_contains(mb_strtolower($detail), 'user location is not supported');
    }

    protected function buildPrompt(?Mission $mission = null): string
    {
        $base = 'You are a waste-sorting validator for KarbonKita, an Indonesian eco app. '
            .'Analyze the attached photo and decide whether it shows correctly sorted recyclable waste '
            .'(plastic, paper, organic, electronic, or residue separated into proper containers/bags). ';

        if ($mission !== null) {
            $base .= "Mission being validated: '{$mission->title}'. "
                ."Mission description: '{$mission->description}'. ";

            if (! empty($mission->validation_prompt)) {
                $base .= "Mission-specific acceptance criteria: {$mission->validation_prompt} ";
            }

            // Ketat: foto benar tapi untuk misi yang salah tetap tidak valid.
            $base .= 'STRICT RULE: judge ONLY against THIS mission. '
                .'If the photo shows valid waste sorting that does NOT match this mission '
                .'(for example a different waste category), you MUST set is_valid to false '
                .'with high confidence. ';
        }

        return $base
            .'Respond with ONLY a JSON object, no markdown, with keys: '
            .'is_valid (boolean), confidence (number 0-100), '
            .'waste_category (one of: plastic, paper, organic, electronic, residue, mixed, unknown), '
            .'reason (penjelasan singkat dalam Bahasa Indonesia).';
    }

    /**
     * @throws RuntimeException
     */
    protected function parseJsonText(string $text): array
    {
        $cleaned = trim($text);
        // Strip ```json ... ``` fences some models add despite responseMimeType.
        if (str_starts_with($cleaned, '```')) {
            $cleaned = (string) preg_replace('/^```[a-zA-Z]*\s*|\s*```$/', '', $cleaned);
            $cleaned = trim($cleaned);
        }

        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Gemini API returned invalid JSON.');
        }

        return $decoded;
    }

    protected function clampConfidence(mixed $value): float
    {
        return max(0.0, min(100.0, (float) $value));
    }
}
