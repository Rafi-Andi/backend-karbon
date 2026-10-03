<?php

namespace App\Http\Controllers;

use App\Models\WargaProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class CarbonStatsController extends Controller
{
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

        return response()->json([
            'success' => true,
            'message' => 'Carbon stats retrieved successfully.',
            'data' => [
                'total_distance_km' => (float) $profile->total_distance_km,
                'total_waste_kg' => (float) $profile->total_waste_kg,
                'total_carbon_saved_kg' => (float) $profile->total_carbon_saved_kg,
                'eco_points' => $profile->eco_points,
                'xp' => $profile->xp,
                'level' => $profile->level,
                'streak_days' => $profile->streak_days,
            ],
        ]);
    }
}
