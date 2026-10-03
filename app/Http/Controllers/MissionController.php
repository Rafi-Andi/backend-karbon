<?php

namespace App\Http\Controllers;

use App\Http\Requests\MobilitySyncRequest;
use App\Http\Requests\VerifyWasteRequest;
use App\Http\Resources\MissionResource;
use App\Models\Mission;
use App\Models\MobilityLog;
use App\Models\PointTransaction;
use App\Models\UserMission;
use App\Models\WargaProfile;
use App\Services\GeminiService;
use App\Services\LevelService;
use App\Services\StreakService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MissionController extends Controller
{
    /**
     * Estimasi berat sampah per verifikasi sukses (kg).
     * AI hanya mengembalikan kategori + confidence, bukan berat.
     */
    public const WASTE_KG_ESTIMATE = 1.0;

    /**
     * Estimasi CO2 yang dihemat per km (gram), setara emisi mobil bensin.
     */
    public const CO2_GRAMS_PER_KM = 210;

    /**
     * Batas kecepatan rata-rata wajar untuk jalan/sepeda (km/h).
     * Di atas ini dianggap data GPS tidak valid.
     */
    public const MAX_AVG_SPEED_KMH = 30;

    /**
     * GET /api/missions/active — daftar misi mobility & waste yang aktif.
     * Quiz dikecualikan (lewat Saga), inactive dikecualikan.
     * Tiap item membawa `is_completed_today` (verified hari ini, WIB)
     * untuk status harian 1x per misi.
     */
    public function index(): JsonResponse
    {
        $missions = Mission::where('is_active', true)
            ->whereIn('category', ['mobility', 'waste'])
            ->orderBy('id')
            ->get();

        $completedIds = $this->completedTodayMissionIds(
            (int) Auth::id(),
            $missions->pluck('id')->all()
        );

        $missions->each(function ($mission) use ($completedIds) {
            $mission->is_completed_today = in_array($mission->id, $completedIds, true);
        });

        return response()->json([
            'success' => true,
            'message' => 'Active missions retrieved successfully.',
            'data' => MissionResource::collection($missions),
        ]);
    }

    /**
     * ID misi yang sudah verified hari ini (WIB) oleh user.
     * Satu-satunya pengunci harian 1x per misi (mobility & waste).
     *
     * @param  list<int>  $missionIds
     * @return list<int>
     */
    private function completedTodayMissionIds(int $userId, array $missionIds): array
    {
        if ($missionIds === [] || $userId <= 0) {
            return [];
        }

        $todayWib = Carbon::now(StreakService::TZ)->toDateString();

        return UserMission::where('user_id', $userId)
            ->whereIn('mission_id', $missionIds)
            ->where('status', 'verified')
            ->whereDate('created_at', $todayWib)
            ->pluck('mission_id')
            ->unique()
            ->values()
            ->all();
    }

    private function hasCompletedToday(int $userId, int $missionId): bool
    {
        return $this->completedTodayMissionIds($userId, [$missionId]) !== [];
    }

    private function dailyCapResponse(int $missionId): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Misi sudah diselesaikan hari ini. Coba lagi besok!',
            'data' => [
                'mission_id' => $missionId,
                'already_completed_today' => true,
            ],
        ], 409);
    }

    /**
     * POST /api/missions/verify-waste — multipart: mission_id, image.
     * Anti-fraud: blokir hash gambar duplikat (global, antar user).
     * EXIF dibaca best-effort untuk audit, tidak pernah hard-fail.
     * Reward hanya jika AI menyatakan valid DAN confidence >= threshold.
     */
    public function verifyWaste(VerifyWasteRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();

        /** @var Mission $mission */
        $mission = Mission::findOrFail($validated['mission_id']);

        $image = $request->file('image');
        $hash = hash_file('sha256', (string) $image->getRealPath());

        // Fast-path duplicate check sebelum simpan file / panggil AI.
        // Didahulukan dari kunci harian agar fraud tetap terdeteksi
        // spesifik sebagai duplikat, bukan sekadar sudah selesai.
        if (UserMission::where('proof_image_hash', $hash)->exists()) {
            $duplicate = UserMission::create([
                'user_id' => $user->id,
                'mission_id' => $mission->id,
                'proof_image_hash' => $hash,
                'status' => 'rejected',
                'anti_fraud_flagged' => true,
                'rejection_reason' => 'Duplicate image detected. Each photo may only be submitted once.',
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Duplicate image detected. Each photo may only be submitted once.',
                'data' => [
                    'user_mission_id' => $duplicate->id,
                    'status' => 'rejected',
                ],
            ], 409);
        }

        // Kunci harian 1x per misi — setelah cek duplikat, sebelum panggil AI
        // agar hemat biaya dan tetap spesifik untuk kasus fraud.
        if ($this->hasCompletedToday((int) $user->id, (int) $mission->id)) {
            return $this->dailyCapResponse((int) $mission->id);
        }

        $exifGps = $this->readExifGps((string) $image->getRealPath());
        $mime = (string) $image->getMimeType();

        $path = $image->store("proofs/{$user->id}", 'public');

        $gemini = GeminiService::fromConfig();

        try {
            $ai = $gemini->verifyWaste(Storage::disk('public')->path($path), $mime, $mission);
        } catch (\Throwable $e) {
            report($e);

            $pending = UserMission::create([
                'user_id' => $user->id,
                'mission_id' => $mission->id,
                'proof_image_url' => $path,
                'proof_image_hash' => $hash,
                'status' => 'pending',
                'rejection_reason' => 'AI validation temporarily unavailable. Please try again later.',
                'ai_gemini_response' => ['meta' => ['mime' => $mime, 'exif_gps' => $exifGps]],
            ]);

            return response()->json([
                'success' => false,
                'message' => 'AI validation temporarily unavailable. Please try again later.',
                'data' => [
                    'user_mission_id' => $pending->id,
                    'status' => 'pending',
                ],
            ], 503);
        }

        $result = DB::transaction(function () use ($user, $mission, $hash, $path, $mime, $exifGps, $ai, $gemini) {
            // Cek ulang dalam transaksi (anti race condition upload bersamaan).
            if (UserMission::where('proof_image_hash', $hash)->exists()) {
                $duplicate = UserMission::create([
                    'user_id' => $user->id,
                    'mission_id' => $mission->id,
                    'proof_image_hash' => $hash,
                    'status' => 'rejected',
                    'anti_fraud_flagged' => true,
                    'rejection_reason' => 'Duplicate image detected. Each photo may only be submitted once.',
                ]);

                return ['duplicate' => true, 'user_mission_id' => $duplicate->id];
            }

            $profile = WargaProfile::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $profile) {
                throw new \Exception('User profile not found.');
            }

            $passes = $ai['is_valid'] && $gemini->passesThreshold($ai);

            // Kunci harian dicek ulang setelah lock (anti race).
            // Hanya hasil verified yang mengunci; rejected boleh coba lagi.
            if ($passes && $this->hasCompletedToday((int) $user->id, (int) $mission->id)) {
                return ['capped' => true, 'mission_id' => (int) $mission->id];
            }

            $userMission = UserMission::create([
                'user_id' => $user->id,
                'mission_id' => $mission->id,
                'proof_image_url' => $path,
                'proof_image_hash' => $hash,
                'ai_gemini_response' => array_merge($ai, [
                    'meta' => ['mime' => $mime, 'exif_gps' => $exifGps],
                ]),
                'confidence_score' => $ai['confidence'],
                'status' => $passes ? 'verified' : 'rejected',
                'rejection_reason' => $passes
                    ? null
                    : sprintf(
                        'AI validation failed (valid: %s, confidence: %s, threshold: %d).',
                        $ai['is_valid'] ? 'yes' : 'no',
                        $ai['confidence'],
                        $gemini->getMinConfidence()
                    ),
            ]);

            if (! $passes) {
                return [
                    'duplicate' => false,
                    'user_mission_id' => $userMission->id,
                    'status' => 'rejected',
                    'is_valid' => $ai['is_valid'],
                    'confidence' => $ai['confidence'],
                    'waste_category' => $ai['waste_category'],
                    'xp_earned' => 0,
                    'points_earned' => 0,
                ];
            }

            $xpReward = $mission->xp_reward ?? 0;
            $pointsReward = $mission->points_reward ?? 0;

            $newXp = $profile->xp + $xpReward;
            $newPoints = $profile->eco_points + $pointsReward;
            $newStreak = StreakService::nextStreak($profile->last_mission_at, $profile->streak_days);
            $newLevel = LevelService::resolveLevel($newXp);

            $profile->update([
                'xp' => $newXp,
                'eco_points' => $newPoints,
                'level' => $newLevel,
                'streak_days' => $newStreak,
                'last_mission_at' => now(),
                'total_waste_kg' => $profile->total_waste_kg + self::WASTE_KG_ESTIMATE,
            ]);

            PointTransaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => $pointsReward,
                'balance_after' => $newPoints,
                'reference_type' => UserMission::class,
                'reference_id' => $userMission->id,
                'description' => 'Waste mission verified: '.$mission->title,
            ]);

            return [
                'duplicate' => false,
                'user_mission_id' => $userMission->id,
                'status' => 'verified',
                'is_valid' => $ai['is_valid'],
                'confidence' => $ai['confidence'],
                'waste_category' => $ai['waste_category'],
                'xp_earned' => $xpReward,
                'points_earned' => $pointsReward,
                'new_xp' => $newXp,
                'new_level' => $newLevel,
                'new_eco_points' => $newPoints,
                'streak_days' => $newStreak,
            ];
        });

        if (($result['capped'] ?? false) === true) {
            return $this->dailyCapResponse((int) $result['mission_id']);
        }

        if ($result['duplicate']) {
            return response()->json([
                'success' => false,
                'message' => 'Duplicate image detected. Each photo may only be submitted once.',
                'data' => [
                    'user_mission_id' => $result['user_mission_id'],
                    'status' => 'rejected',
                ],
            ], 409);
        }

        if ($result['status'] === 'rejected') {
            return response()->json([
                'success' => true,
                'message' => 'Photo rejected by AI validation. Try again with a clearer photo!',
                'data' => $result,
            ]);
        }

        return response()->json([
            'success' => true,
            'message' => "Waste verified! +{$result['xp_earned']} XP, +{$result['points_earned']} points.",
            'data' => $result,
        ], 201);
    }

    /**
     * POST /api/missions/mobility-sync — JSON: mission_id?, activity_type,
     * distance_km, duration_seconds, gps_coordinates_path [{lat, lng}].
     * Mapping kontrak → skema DB: activity_type → transport_mode,
     * duration_seconds → duration_minutes (ceil), path → start/end/route.
     * Reward memakai xp/points penuh milik misi, dibatasi harian 1x per misi.
     */
    public function mobilitySync(MobilitySyncRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();

        $mission = ($validated['mission_id'] ?? null) !== null
            ? Mission::findOrFail($validated['mission_id'])
            : Mission::where('category', 'mobility')->where('is_active', true)->orderBy('id')->first();

        if (! $mission) {
            return response()->json([
                'success' => false,
                'message' => 'No active mobility mission available.',
            ], 422);
        }

        // Kunci harian 1x per misi (terhadap misi hasil resolve).
        if ($this->hasCompletedToday((int) $user->id, (int) $mission->id)) {
            return $this->dailyCapResponse((int) $mission->id);
        }

        $distanceKm = (float) $validated['distance_km'];

        // Target jarak misi wajib tercapai (fallback 0,1 km bila tak diset).
        $targetKm = $mission->target_distance_km !== null
            ? (float) $mission->target_distance_km
            : 0.1;

        if ($distanceKm < $targetKm) {
            return response()->json([
                'success' => false,
                'message' => sprintf(
                    'Jarak belum mencapai target misi (%.2f KM).',
                    $targetKm
                ),
                'data' => [
                    'mission_id' => (int) $mission->id,
                    'distance_km' => $distanceKm,
                    'required_distance_km' => round($targetKm, 2),
                ],
            ], 422);
        }

        $durationSeconds = (int) $validated['duration_seconds'];
        $avgSpeedKmh = $distanceKm / ($durationSeconds / 3600);

        if ($avgSpeedKmh > self::MAX_AVG_SPEED_KMH) {
            $rejected = UserMission::create([
                'user_id' => $user->id,
                'mission_id' => $mission->id,
                'status' => 'rejected',
                'confidence_score' => 0,
                'ai_gemini_response' => [
                    'source' => 'mobility-sync',
                    'avg_speed_kmh' => round($avgSpeedKmh, 2),
                ],
                'rejection_reason' => sprintf(
                    'Unrealistic average speed (%.1f km/h exceeds %d km/h limit).',
                    $avgSpeedKmh,
                    self::MAX_AVG_SPEED_KMH
                ),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unrealistic activity data. Please sync a valid route.',
                'data' => [
                    'user_mission_id' => $rejected->id,
                    'status' => 'rejected',
                    'avg_speed_kmh' => round($avgSpeedKmh, 2),
                ],
            ], 422);
        }

        $route = array_values($validated['gps_coordinates_path']);
        $durationMinutes = (int) ceil($durationSeconds / 60);
        $co2Grams = round($distanceKm * self::CO2_GRAMS_PER_KM, 2);

        $result = DB::transaction(function () use ($user, $mission, $validated, $route, $distanceKm, $durationMinutes, $co2Grams) {
            $profile = WargaProfile::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $profile) {
                throw new \Exception('User profile not found.');
            }

            // Cek ulang setelah lock (anti race sync bersamaan).
            if ($this->hasCompletedToday((int) $user->id, (int) $mission->id)) {
                return ['capped' => true, 'mission_id' => (int) $mission->id];
            }

            $userMission = UserMission::create([
                'user_id' => $user->id,
                'mission_id' => $mission->id,
                'status' => 'verified',
                'confidence_score' => 100,
                'ai_gemini_response' => [
                    'source' => 'mobility-sync',
                    'activity_type' => $validated['activity_type'],
                    'distance_km' => $distanceKm,
                ],
            ]);

            $mobilityLog = MobilityLog::create([
                'user_mission_id' => $userMission->id,
                'user_id' => $user->id,
                'start_point' => $route[0],
                'end_point' => $route[count($route) - 1],
                'route_coordinates' => $route,
                'distance_km' => $distanceKm,
                'co2_saved_grams' => $co2Grams,
                'duration_minutes' => $durationMinutes,
                'transport_mode' => $validated['activity_type'],
            ]);

            $xpReward = $mission->xp_reward ?? 0;
            $pointsReward = $mission->points_reward ?? 0;

            $newXp = $profile->xp + $xpReward;
            $newPoints = $profile->eco_points + $pointsReward;
            $newStreak = StreakService::nextStreak($profile->last_mission_at, $profile->streak_days);
            $newLevel = LevelService::resolveLevel($newXp);

            $profile->update([
                'xp' => $newXp,
                'eco_points' => $newPoints,
                'level' => $newLevel,
                'streak_days' => $newStreak,
                'last_mission_at' => now(),
                'total_distance_km' => $profile->total_distance_km + $distanceKm,
                'total_carbon_saved_kg' => $profile->total_carbon_saved_kg + ($co2Grams / 1000),
            ]);

            PointTransaction::create([
                'user_id' => $user->id,
                'type' => 'credit',
                'amount' => $pointsReward,
                'balance_after' => $newPoints,
                'reference_type' => UserMission::class,
                'reference_id' => $userMission->id,
                'description' => 'Mobility activity synced: '.$mission->title,
            ]);

            return [
                'user_mission_id' => $userMission->id,
                'mobility_log_id' => $mobilityLog->id,
                'status' => 'verified',
                'xp_earned' => $xpReward,
                'points_earned' => $pointsReward,
                'distance_km' => $distanceKm,
                'duration_minutes' => $durationMinutes,
                'co2_saved_grams' => $co2Grams,
                'new_xp' => $newXp,
                'new_level' => $newLevel,
                'new_eco_points' => $newPoints,
                'streak_days' => $newStreak,
            ];
        });

        if (($result['capped'] ?? false) === true) {
            return $this->dailyCapResponse((int) $result['mission_id']);
        }

        return response()->json([
            'success' => true,
            'message' => "Activity synced! +{$result['xp_earned']} XP, +{$result['points_earned']} points.",
            'data' => $result,
        ], 201);
    }

    /**
     * Baca koordinat GPS dari EXIF (best-effort, longgar).
     * Return null jika tidak ada EXIF/GPS — bukan kondisi gagal.
     *
     * @return array{latitude: float, longitude: float}|null
     */
    private function readExifGps(string $path): ?array
    {
        try {
            if (! function_exists('exif_read_data')) {
                return null;
            }

            $exif = @exif_read_data($path);

            if (! is_array($exif)
                || ! isset($exif['GPSLatitude'], $exif['GPSLatitudeRef'], $exif['GPSLongitude'], $exif['GPSLongitudeRef'])
            ) {
                return null;
            }

            $latitude = $this->gpsToDecimal($exif['GPSLatitude'], $exif['GPSLatitudeRef']);
            $longitude = $this->gpsToDecimal($exif['GPSLongitude'], $exif['GPSLongitudeRef']);

            if ($latitude === null || $longitude === null) {
                return null;
            }

            return ['latitude' => $latitude, 'longitude' => $longitude];
        } catch (\Throwable) {
            return null;
        }
    }

    private function gpsToDecimal(mixed $coordinate, mixed $hemisphere): ?float
    {
        if (! is_array($coordinate) || count($coordinate) !== 3) {
            return null;
        }

        $degrees = $this->exifFraction($coordinate[0]);
        $minutes = $this->exifFraction($coordinate[1]);
        $seconds = $this->exifFraction($coordinate[2]);

        if ($degrees === null || $minutes === null || $seconds === null) {
            return null;
        }

        $decimal = $degrees + ($minutes / 60) + ($seconds / 3600);

        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $decimal *= -1;
        }

        return round($decimal, 6);
    }

    private function exifFraction(mixed $value): ?float
    {
        if (is_numeric($value)) {
            return (float) $value;
        }

        if (is_string($value) && str_contains($value, '/')) {
            [$numerator, $denominator] = explode('/', $value, 2);

            if (is_numeric($numerator) && is_numeric($denominator) && (float) $denominator !== 0.0) {
                return (float) $numerator / (float) $denominator;
            }
        }

        return null;
    }
}
