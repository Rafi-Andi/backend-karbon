<?php

namespace Tests\Feature;

use App\Models\Mission;
use App\Models\MitraProfile;
use App\Models\PointTransaction;
use App\Models\Quiz;
use App\Models\User;
use App\Models\UserMission;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\WargaProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(User $user): array
    {
        return ['Authorization' => 'Bearer '.$user->createToken('auth_token')->plainTextToken];
    }

    private function makeWarga(): User
    {
        $user = User::factory()->create(['role' => 'warga', 'rt' => '005', 'rw' => '02', 'kelurahan' => 'Smoea']);
        WargaProfile::create([
            'user_id' => $user->id,
            'level' => 'Earth Newbie',
            'xp' => 0,
            'eco_points' => 1000,
            'streak_days' => 0,
        ]);

        return $user;
    }

    private function makeMission(array $over = []): Mission
    {
        return Mission::create(array_merge([
            'title' => 'Test Mission',
            'description' => 'desc',
            'category' => 'waste',
            'xp_reward' => 50,
            'points_reward' => 50,
            'is_active' => true,
        ], $over));
    }

    public function test_unauthenticated_returns_401(): void
    {
        $this->getJson('/api/user/activities')->assertStatus(401);
    }

    public function test_empty_returns_empty_array(): void
    {
        $user = $this->makeWarga();

        $this->getJson('/api/user/activities', $this->authHeader($user))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data', []);
    }

    public function test_maps_waste_mobility_voucher_quiz_in_desc_order(): void
    {
        $user = $this->makeWarga();

        $waste = $this->makeMission(['title' => 'Pilah Sampah', 'category' => 'waste']);
        $wasteUm = UserMission::create(['user_id' => $user->id, 'mission_id' => $waste->id, 'status' => 'verified']);
        $wasteTx = PointTransaction::create([
            'user_id' => $user->id, 'type' => 'credit', 'amount' => 50, 'balance_after' => 1050,
            'reference_type' => UserMission::class, 'reference_id' => $wasteUm->id,
            'description' => 'Waste mission verified: Pilah Sampah',
        ]);

        $mobility = $this->makeMission(['title' => 'Gowes', 'category' => 'mobility']);
        $mobUm = UserMission::create([
            'user_id' => $user->id, 'mission_id' => $mobility->id, 'status' => 'verified',
            'ai_gemini_response' => ['source' => 'mobility-sync', 'activity_type' => 'cycling', 'distance_km' => 2],
        ]);
        $mobTx = PointTransaction::create([
            'user_id' => $user->id, 'type' => 'credit', 'amount' => 45, 'balance_after' => 1095,
            'reference_type' => UserMission::class, 'reference_id' => $mobUm->id,
            'description' => 'Mobility activity synced: Gowes',
        ]);

        $mitraUser = User::factory()->mitra()->create();
        $mitra = MitraProfile::create([
            'user_id' => $mitraUser->id,
            'nama_usaha' => 'Warung Test',
            'jenis_usaha' => 'UMKM',
            'alamat_usaha' => 'Jl. Test No 1',
            'nama_bank' => 'Bank BCA',
            'nomor_rekening' => '1234567890',
            'nama_pemilik_rekening' => 'Warung Test',
            'status_verifikasi' => 'verified',
            'is_active' => true,
            'balance' => 0,
        ]);
        $voucher = Voucher::create([
            'mitra_profile_id' => $mitra->id,
            'title' => 'Kopi UMKM', 'description' => 'd', 'points_cost' => 200,
            'rupiah_value' => 20000, 'stock' => 10, 'claimed_count' => 0,
            'is_active' => true, 'expired_at' => now()->addMonth()->toDateString(),
        ]);
        $claim = VoucherClaim::create(['user_id' => $user->id, 'voucher_id' => $voucher->id, 'qr_token' => 'KBK-AAA-BBB', 'status' => 'claimed', 'claimed_at' => now()]);
        $voucherTx = PointTransaction::create([
            'user_id' => $user->id, 'type' => 'debit', 'amount' => 200, 'balance_after' => 895,
            'reference_type' => VoucherClaim::class, 'reference_id' => $claim->id,
            'description' => 'Claimed voucher: Kopi UMKM',
        ]);

        $quizMission = $this->makeMission(['title' => 'Node 1', 'category' => 'quiz', 'xp_reward' => 150]);
        foreach (range(1, 5) as $i) {
            Quiz::create([
                'mission_id' => $quizMission->id, 'question' => "Q$i",
                'options' => ['A' => 'a', 'B' => 'b', 'C' => 'c', 'D' => 'd'],
                'correct_answer' => 'A', 'order' => $i,
            ]);
        }
        $quizUm = UserMission::create([
            'user_id' => $user->id, 'mission_id' => $quizMission->id, 'status' => 'verified',
            'confidence_score' => 100, 'ai_gemini_response' => ['quiz_id' => 1, 'answer' => 'correct'],
        ]);

        // Timestamp deterministik (created_at bukan fillable → set via query builder).
        DB::table('point_transactions')->where('id', $wasteTx->id)->update(['created_at' => now()->subHours(4)]);
        DB::table('point_transactions')->where('id', $mobTx->id)->update(['created_at' => now()->subHours(3)]);
        DB::table('point_transactions')->where('id', $voucherTx->id)->update(['created_at' => now()->subHours(2)]);
        DB::table('user_missions')->where('id', $quizUm->id)->update(['created_at' => now()->subHour()]);

        $response = $this->getJson('/api/user/activities', $this->authHeader($user));

        $response->assertOk()->assertJsonPath('success', true);
        $data = $response->json('data');
        $this->assertCount(4, $data);

        // Terbaru dulu: kuis terakhir dibuat.
        $this->assertSame('quiz', $data[0]['kind']);
        $this->assertSame('Kuis Hijau Harian', $data[0]['title']);
        $this->assertSame(30, $data[0]['delta']); // 150 / 5 soal

        $kinds = collect($data)->pluck('kind')->all();
        $this->assertContains('waste', $kinds);
        $this->assertContains('mobility', $kinds);
        $this->assertContains('voucher', $kinds);

        $wasteRow = collect($data)->firstWhere('kind', 'waste');
        $this->assertSame('Validasi Sampah AI', $wasteRow['title']);
        $this->assertSame(50, $wasteRow['delta']);

        $mobRow = collect($data)->firstWhere('kind', 'mobility');
        $this->assertSame('Tracker Bersepeda', $mobRow['title']);
        $this->assertSame(45, $mobRow['delta']);

        $voucherRow = collect($data)->firstWhere('kind', 'voucher');
        $this->assertSame('Tukar Voucher UMKM', $voucherRow['title']);
        $this->assertSame(-200, $voucherRow['delta']);

        $response->assertJsonStructure(['success', 'message', 'data' => ['*' => ['id', 'kind', 'title', 'delta', 'created_at']]]);
    }

    public function test_limit_param_caps_results(): void
    {
        $user = $this->makeWarga();
        $mission = $this->makeMission();

        foreach (range(1, 3) as $i) {
            $um = UserMission::create(['user_id' => $user->id, 'mission_id' => $mission->id, 'status' => 'verified']);
            PointTransaction::create([
                'user_id' => $user->id, 'type' => 'credit', 'amount' => 10, 'balance_after' => 1000 + $i * 10,
                'reference_type' => UserMission::class, 'reference_id' => $um->id, 'description' => "tx $i",
            ]);
        }

        $response = $this->getJson('/api/user/activities?limit=2', $this->authHeader($user));

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_dashboard_user_carries_rt_rw_kelurahan(): void
    {
        $user = $this->makeWarga();

        $response = $this->getJson('/api/user/dashboard', $this->authHeader($user));

        $response->assertOk()
            ->assertJsonPath('data.user.rt', '005')
            ->assertJsonPath('data.user.rw', '02')
            ->assertJsonPath('data.user.kelurahan', 'Smoea');
    }

    public function test_route_name_resolves(): void
    {
        $this->assertEquals('/api/user/activities', route('user.activities', [], false));
    }
}
