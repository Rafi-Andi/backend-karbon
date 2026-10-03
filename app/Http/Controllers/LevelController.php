<?php

namespace App\Http\Controllers;

use App\Models\WargaProfile;
use App\Services\LevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class LevelController extends Controller
{
    /**
     * GET /api/user/levels — 3 tier lencana level untuk Profil.
     * Unlock dihitung dari XP (bukan label tersimpan) agar tahan data lama.
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        $profile = WargaProfile::where('user_id', $user->id)->first();

        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'User profile not found.',
            ], 404);
        }

        $xp = (int) $profile->xp;

        return response()->json([
            'success' => true,
            'message' => 'Level tiers retrieved successfully.',
            'data' => [
                'xp' => $xp,
                'level' => $profile->level,
                'current_tier' => LevelService::getTierKeyForXp($xp),
                'tiers' => LevelService::getTiers($xp),
            ],
        ]);
    }
}
