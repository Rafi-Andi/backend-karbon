<?php

namespace Tests\Unit;

use App\Models\Mission;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class GeminiServiceTest extends TestCase
{
    private string $imagePath;

    private string $imageBase64;

    protected function setUp(): void
    {
        parent::setUp();

        $this->imagePath = (string) tempnam(sys_get_temp_dir(), 'waste_');
        file_put_contents($this->imagePath, 'fake-image-bytes');
        $this->imageBase64 = base64_encode('fake-image-bytes');
    }

    protected function tearDown(): void
    {
        if (is_file($this->imagePath)) {
            unlink($this->imagePath);
        }

        parent::tearDown();
    }

    private function geminiResponse(string $innerText, int $status = 200): array
    {
        return [
            'candidates' => [
                [
                    'content' => [
                        'parts' => [['text' => $innerText]],
                    ],
                ],
            ],
        ];
    }

    public function test_mock_mode_returns_valid_without_http(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $service = new GeminiService(apiKey: 'dummy-key', mock: true);

        $result = $service->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals(92.0, $result['confidence']);
        $this->assertEquals('plastic', $result['waste_category']);
        $this->assertNotEmpty($result['reason']);
        $this->assertTrue($result['raw']['mock'] ?? false);
        Http::assertNothingSent();
    }

    public function test_empty_api_key_falls_back_to_mock(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $service = new GeminiService(apiKey: '', mock: false);

        $this->assertTrue($service->isMock());

        $result = $service->verifyWaste($this->imagePath, 'image/png');

        $this->assertTrue($result['is_valid']);
        Http::assertNothingSent();
    }

    public function test_from_config_respects_config_values(): void
    {
        config()->set('services.gemini', [
            'key' => 'cfg-key',
            'model' => 'gemini-test-model',
            'min_confidence' => 70,
            'mock' => false,
        ]);

        $service = GeminiService::fromConfig();

        $this->assertFalse($service->isMock());
        $this->assertEquals(70, $service->getMinConfidence());
        $this->assertTrue($service->passesThreshold(['confidence' => 70]));
        $this->assertFalse($service->passesThreshold(['confidence' => 69.9]));
    }

    public function test_real_mode_success_parses_valid_result(): void
    {
        Http::fake([
            '*' => Http::response($this->geminiResponse(
                '{"is_valid":true,"confidence":95,"waste_category":"paper","reason":"Sorted paper visible"}'
            ), 200),
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);

        $result = $service->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals(95.0, $result['confidence']);
        $this->assertEquals('paper', $result['waste_category']);
        $this->assertEquals('Sorted paper visible', $result['reason']);
        $this->assertArrayHasKey('candidates', $result['raw']);
    }

    public function test_real_mode_low_confidence_invalid_result(): void
    {
        Http::fake([
            '*' => Http::response($this->geminiResponse(
                '{"is_valid":false,"confidence":40,"waste_category":"unknown","reason":"Blurry, cannot tell"}'
            ), 200),
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false, minConfidence: 85);

        $result = $service->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals(40.0, $result['confidence']);
        $this->assertFalse($service->passesThreshold($result));
    }

    public function test_real_mode_strips_markdown_fences(): void
    {
        Http::fake([
            '*' => Http::response($this->geminiResponse(
                "```json\n".'{"is_valid":true,"confidence":88,"waste_category":"organic","reason":"Compost bin"}'."\n```"
            ), 200),
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);

        $result = $service->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals(88.0, $result['confidence']);
        $this->assertEquals('organic', $result['waste_category']);
    }

    public function test_real_mode_throws_on_http_error(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'boom']], 500)]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->verifyWaste($this->imagePath, 'image/jpeg');
    }

    public function test_http_error_message_includes_google_reason(): void
    {
        Http::fake([
            '*' => Http::response([
                'error' => [
                    'code' => 400,
                    'message' => 'User location is not supported for the API use.',
                    'status' => 'FAILED_PRECONDITION',
                ],
            ], 400),
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);

        try {
            $service->verifyWaste($this->imagePath, 'image/jpeg');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 400', $e->getMessage());
            $this->assertStringContainsString('User location is not supported', $e->getMessage());
        }
    }

    public function test_location_block_is_logged_as_warning(): void
    {
        Log::spy();

        Http::fake([
            '*' => Http::response([
                'error' => ['message' => 'User location is not supported for the API use.'],
            ], 400),
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);

        try {
            $service->verifyWaste($this->imagePath, 'image/jpeg');
        } catch (RuntimeException) {
            // expected
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'Gemini menolak lokasi IP server'))
            ->once();
    }

    public function test_real_mode_throws_on_invalid_json_text(): void
    {
        Http::fake([
            '*' => Http::response($this->geminiResponse('not json at all'), 200),
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('invalid JSON');

        $service->verifyWaste($this->imagePath, 'image/jpeg');
    }

    public function test_missing_file_throws_without_http(): void
    {
        Http::fake();
        Http::preventStrayRequests();

        $service = new GeminiService(apiKey: 'dummy-key', mock: true);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not found or unreadable/');

        $service->verifyWaste(sys_get_temp_dir().'/karbonkita-missing-'.uniqid().'.jpg', 'image/jpeg');
    }

    public function test_confidence_is_clamped_at_100(): void
    {
        Http::fake([
            '*' => Http::response($this->geminiResponse(
                '{"is_valid":true,"confidence":150,"waste_category":"plastic","reason":"x"}'
            ), 200),
        ]);
        $service = new GeminiService(apiKey: 'real-key', mock: false);
        $this->assertEquals(100.0, $service->verifyWaste($this->imagePath, 'image/jpeg')['confidence']);
    }

    public function test_confidence_is_clamped_at_0(): void
    {
        Http::fake([
            '*' => Http::response($this->geminiResponse(
                '{"is_valid":false,"confidence":-20,"waste_category":"unknown","reason":"x"}'
            ), 200),
        ]);
        $service = new GeminiService(apiKey: 'real-key', mock: false);
        $this->assertEquals(0.0, $service->verifyWaste($this->imagePath, 'image/jpeg')['confidence']);
    }

    public function test_custom_base_url_is_used_for_relay(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $service = new GeminiService(
            apiKey: 'real-key',
            model: 'gemini-3.5-flash-lite',
            mock: false,
            baseUrl: 'https://relay.contoh.id/',
            relayKey: 'rahasia',
        );

        $service->verifyWaste($this->imagePath, 'image/jpeg');

        Http::assertSent(function ($request) {
            return str_starts_with($request->url(), 'https://relay.contoh.id/v1beta/models/gemini-3.5-flash-lite:generateContent')
                && $request->hasHeader('X-Relay-Key', 'rahasia');
        });
    }

    public function test_vertex_express_endpoint_path_is_used_when_configured(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $service = new GeminiService(
            apiKey: 'vertex-key',
            model: 'gemini-3.5-flash-lite',
            mock: false,
            baseUrl: 'https://aiplatform.googleapis.com',
            endpointPath: '/v1/publishers/google/models/{model}:generateContent',
        );

        $service->verifyWaste($this->imagePath, 'image/jpeg');

        Http::assertSent(function ($request) {
            return str_starts_with(
                $request->url(),
                'https://aiplatform.googleapis.com/v1/publishers/google/models/gemini-3.5-flash-lite:generateContent?key=vertex-key'
            );
        });
    }

    public function test_proxy_option_is_passed_to_http_client(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $service = new GeminiService(
            apiKey: 'real-key',
            model: 'gemini-3.5-flash-lite',
            mock: false,
            proxy: 'http://user:pass@proxy.contoh.id:8080',
        );

        $service->verifyWaste($this->imagePath, 'image/jpeg');

        Http::assertSentCount(1);
    }

    public function test_request_sends_base64_image_and_mime(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"electronic","reason":"E-waste box"}'
        ), 200)]);

        $service = new GeminiService(apiKey: 'real-key', model: 'gemini-3.5-flash-lite', mock: false);
        $service->verifyWaste($this->imagePath, 'image/png');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gemini-3.5-flash-lite:generateContent')) {
                return false;
            }
            $payload = $request->data();
            $parts = $payload['contents'][0]['parts'] ?? [];

            return ($parts[1]['inline_data']['mime_type'] ?? null) === 'image/png'
                && ($parts[1]['inline_data']['data'] ?? null) === $this->imageBase64;
        });
    }

    public function test_threshold_boundary_uses_min_confidence(): void
    {
        $service = new GeminiService(apiKey: 'k', mock: true, minConfidence: 85);

        $this->assertTrue($service->passesThreshold(['confidence' => 85]));
        $this->assertTrue($service->passesThreshold(['confidence' => 85.0]));
        $this->assertFalse($service->passesThreshold(['confidence' => 84.99]));
        $this->assertFalse($service->passesThreshold([]));
    }

    private function sentPromptText(): string
    {
        $text = '';
        Http::assertSent(function ($request) use (&$text) {
            $parts = $request->data()['contents'][0]['parts'] ?? [];
            if (! isset($parts[0]['text'])) {
                return false;
            }
            $text = $parts[0]['text'];

            return true;
        });

        return $text;
    }

    public function test_prompt_includes_mission_context_and_strict_rule(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"electronic","reason":"E-waste terpilah"}'
        ), 200)]);

        $mission = new Mission([
            'title' => 'Pilah Sampah Elektronik',
            'description' => 'Pilah e-waste dengan benar',
            'validation_prompt' => 'KRITERIA-KHUSUS-EWASTE-XYZ',
            'category' => 'waste',
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);
        $result = $service->verifyWaste($this->imagePath, 'image/jpeg', $mission);

        $this->assertTrue($result['is_valid']);

        $text = $this->sentPromptText();
        $this->assertStringContainsString('Pilah Sampah Elektronik', $text);
        $this->assertStringContainsString('Pilah e-waste dengan benar', $text);
        $this->assertStringContainsString('KRITERIA-KHUSUS-EWASTE-XYZ', $text);
        $this->assertStringContainsString('STRICT RULE', $text);
        $this->assertStringContainsString('Bahasa Indonesia', $text);
    }

    public function test_prompt_differs_per_mission(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $plastic = new Mission([
            'title' => 'Misi Plastik',
            'description' => 'plastik',
            'validation_prompt' => 'KRITERIA-PLASTIK-AAA',
            'category' => 'waste',
        ]);
        $electronic = new Mission([
            'title' => 'Misi Elektronik',
            'description' => 'elektronik',
            'validation_prompt' => 'KRITERIA-ELEKTRONIK-BBB',
            'category' => 'waste',
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);
        $service->verifyWaste($this->imagePath, 'image/jpeg', $plastic);

        Http::assertSent(function ($request) {
            $text = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($text, 'KRITERIA-PLASTIK-AAA')
                && ! str_contains($text, 'KRITERIA-ELEKTRONIK-BBB');
        });

        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);
        $service->verifyWaste($this->imagePath, 'image/jpeg', $electronic);

        Http::assertSent(function ($request) {
            $text = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($text, 'KRITERIA-ELEKTRONIK-BBB')
                && ! str_contains($text, 'KRITERIA-PLASTIK-AAA');
        });
    }

    public function test_prompt_without_criteria_still_names_mission_and_strict(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $mission = new Mission([
            'title' => 'Misi Tanpa Kriteria',
            'description' => 'deskripsi misi',
            'validation_prompt' => null,
            'category' => 'waste',
        ]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);
        $service->verifyWaste($this->imagePath, 'image/jpeg', $mission);

        $text = $this->sentPromptText();
        $this->assertStringContainsString('Misi Tanpa Kriteria', $text);
        $this->assertStringContainsString('STRICT RULE', $text);
        $this->assertStringNotContainsString('acceptance criteria', strtolower($text));
    }

    public function test_prompt_falls_back_to_generic_without_mission(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);
        $service->verifyWaste($this->imagePath, 'image/jpeg');

        $text = $this->sentPromptText();
        $this->assertStringNotContainsString('Mission being validated', $text);
        $this->assertStringNotContainsString('STRICT RULE', $text);
        $this->assertStringContainsString('Bahasa Indonesia', $text);
    }

    // ---------- Driver OpenAI-compatible (Groq, OpenRouter, dst) ----------

    private function openAiResponse(string $innerText): array
    {
        return [
            'id' => 'chatcmpl-test',
            'object' => 'chat.completion',
            'model' => 'qwen/qwen3.8-27b',
            'choices' => [
                [
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => $innerText],
                    'finish_reason' => 'stop',
                ],
            ],
        ];
    }

    private function openAiService(string $apiKey = 'gsk-test'): GeminiService
    {
        return new GeminiService(
            apiKey: $apiKey,
            model: 'qwen/qwen3.8-27b',
            mock: false,
            driver: GeminiService::DRIVER_OPENAI,
        );
    }

    public function test_openai_driver_posts_chat_completions_with_bearer_and_data_url(): void
    {
        Http::fake(['*' => Http::response($this->openAiResponse(
            '{"is_valid":true,"confidence":0.98,"waste_category":"plastic","reason":"Botol plastik terlihat jelas di kantong."}'
        ), 200)]);

        $service = $this->openAiService();
        $result = $service->verifyWaste($this->imagePath, 'image/png');

        $this->assertSame('openai', $service->getDriver());
        $this->assertTrue($result['is_valid']);
        // Rasio 0-1 milik Qwen dinormalkan ke 0-100.
        $this->assertEquals(98.0, $result['confidence']);
        $this->assertTrue($service->passesThreshold($result));
        $this->assertSame('plastic', $result['waste_category']);
        $this->assertSame('openai', $result['provider']);
        $this->assertSame('qwen/qwen3.8-27b', $result['model']);

        Http::assertSent(function ($request) {
            $payload = $request->data();
            $content = $payload['messages'][0]['content'] ?? [];

            return $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer gsk-test')
                && ($payload['model'] ?? null) === 'qwen/qwen3.8-27b'
                && ($payload['response_format']['type'] ?? null) === 'json_object'
                && ($content[0]['type'] ?? null) === 'text'
                && ($content[1]['type'] ?? null) === 'image_url'
                && ($content[1]['image_url']['url'] ?? null) === 'data:image/png;base64,'.$this->imageBase64
                && str_contains((string) ($content[0]['text'] ?? ''), 'Bahasa Indonesia');
        });
    }

    public function test_openai_driver_alias_and_config_defaults_are_used(): void
    {
        Http::fake(['*' => Http::response($this->openAiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"paper","reason":"ok"}'
        ), 200)]);

        config()->set('services.gemini.driver', 'groq');
        config()->set('services.gemini.key', 'gsk-config');
        // AI_MODEL/GEMINI_MODEL kosong → default mengikuti driver (Qwen via Groq).
        config()->set('services.gemini.model', null);

        $service = new GeminiService(mock: false);

        $this->assertSame('openai', $service->getDriver());
        $this->assertSame('qwen/qwen3.8-27b', $service->getModel());

        $service->verifyWaste($this->imagePath, 'image/jpeg');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer gsk-config'));
    }

    public function test_gemini_driver_stays_default_without_config(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $service = new GeminiService(apiKey: 'k', mock: false);

        $this->assertSame('gemini', $service->getDriver());

        $service->verifyWaste($this->imagePath, 'image/jpeg');

        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://generativelanguage.googleapis.com/v1beta/models/'));
    }

    public function test_openai_driver_custom_endpoint_for_openrouter(): void
    {
        Http::fake(['*' => Http::response($this->openAiResponse(
            '{"is_valid":true,"confidence":88,"waste_category":"plastic","reason":"ok"}'
        ), 200)]);

        $service = new GeminiService(
            apiKey: 'sk-or-test',
            model: 'google/gemini-2.5-flash',
            mock: false,
            baseUrl: 'https://openrouter.ai/api/v1/',
            driver: GeminiService::DRIVER_OPENAI,
        );

        $service->verifyWaste($this->imagePath, 'image/jpeg');

        Http::assertSent(fn ($request) => $request->url() === 'https://openrouter.ai/api/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer sk-or-test'));
    }

    public function test_openai_driver_extracts_json_wrapped_in_prose_and_fences(): void
    {
        Http::fake(['*' => Http::response($this->openAiResponse(
            "Berikut hasil analisisnya:\n```json\n".
            '{"is_valid":true,"confidence":91,"waste_category":"organic","reason":"Kompos terlihat."}'.
            "\n```\nSemoga membantu."
        ), 200)]);

        $service = $this->openAiService();
        $result = $service->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals(91.0, $result['confidence']);
        $this->assertSame('organic', $result['waste_category']);
        $this->assertSame('Kompos terlihat.', $result['reason']);
    }

    public function test_openai_driver_accepts_content_array_parts(): void
    {
        Http::fake(['*' => Http::response([
            'choices' => [
                ['message' => ['role' => 'assistant', 'content' => [
                    ['type' => 'text', 'text' => '{"is_valid":true,"confidence":77,"waste_category":"paper","reason":"ok"}'],
                ]]],
            ],
        ], 200)]);

        $result = $this->openAiService()->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals(77.0, $result['confidence']);
    }

    public function test_string_boolean_false_is_treated_as_invalid(): void
    {
        Http::fake(['*' => Http::response($this->openAiResponse(
            '{"is_valid":"false","confidence":95,"waste_category":"plastic","reason":"Bukan sampah plastik, ini kardus."}'
        ), 200)]);

        $result = $this->openAiService()->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertFalse($result['is_valid']);
        $this->assertSame('Bukan sampah plastik, ini kardus.', $result['reason']);
    }

    public function test_openai_driver_reports_rate_limit_and_logs_quota_warning(): void
    {
        Log::spy();

        Http::fake(['*' => Http::response([
            'error' => ['message' => 'Rate limit reached for model `qwen/qwen3.8-27b` on tokens per day (TPD).'],
        ], 429)]);

        $service = $this->openAiService();

        try {
            $service->verifyWaste($this->imagePath, 'image/jpeg');
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('HTTP 429', $e->getMessage());
            $this->assertStringContainsString('Rate limit reached', $e->getMessage());
        }

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message) => str_contains($message, 'batas kuota'))
            ->once();
    }

    public function test_openai_driver_rejects_unknown_category_fallback(): void
    {
        Http::fake(['*' => Http::response($this->openAiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"  ","reason":"ok"}'
        ), 200)]);

        $result = $this->openAiService()->verifyWaste($this->imagePath, 'image/jpeg');

        $this->assertSame('unknown', $result['waste_category']);
    }
}
