<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\PointTransaction;
use App\Models\User;
use App\Models\UserMission;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class VerifyWasteTest extends TestCase
{
    use RefreshDatabase;

    // 1x1 transparent PNG — fixed bytes => fixed sha256 (for duplicate tests).
    private const FIXED_PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('public');
        config()->set('services.gemini', [
            'key' => null,
            'model' => 'gemini-3.5-flash-lite',
            'min_confidence' => 85,
            'mock' => true,
        ]);
    }

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeWarga(array $profile = []): User
    {
        $user = User::factory()->create(['role' => 'warga']);
        WargaProfile::create(array_merge([
            'user_id' => $user->id,
            'level' => 'Earth Newbie',
            'xp' => 0,
            'eco_points' => 0,
            'streak_days' => 0,
        ], $profile));

        return $user;
    }

    private function makeMission(array $overrides = []): Mission
    {
        return Mission::create(array_merge([
            'title' => 'Pahlawan Plastik Terpilah',
            'description' => 'Ambil foto hasil pilah sampahmu!',
            'category' => 'waste',
            'xp_reward' => 300,
            'points_reward' => 100,
            'icon' => 'recycling',
            'max_participants' => null,
            'is_active' => true,
        ], $overrides));
    }

    private function fixedPng(string $name = 'waste.png'): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'waste_');
        file_put_contents($tmp, (string) base64_decode(self::FIXED_PNG_BASE64));

        return new UploadedFile($tmp, $name, 'image/png', null, true);
    }

    private function geminiPayload(string $innerText): array
    {
        return [
            'candidates' => [
                ['content' => ['parts' => [['text' => $innerText]]]],
            ],
        ];
    }

    private function useRealGeminiFake(string $innerText, int $status = 200): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.mock', false);
        Http::fake(['*' => Http::response($this->geminiPayload($innerText), $status)]);
    }

    public function test_unauthenticated_returns_401(): void
    {
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ])->assertStatus(401);
    }

    public function test_unauthenticated_without_accept_header_returns_json_401(): void
    {
        $mission = $this->makeMission();

        // Klien seperti curl/browser tidak mengirim header Accept. Sebelum
        // shouldRenderJsonWhen dipasang, ini 500 karena Laravel mencoba
        // redirect ke route `login` yang tidak ada di aplikasi API-only.
        $response = $this->post('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ]);

        $response->assertStatus(401);
        $this->assertSame('Unauthenticated.', $response->json('message'));
    }

    public function test_success_verified_with_mock_ai(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user));

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success', 'message',
                'data' => [
                    'user_mission_id', 'status', 'is_valid', 'confidence',
                    'waste_category', 'xp_earned', 'points_earned',
                    'new_xp', 'new_level', 'new_eco_points', 'streak_days',
                ],
            ])
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.xp_earned', 300)
            ->assertJsonPath('data.points_earned', 100);

        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertNotNull($record);
        $this->assertEquals('verified', $record->status);
        $this->assertEquals(92.0, (float) $record->confidence_score);
        $this->assertFalse((bool) $record->anti_fraud_flagged);
        $this->assertNotNull($record->proof_image_url);
        $this->assertNotNull($record->proof_image_hash);
        Storage::disk('public')->assertExists($record->proof_image_url);

        $profile = WargaProfile::where('user_id', $user->id)->first();
        $this->assertEquals(300, $profile->xp);
        $this->assertEquals(100, $profile->eco_points);
        $this->assertEquals(1, $profile->streak_days);
        $this->assertEquals(1.0, (float) $profile->total_waste_kg);
        $this->assertNotNull($profile->last_mission_at);

        $tx = PointTransaction::where('reference_id', $record->id)->first();
        $this->assertNotNull($tx);
        $this->assertEquals('credit', $tx->type);
        $this->assertEquals(100, $tx->amount);
        $this->assertEquals(100, $tx->balance_after);
    }

    public function test_validation_missing_fields(): void
    {
        $user = $this->makeWarga();

        $this->postJson('/api/missions/verify-waste', [], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['mission_id', 'image']);
    }

    public function test_validation_nonexistent_mission(): void
    {
        $user = $this->makeWarga();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => 9999,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mission_id']);
    }

    public function test_validation_quiz_mission_rejected(): void
    {
        $user = $this->makeWarga();
        $quiz = $this->makeMission(['title' => 'Quiz', 'category' => 'quiz']);

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $quiz->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mission_id']);
    }

    public function test_validation_mobility_mission_rejected(): void
    {
        $user = $this->makeWarga();
        $mobility = $this->makeMission(['title' => 'Mobility', 'category' => 'mobility']);

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mobility->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mission_id']);
    }

    public function test_validation_inactive_waste_mission_rejected(): void
    {
        $user = $this->makeWarga();
        $inactive = $this->makeMission(['title' => 'Inactive', 'is_active' => false]);

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $inactive->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mission_id']);
    }

    public function test_validation_non_image_file_rejected(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->create('note.txt', 100, 'text/plain'),
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['image']);

        $this->assertEquals(0, UserMission::count());
    }

    public function test_duplicate_image_second_upload_returns_409(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $first = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => $this->fixedPng('first.png'),
        ], $this->authHeader($user));
        $first->assertStatus(201);

        $pointsAfterFirst = WargaProfile::where('user_id', $user->id)->first()->eco_points;

        $second = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => $this->fixedPng('second.png'),
        ], $this->authHeader($user));

        $second->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'rejected');

        $flagged = UserMission::find($second->json('data.user_mission_id'));
        $this->assertTrue((bool) $flagged->anti_fraud_flagged);
        $this->assertEquals('rejected', $flagged->status);
        $this->assertNotNull($flagged->rejection_reason);

        // Poin tidak bertambah dari upload duplikat.
        $this->assertEquals(
            $pointsAfterFirst,
            WargaProfile::where('user_id', $user->id)->first()->eco_points
        );
        $this->assertEquals(0, PointTransaction::where('reference_id', $flagged->id)->count());
    }

    public function test_same_mission_twice_same_day_returns_409(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            // Dimensi beda => bytes beda => hash beda
            // (fake()->image deterministik per dimensi).
            'image' => UploadedFile::fake()->image('first.png', 10, 10),
        ], $this->authHeader($user))->assertStatus(201);

        $pointsAfterFirst = WargaProfile::where('user_id', $user->id)->first()->eco_points;

        // Gambar berbeda (hash berbeda) tapi misi sama hari sama → kunci harian.
        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('second.png', 20, 20),
        ], $this->authHeader($user))
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.already_completed_today', true);

        $this->assertEquals(
            $pointsAfterFirst,
            WargaProfile::where('user_id', $user->id)->first()->eco_points
        );
    }

    public function test_rejected_does_not_lock_daily_cap(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":false,"confidence":40,"waste_category":"unknown","reason":"Blurry photo"}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('blurry.png', 10, 10),
        ], $this->authHeader($user))->assertOk()->assertJsonPath('data.status', 'rejected');

        // Rejected tidak mengunci — upload bagus setelahnya tetap 201.
        // Kembalikan ke mock sukses (mock=true memberi valid + confidence 95).
        config()->set('services.gemini.mock', true);
        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('good.png', 20, 20),
        ], $this->authHeader($user))->assertStatus(201);
    }

    public function test_duplicate_image_blocked_across_users(): void
    {
        $userA = $this->makeWarga();
        $userB = $this->makeWarga();
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => $this->fixedPng('a.png'),
        ], $this->authHeader($userA))->assertStatus(201);

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => $this->fixedPng('b.png'),
        ], $this->authHeader($userB))->assertStatus(409);

        $this->assertEquals(0, WargaProfile::where('user_id', $userB->id)->first()->eco_points);
    }

    public function test_low_confidence_rejected_without_reward(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":false,"confidence":40,"waste_category":"unknown","reason":"Blurry photo"}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('blurry.jpg'),
        ], $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.xp_earned', 0)
            ->assertJsonPath('data.points_earned', 0);

        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertEquals('rejected', $record->status);
        $this->assertEquals(40.0, (float) $record->confidence_score);
        $this->assertNotNull($record->rejection_reason);

        $profile = WargaProfile::where('user_id', $user->id)->first();
        $this->assertEquals(0, $profile->xp);
        $this->assertEquals(0, $profile->eco_points);
        $this->assertEquals(0, PointTransaction::count());
    }

    public function test_valid_but_below_threshold_rejected(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":true,"confidence":80,"waste_category":"plastic","reason":"Likely sorted"}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user));

        $response->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->assertEquals(0, WargaProfile::where('user_id', $user->id)->first()->eco_points);
        $this->assertEquals(0, PointTransaction::count());
    }

    public function test_gemini_failure_returns_503_pending(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.mock', false);
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user));

        $response->assertStatus(503)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.status', 'pending');

        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertEquals('pending', $record->status);

        $this->assertEquals(0, WargaProfile::where('user_id', $user->id)->first()->eco_points);
        $this->assertEquals(0, PointTransaction::count());
    }

    public function test_file_stored_under_user_folder(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => $this->fixedPng('stored.png'),
        ], $this->authHeader($user));

        $response->assertStatus(201);

        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertStringStartsWith("proofs/{$user->id}/", (string) $record->proof_image_url);
        $this->assertEquals(
            hash('sha256', (string) base64_decode(self::FIXED_PNG_BASE64)),
            $record->proof_image_hash
        );
        $this->assertEquals(92.0, (float) $record->ai_gemini_response['confidence']);
    }

    public function test_invalid_token_returns_401(): void
    {
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], ['Authorization' => 'Bearer invalid-token'])->assertStatus(401);
    }

    public function test_donation_mission_rejected_422(): void
    {
        $user = $this->makeWarga();
        $donation = $this->makeMission([
            'title' => 'Donasi Pohon Mangrove',
            'category' => 'donation',
        ]);

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $donation->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['mission_id']);

        $this->assertEquals(0, UserMission::count());
    }

    public function test_prompt_wiring_sends_mission_criteria_to_gemini(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":true,"confidence":95,"waste_category":"electronic","reason":"E-waste terpilah rapi"}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission([
            'title' => 'Pilah Sampah Elektronik',
            'validation_prompt' => 'KRITERIA-WIRING-EWASTE-123',
        ]);

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('ewaste.jpg'),
        ], $this->authHeader($user));

        $response->assertStatus(201)->assertJsonPath('data.status', 'verified');

        Http::assertSent(function ($request) {
            $text = $request->data()['contents'][0]['parts'][0]['text'] ?? '';

            return str_contains($text, 'Pilah Sampah Elektronik')
                && str_contains($text, 'KRITERIA-WIRING-EWASTE-123')
                && str_contains($text, 'STRICT RULE');
        });

        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertEquals('E-waste terpilah rapi', $record->ai_gemini_response['reason']);
    }

    // ---------- Alasan terima/tolak yang dibaca pengguna ----------

    private function useOpenAiFake(string $innerText, int $status = 200): void
    {
        config()->set('services.gemini.driver', 'openai');
        config()->set('services.gemini.key', 'gsk-test');
        config()->set('services.gemini.mock', false);
        config()->set('services.gemini.model', 'qwen/qwen3.8-27b');
        config()->set('services.gemini.base_url', 'https://api.groq.com/openai/v1');

        Http::fake(['*' => Http::response([
            'choices' => [['message' => ['role' => 'assistant', 'content' => $innerText]]],
        ], $status)]);
    }

    public function test_verified_response_explains_why_accepted(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":true,"confidence":95,"waste_category":"plastic","reason":"Botol plastik sudah dipisahkan."}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user));

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.verification_note', 'AI mengenali foto ini sebagai plastik dengan keyakinan 95%.');
    }

    public function test_rejected_response_explains_why_rejected(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":false,"confidence":40,"waste_category":"unknown","reason":"Foto terlalu gelap, sampah tidak terlihat."}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('dark.jpg'),
        ], $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('data.status', 'rejected')
            ->assertJsonPath('data.rejection_reason', 'AI menilai foto ini belum memenuhi kriteria misi.')
            ->assertJsonPath('data.ai_reason', 'Foto terlalu gelap, sampah tidak terlihat.');

        $this->assertNotEmpty($response->json('data.hint'));

        // Alasan tersimpan juga di riwayat (kolom rejection_reason), bukan pesan teknis.
        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertSame('AI menilai foto ini belum memenuhi kriteria misi.', $record->rejection_reason);
        $this->assertStringNotContainsString('confidence', (string) $record->rejection_reason);
    }

    public function test_below_threshold_rejection_names_confidence_and_threshold(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":true,"confidence":80,"waste_category":"plastic","reason":"Kelihatan plastik."}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user));

        $response->assertOk()->assertJsonPath('data.status', 'rejected');

        $reason = (string) $response->json('data.rejection_reason');
        $this->assertStringContainsString('80%', $reason);
        $this->assertStringContainsString('85%', $reason);
    }

    public function test_wrong_category_hint_names_detected_category(): void
    {
        $this->useRealGeminiFake(
            '{"is_valid":false,"confidence":90,"waste_category":"paper","reason":"Yang terlihat kardus, bukan plastik."}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission(['title' => 'Pahlawan Plastik Terpilah']);

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('kardus.jpg'),
        ], $this->authHeader($user));

        $response->assertOk()->assertJsonPath('data.status', 'rejected');

        $hint = (string) $response->json('data.hint');
        $this->assertStringContainsString('kertas', $hint);
        $this->assertStringContainsString('sesuai misi', $hint);
    }

    public function test_duplicate_response_includes_indonesian_reason(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => $this->fixedPng('first.png'),
        ], $this->authHeader($user))->assertStatus(201);

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => $this->fixedPng('second.png'),
        ], $this->authHeader($user))
            ->assertStatus(409)
            ->assertJsonPath('data.rejection_reason', 'Foto ini sudah pernah dikirim sebelumnya. Setiap foto hanya boleh dipakai sekali.');
    }

    public function test_ai_unavailable_response_includes_indonesian_reason(): void
    {
        config()->set('services.gemini.key', 'test-key');
        config()->set('services.gemini.mock', false);
        Http::fake(['*' => Http::response(['error' => 'boom'], 500)]);

        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user))
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.rejection_reason', 'Validasi AI sedang tidak tersedia, jadi foto ini belum bisa dinilai.');
    }

    public function test_openai_driver_verifies_photo_end_to_end(): void
    {
        $this->useOpenAiFake(
            '{"is_valid":true,"confidence":0.98,"waste_category":"plastic","reason":"Botol dan gelas plastik terlihat terpisah."}'
        );
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $response = $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user));

        $response->assertStatus(201)
            ->assertJsonPath('data.status', 'verified')
            ->assertJsonPath('data.confidence', 98)
            ->assertJsonPath('data.waste_category', 'plastic')
            ->assertJsonPath('data.xp_earned', 300)
            ->assertJsonPath('data.verification_note', 'AI mengenali foto ini sebagai plastik dengan keyakinan 98%.');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.groq.com/openai/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer gsk-test')
            && str_contains((string) data_get($request->data(), 'messages.0.content.1.image_url.url'), 'data:image/'));

        // confidence 0.98 (rasio) tersimpan sebagai 98, bukan 0.98.
        $record = UserMission::find($response->json('data.user_mission_id'));
        $this->assertEquals(98.0, (float) $record->confidence_score);
        $this->assertSame('openai', $record->ai_gemini_response['provider']);
        $this->assertEquals(300, WargaProfile::where('user_id', $user->id)->first()->xp);
    }

    public function test_openai_driver_rate_limit_returns_503_pending(): void
    {
        $this->useOpenAiFake('{"error":{"message":"Rate limit reached"}}', 429);
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        $this->postJson('/api/missions/verify-waste', [
            'mission_id' => $mission->id,
            'image' => UploadedFile::fake()->image('waste.jpg'),
        ], $this->authHeader($user))
            ->assertStatus(503)
            ->assertJsonPath('data.status', 'pending');

        // Bukan mock: user tidak dapat poin, tapi juga tidak diblokir permanen.
        $this->assertEquals(0, WargaProfile::where('user_id', $user->id)->first()->eco_points);
    }
}
