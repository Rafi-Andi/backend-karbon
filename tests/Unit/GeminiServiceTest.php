<?php

namespace Tests\Unit;

use App\Models\Mission;
use App\Services\GeminiService;
use Illuminate\Support\Facades\Http;
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
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $service = new GeminiService(apiKey: 'real-key', mock: false);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/HTTP 500/');

        $service->verifyWaste($this->imagePath, 'image/jpeg');
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

    public function test_request_sends_base64_image_and_mime(): void
    {
        Http::fake(['*' => Http::response($this->geminiResponse(
            '{"is_valid":true,"confidence":90,"waste_category":"electronic","reason":"E-waste box"}'
        ), 200)]);

        $service = new GeminiService(apiKey: 'real-key', model: 'gemini-1.5-flash', mock: false);
        $service->verifyWaste($this->imagePath, 'image/png');

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'gemini-1.5-flash:generateContent')) {
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
}
