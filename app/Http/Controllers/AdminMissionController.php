<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMissionRequest;
use App\Http\Requests\UpdateMissionRequest;
use App\Http\Resources\MissionResource;
use App\Models\Mission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class AdminMissionController extends Controller
{
    /**
     * Kategori yang boleh dikelola via panel admin.
     * Quiz butuh baris soal Saga, donation lewat panel donasi.
     */
    private const MANAGEABLE = ['mobility', 'waste'];

    /**
     * GET /api/admin/missions — semua misi mobility & waste,
     * termasuk yang nonaktif (untuk layar kelola).
     */
    public function index(): JsonResponse
    {
        $missions = Mission::whereIn('category', self::MANAGEABLE)
            ->orderBy('id')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Missions retrieved successfully.',
            'data' => MissionResource::collection($missions),
        ]);
    }

    /**
     * POST /api/admin/missions — buat misi mobility/waste baru.
     */
    public function store(StoreMissionRequest $request): JsonResponse
    {
        $mission = Mission::create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Mission created successfully.',
            'data' => new MissionResource($mission),
        ], 201);
    }

    /**
     * PATCH /api/admin/missions/{id} — ubah misi (kecuali category).
     */
    public function update(UpdateMissionRequest $request, int $id): JsonResponse
    {
        $mission = $this->findManageable($id);

        if (! $mission) {
            return $this->notFound();
        }

        $mission->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Mission updated successfully.',
            'data' => new MissionResource($mission->refresh()),
        ]);
    }

    /**
     * PATCH /api/admin/missions/{id}/status — aktif/nonaktif saja.
     * Pengganti hapus: riwayat user_missions tetap utuh.
     */
    public function setStatus(Request $request, int $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'is_active' => ['required', 'boolean'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $mission = $this->findManageable($id);

        if (! $mission) {
            return $this->notFound();
        }

        $mission->update(['is_active' => (bool) $validator->validated()['is_active']]);

        return response()->json([
            'success' => true,
            'message' => $mission->is_active
                ? 'Mission activated successfully.'
                : 'Mission deactivated successfully.',
            'data' => new MissionResource($mission->refresh()),
        ]);
    }

    private function findManageable(int $id): ?Mission
    {
        return Mission::whereIn('category', self::MANAGEABLE)->find($id);
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => 'Mission not found.',
        ], 404);
    }
}
