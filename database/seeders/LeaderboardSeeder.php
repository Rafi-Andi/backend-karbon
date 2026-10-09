<?php

namespace Database\Seeders;

use App\Models\Mission;
use App\Models\User;
use App\Models\UserMission;
use App\Models\WargaProfile;
use App\Services\LevelService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LeaderboardSeeder extends Seeder
{
    public function run(): void
    {
        $missions = Mission::all();

        if ($missions->isEmpty()) {
            $this->command?->warn('LeaderboardSeeder dilewati: tabel missions kosong. Jalankan MissionSeeder dulu.');

            return;
        }

        // Level tidak di-hardcode di sini: dihitung via
        // LevelService::resolveLevel(xp) setelah total misi dijumlahkan.
        $users = [
            // --- RT 005 / RW 02 (satu RT dengan Rafi/Alya/Reza) ---
            ['name' => 'Budi Santoso', 'email' => 'budi@example.com', 'rt' => '005', 'rw' => '02', 'active' => true, 'streak' => 9],
            ['name' => 'Siti Rahma', 'email' => 'siti@example.com', 'rt' => '005', 'rw' => '02', 'active' => true, 'streak' => 8],
            ['name' => 'Dewi Lestari', 'email' => 'dewi@example.com', 'rt' => '005', 'rw' => '02', 'active' => true, 'streak' => 5],
            ['name' => 'Andi Pratama', 'email' => 'andi@example.com', 'rt' => '005', 'rw' => '02', 'active' => true, 'streak' => 3],
            ['name' => 'Maya Putri', 'email' => 'maya@example.com', 'rt' => '005', 'rw' => '02', 'active' => true, 'streak' => 4],
            // --- RT 006 / RW 02 (satu RW, beda RT: muncul di scope=rw saja) ---
            ['name' => 'Fajar Nugroho', 'email' => 'fajar@example.com', 'rt' => '006', 'rw' => '02', 'active' => true, 'streak' => 6],
            ['name' => 'Intan Permata', 'email' => 'intan@example.com', 'rt' => '006', 'rw' => '02', 'active' => true, 'streak' => 2],
            ['name' => 'Hendra Gunawan', 'email' => 'hendra@example.com', 'rt' => '006', 'rw' => '02', 'active' => true, 'streak' => 7],
            // --- RW 03 (kontrol negatif: tidak muncul di RT/RW 02) ---
            ['name' => 'Lina Marlina', 'email' => 'lina@example.com', 'rt' => '001', 'rw' => '03', 'active' => true, 'streak' => 5],
            ['name' => 'Doni Saputra', 'email' => 'doni@example.com', 'rt' => '001', 'rw' => '03', 'active' => false, 'streak' => 0],
            // --- Kontrol negatif beda kota (RT/RW sama 005/02 tapi Bandung: tidak boleh bocor ke Surabaya) ---
            ['name' => 'Rina Bandung', 'email' => 'rina.bandung@example.com', 'rt' => '005', 'rw' => '02', 'active' => true, 'streak' => 6, 'kota' => 'Bandung', 'kecamatan' => 'Coblong', 'kelurahan' => 'Dago'],
        ];

        // title => [days_ago, status]
        $missionsByEmail = [
            // Juara weekly: full misi 0-6 hari (masuk weekly + monthly).
            'budi@example.com' => [
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 1, 'status' => 'verified'],
                ['title' => 'Pilah Sampah Elektronik', 'days_ago' => 2, 'status' => 'verified'],
                ['title' => 'Sepeda Pagi Hari', 'days_ago' => 3, 'status' => 'verified'],
                ['title' => 'Pejuang Pedal 2Km', 'days_ago' => 5, 'status' => 'verified'],
                ['title' => 'Jalan Kaki 3Km', 'days_ago' => 6, 'status' => 'verified'],
            ],
            // Juara monthly: misi 10-25 hari (masuk monthly, TIDAK masuk weekly).
            'siti@example.com' => [
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 10, 'status' => 'verified'],
                ['title' => 'Pilah Sampah Elektronik', 'days_ago' => 14, 'status' => 'verified'],
                ['title' => 'Sepeda Pagi Hari', 'days_ago' => 18, 'status' => 'verified'],
                ['title' => 'Donasi Pohon Mangrove', 'days_ago' => 22, 'status' => 'verified'],
                ['title' => 'Kuis Hijau Harian', 'days_ago' => 25, 'status' => 'verified'],
            ],
            'dewi@example.com' => [
                ['title' => 'Pejuang Pedal 2Km', 'days_ago' => 2, 'status' => 'verified'],
                ['title' => 'Kuis Hijau Harian', 'days_ago' => 9, 'status' => 'verified'],
                ['title' => 'Jalan Kaki 3Km', 'days_ago' => 20, 'status' => 'verified'],
            ],
            'andi@example.com' => [
                ['title' => 'Kuis Hijau Harian', 'days_ago' => 1, 'status' => 'verified'],
                ['title' => 'Petualangan Kuis Hijau', 'days_ago' => 3, 'status' => 'verified'],
            ],
            // Misi tua saja (40-60 hari): tidak masuk weekly maupun monthly.
            'maya@example.com' => [
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 40, 'status' => 'verified'],
                ['title' => 'Sepeda Pagi Hari', 'days_ago' => 55, 'status' => 'verified'],
            ],
            'fajar@example.com' => [
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 2, 'status' => 'verified'],
                ['title' => 'Pejuang Pedal 2Km', 'days_ago' => 12, 'status' => 'verified'],
                ['title' => 'Kuis Hijau Harian', 'days_ago' => 16, 'status' => 'verified'],
            ],
            // Campuran verified/pending/rejected: hanya verified yang dihitung leaderboard.
            'intan@example.com' => [
                ['title' => 'Pejuang Pedal 2Km', 'days_ago' => 1, 'status' => 'verified'],
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 0, 'status' => 'pending'],
                ['title' => 'Sepeda Pagi Hari', 'days_ago' => 0, 'status' => 'rejected'],
            ],
            'hendra@example.com' => [
                ['title' => 'Pilah Sampah Elektronik', 'days_ago' => 4, 'status' => 'verified'],
                ['title' => 'Donasi Pohon Mangrove', 'days_ago' => 11, 'status' => 'verified'],
                ['title' => 'Jejak Karbon Harian', 'days_ago' => 21, 'status' => 'verified'],
            ],
            'lina@example.com' => [
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 1, 'status' => 'verified'],
                ['title' => 'Sepeda Pagi Hari', 'days_ago' => 5, 'status' => 'verified'],
            ],
            // User non-aktif: harus ter-exclude dari leaderboard walau misinya verified.
            'doni@example.com' => [
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 1, 'status' => 'verified'],
                ['title' => 'Pilah Sampah Elektronik', 'days_ago' => 2, 'status' => 'verified'],
            ],
            // Kontrol beda kota: RT/RW sama tapi tidak boleh muncul di leaderboard Surabaya.
            'rina.bandung@example.com' => [
                ['title' => 'Pahlawan Plastik Terpilah', 'days_ago' => 1, 'status' => 'verified'],
                ['title' => 'Sepeda Pagi Hari', 'days_ago' => 2, 'status' => 'verified'],
            ],
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'phone' => '+628100000'.random_int(100, 999),
                    'password' => Hash::make('SecurePassword123!'),
                    'role' => 'warga',
                    'kota' => $data['kota'] ?? 'Surabaya',
                    'kecamatan' => $data['kecamatan'] ?? 'Gubeng',
                    'kelurahan' => $data['kelurahan'] ?? 'Mojo',
                    'rt' => $data['rt'],
                    'rw' => $data['rw'],
                    'is_active' => $data['active'],
                ]
            );

            // Sinkron status aktif bila seeder dijalankan ulang.
            $user->update(['is_active' => $data['active']]);

            WargaProfile::updateOrCreate(
                ['user_id' => $user->id],
                [
                    // Level sementara; nilai final dihitung ulang dari XP
                    // via LevelService setelah total misi dijumlahkan.
                    'level' => LevelService::resolveLevel(0),
                    'xp' => 0,
                    'eco_points' => 0,
                    'streak_days' => $data['streak'],
                    'total_distance_km' => 0,
                    'total_waste_kg' => 0,
                    'total_carbon_saved_kg' => 0,
                ]
            );

            // Idempotent: hapus misi lama user dummy ini lalu buat ulang.
            UserMission::where('user_id', $user->id)->delete();

            $rows = [];
            foreach ($missionsByEmail[$data['email']] ?? [] as $i => $item) {
                $mission = $missions->where('title', $item['title'])->first();

                if (! $mission) {
                    continue;
                }

                $stampedAt = now()->subDays($item['days_ago'])->setTime(10, $i, 0);

                $rows[] = [
                    'user_id' => $user->id,
                    'mission_id' => $mission->id,
                    'proof_image_url' => 'seed/leaderboard_'.$user->id.'_'.$mission->id.'_'.$i.'.jpg',
                    'status' => $item['status'],
                    'anti_fraud_flagged' => false,
                    'created_at' => $stampedAt,
                    'updated_at' => $stampedAt,
                ];
            }

            if (! empty($rows)) {
                // Insert langsung agar created_at/updated_at backdate tersimpan
                // (UserMission $fillable tidak mencakup kolom timestamp).
                DB::table('user_missions')->insert($rows);
            }

            // Sinkron profil agar konsisten dengan VerifyDataSeeder
            // (xp/eco_points = SUM reward misi verified).
            $totals = DB::table('user_missions')
                ->join('missions', 'user_missions.mission_id', '=', 'missions.id')
                ->where('user_missions.user_id', $user->id)
                ->where('user_missions.status', 'verified')
                ->selectRaw('COALESCE(SUM(missions.xp_reward), 0) as xp, COALESCE(SUM(missions.points_reward), 0) as points')
                ->first();

            $syncedXp = (int) ($totals->xp ?? 0);
            WargaProfile::where('user_id', $user->id)->update([
                'xp' => $syncedXp,
                'eco_points' => (int) ($totals->points ?? 0),
                // Sinkron label level dengan XP agar tidak "turun"
                // saat user main kuis/misi (resolveLevel menimpa).
                'level' => LevelService::resolveLevel($syncedXp),
            ]);
        }
    }
}
