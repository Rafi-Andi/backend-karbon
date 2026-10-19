<?php

namespace App\Services;

use App\Models\Mission;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Validasi foto sampah via AI vision (server-side).
 *
 * Dua driver didukung, dipilih lewat AI_DRIVER:
 * - `gemini`  : Google Generative Language API (AI Studio) & Vertex AI Express
 *               — auth lewat query `?key=`, body `contents/parts`.
 * - `openai`  : endpoint OpenAI-compatible `/chat/completions`
 *               — auth `Authorization: Bearer`, body `messages[].content[]`
 *               Dipakai untuk Groq, OpenRouter, DeepSeek, OpenAI, dst.
 *
 * Kenapa bisa pindah penyedia: Google menolak IP datacenter/VPS dengan
 * "User location is not supported for the API use." Kebijakan itu milik
 * Google, bukan bug aplikasi — jadi jalur keluar tercepat adalah penyedia
 * yang tidak menerapkan aturan tersebut (mis. Groq).
 */
class GeminiService
{
    public const DRIVER_GEMINI = 'gemini';

    public const DRIVER_OPENAI = 'openai';

    /** Alias AI_DRIVER yang semuanya berarti mode OpenAI-compatible. */
    public const OPENAI_DRIVER_ALIASES = ['openai', 'groq', 'openrouter', 'deepseek', 'openai-compatible', 'compatible'];

    public const DEFAULT_MODEL = 'gemini-3.5-flash-lite';

    public const DEFAULT_BASE_URL = 'https://generativelanguage.googleapis.com';

    public const DEFAULT_ENDPOINT_PATH = '/v1beta/models/{model}:generateContent';

    public const DEFAULT_OPENAI_MODEL = 'qwen/qwen3.8-27b';

    public const DEFAULT_OPENAI_BASE_URL = 'https://api.groq.com/openai/v1';

    public const DEFAULT_OPENAI_ENDPOINT_PATH = '/chat/completions';

    public const DEFAULT_MIN_CONFIDENCE = 85;

    public const TIMEOUT_SECONDS = 40;

    public const CONNECT_TIMEOUT_SECONDS = 10;

    /**
     * Batas token jawaban untuk driver OpenAI-compatible. Model dengan mode
     * berpikir (mis. Qwen) memakai sebagian jatah untuk penalaran internal,
     * jadi beri ruang agar JSON-nya tidak terpotong.
     */
    public const MAX_COMPLETION_TOKENS = 800;

    protected string $driver;

    protected string $apiKey;

    protected string $model;

    protected int $minConfidence;

    protected bool $mock;

    protected string $baseUrl;

    protected string $endpointPath;

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
        ?string $endpointPath = null,
        ?string $driver = null,
    ) {
        $this->driver = $this->resolveDriver($driver ?? config('services.gemini.driver'));

        $openAi = $this->isOpenAi();

        $this->apiKey = $apiKey ?? (string) config('services.gemini.key', '');
        $this->model = $this->nullableString($model ?? config('services.gemini.model'))
            ?? ($openAi ? self::DEFAULT_OPENAI_MODEL : self::DEFAULT_MODEL);
        $this->minConfidence = $minConfidence ?? (int) config('services.gemini.min_confidence', self::DEFAULT_MIN_CONFIDENCE);
        $this->mock = $mock ?? (bool) config('services.gemini.mock', false);

        $defaultBaseUrl = $openAi ? self::DEFAULT_OPENAI_BASE_URL : self::DEFAULT_BASE_URL;
        $this->baseUrl = rtrim($this->nullableString($baseUrl ?? config('services.gemini.base_url')) ?? $defaultBaseUrl, '/')
            ?: $defaultBaseUrl;

        $defaultPath = $openAi ? self::DEFAULT_OPENAI_ENDPOINT_PATH : self::DEFAULT_ENDPOINT_PATH;
        $this->endpointPath = $this->nullableString($endpointPath ?? config('services.gemini.endpoint_path')) ?? $defaultPath;

        $this->proxy = $this->nullableString($proxy ?? config('services.gemini.proxy'));
        $this->relayKey = $this->nullableString($relayKey ?? config('services.gemini.relay_key'));
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function resolveDriver(mixed $driver): string
    {
        $driver = mb_strtolower(trim((string) $driver));

        return in_array($driver, self::OPENAI_DRIVER_ALIASES, true)
            ? self::DRIVER_OPENAI
            : self::DRIVER_GEMINI;
    }

    public static function fromConfig(): self
    {
        return new self;
    }

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getModel(): string
    {
        return $this->model;
    }

    protected function isOpenAi(): bool
    {
        return $this->driver === self::DRIVER_OPENAI;
    }

    /** Label penyedia untuk pesan error & log. */
    protected function providerLabel(): string
    {
        return $this->isOpenAi() ? 'AI provider' : 'Gemini';
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
     * Validate a waste-sorting photo via the configured AI vision provider.
     *
     * When a mission is given, the prompt is built from that mission's own
     * validation criteria (missions.validation_prompt), so each mission has
     * different acceptance rules. Without a mission, a generic fallback
     * prompt is used.
     *
     * @return array{is_valid: bool, confidence: float, waste_category: string, reason: string, provider: string, model: string, raw: array}
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

        $request = $this->httpClient();

        try {
            $response = $this->isOpenAi()
                ? $this->sendOpenAiRequest($request, $base64, $mime, $mission)
                : $this->sendGeminiRequest($request, $base64, $mime, $mission);
        } catch (\Throwable $e) {
            throw new RuntimeException("{$this->providerLabel()} API timeout or unreachable: ".$e->getMessage(), 0, $e);
        }

        if ($response->failed()) {
            $detail = $this->errorDetail($response);
            $status = $response->status();

            Log::error("{$this->providerLabel()} API request failed", [
                'driver' => $this->driver,
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
                    'Gemini menolak lokasi IP server. Arahkan ke penyedia lain '
                    .'(AI_DRIVER=openai + AI_BASE_URL=https://api.groq.com/openai/v1), '
                    .'atau set AI_PROXY ke proxy residensial, atau AI_BASE_URL ke relay.'
                );
            }

            if ($status === 429) {
                Log::warning(
                    'Penyedia AI menolak karena batas kuota (HTTP 429). '
                    .'Kurangi frekuensi verifikasi atau naikkan tier kuota penyedia.'
                );
            }

            throw new RuntimeException("{$this->providerLabel()} API request failed (HTTP {$status}): {$detail}");
        }

        $parsed = $this->parseJsonText($this->extractText($response));

        return [
            'is_valid' => $this->toBool($parsed['is_valid'] ?? false),
            'confidence' => $this->normalizeConfidence($parsed['confidence'] ?? 0),
            'waste_category' => $this->normalizeCategory($parsed['waste_category'] ?? null),
            'reason' => trim((string) ($parsed['reason'] ?? '')),
            'provider' => $this->driver,
            'model' => $this->model,
            'raw' => $response->json(),
        ];
    }

    protected function httpClient(): PendingRequest
    {
        $request = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->timeout(self::TIMEOUT_SECONDS);

        if ($this->proxy !== null) {
            $request = $request->withOptions(['proxy' => $this->proxy]);
        }

        if ($this->relayKey !== null) {
            // Relay pribadi (host yang tidak diblokir Google) meneruskan
            // request ke penyedia AI. Key penyedia tetap tidak dikirim ke relay.
            $request = $request->withHeaders(['X-Relay-Key' => $this->relayKey]);
        }

        return $request;
    }

    /**
     * Google Generative Language API / Vertex AI Express.
     */
    protected function sendGeminiRequest(PendingRequest $request, string $base64, string $mime, ?Mission $mission): Response
    {
        $url = $this->baseUrl.str_replace('{model}', $this->model, $this->endpointPath);

        return $request->post($url.'?key='.$this->apiKey, [
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
    }

    /**
     * Endpoint OpenAI-compatible: Groq, OpenRouter, DeepSeek, OpenAI, dst.
     */
    protected function sendOpenAiRequest(PendingRequest $request, string $base64, string $mime, ?Mission $mission): Response
    {
        $url = $this->baseUrl.str_replace('{model}', $this->model, $this->endpointPath);

        return $request
            ->withToken($this->apiKey)
            ->acceptJson()
            ->post($url, [
                'model' => $this->model,
                'messages' => [
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $this->buildPrompt($mission)],
                            [
                                'type' => 'image_url',
                                'image_url' => ['url' => "data:{$mime};base64,{$base64}"],
                            ],
                        ],
                    ],
                ],
                'response_format' => ['type' => 'json_object'],
                'temperature' => 0,
                'max_completion_tokens' => self::MAX_COMPLETION_TOKENS,
            ]);
    }

    /**
     * Ambil teks jawaban model dari bentuk respons masing-masing penyedia.
     */
    protected function extractText(Response $response): string
    {
        if (! $this->isOpenAi()) {
            return (string) data_get($response->json(), 'candidates.0.content.parts.0.text', '');
        }

        $content = data_get($response->json(), 'choices.0.message.content', '');

        // Sebagian penyedia mengembalikan content sebagai array part.
        if (is_array($content)) {
            $content = (string) data_get($content, '0.text', '');
        }

        return (string) $content;
    }

    protected function mockResponse(): array
    {
        return [
            'is_valid' => true,
            'confidence' => 92.0,
            'waste_category' => 'plastic',
            'reason' => 'Mock mode: validation skipped.',
            'provider' => 'mock',
            'model' => $this->model,
            'raw' => ['mock' => true, 'model' => $this->model],
        ];
    }

    /**
     * Ambil alasan asli dari body error penyedia (bukan sekadar "HTTP 400").
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
            .'reason (1-2 sentences in Bahasa Indonesia: describe what is actually visible in the photo; '
            .'when is_valid is false, name the specific problem and what the user should fix '
            .'so the next photo is accepted, e.g. closer framing, better lighting, waste clearly in the bin).';
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

        // Sebagian model menambahkan kalimat pembuka/penutup di luar JSON.
        if (! is_array($decoded)) {
            $start = strpos($cleaned, '{');
            $end = strrpos($cleaned, '}');

            if ($start !== false && $end !== false && $end > $start) {
                $decoded = json_decode(substr($cleaned, $start, $end - $start + 1), true);
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException("{$this->providerLabel()} API returned invalid JSON.");
        }

        return $decoded;
    }

    /**
     * Sebagian model (mis. Qwen lewat Groq) menjawab confidence sebagai
     * rasio 0-1, bukan skala 0-100. Nilai di rentang itu dikonversi dulu
     * supaya ambang minimum (default 85) tetap bermakna.
     */
    protected function normalizeConfidence(mixed $value): float
    {
        $confidence = (float) $value;

        if ($confidence > 0 && $confidence <= 1) {
            $confidence *= 100;
        }

        return $this->clampConfidence($confidence);
    }

    protected function clampConfidence(mixed $value): float
    {
        return max(0.0, min(100.0, (float) $value));
    }

    protected function toBool(mixed $value): bool
    {
        if (is_string($value)) {
            return in_array(mb_strtolower(trim($value)), ['true', '1', 'yes', 'ya', 'valid'], true);
        }

        return (bool) $value;
    }

    protected function normalizeCategory(mixed $value): string
    {
        $category = mb_strtolower(trim((string) $value));

        return $category === '' ? 'unknown' : $category;
    }
}
