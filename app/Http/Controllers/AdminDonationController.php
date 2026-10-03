<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateCampaignRequest;
use App\Http\Requests\CreateFundedVoucherRequest;
use App\Http\Requests\UpdateCampaignRequest;
use App\Models\DonationCampaign;
use App\Models\MitraProfile;
use App\Models\Voucher;
use App\Models\VoucherFundAllocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AdminDonationController extends Controller
{
    /**
     * GET /api/admin/donation-campaigns — semua campaign + available.
     */
    public function index(): JsonResponse
    {
        $campaigns = DonationCampaign::orderByDesc('id')->get()->map(fn ($c) => [
            'id' => $c->id,
            'title' => $c->title,
            'slug' => $c->slug,
            'status' => $c->status,
            'target_amount' => number_format((float) $c->target_amount, 2, '.', ''),
            'collected_amount' => number_format((float) $c->collected_amount, 2, '.', ''),
            'allocated_amount' => number_format((float) $c->allocated_amount, 2, '.', ''),
            'available_amount' => number_format($c->availableAmount(), 2, '.', ''),
            'progress_percent' => $c->progressPercent(),
            'started_at' => $c->started_at?->format('Y-m-d\TH:i:s'),
            'ended_at' => $c->ended_at?->format('Y-m-d\TH:i:s'),
        ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Donation campaigns retrieved successfully.',
            'data' => $campaigns,
        ]);
    }

    /**
     * POST /api/admin/donation-campaigns — buat campaign CSR/komunitas.
     */
    public function store(CreateCampaignRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $campaign = DonationCampaign::create([
            'title' => $validated['title'],
            'slug' => $validated['slug'] ?? Str::slug($validated['title']).'-'.Str::lower(Str::random(6)),
            'description' => $validated['description'],
            'image_url' => $validated['image_url'] ?? null,
            'target_amount' => $validated['target_amount'],
            'status' => $validated['status'] ?? 'draft',
            'started_at' => $validated['started_at'] ?? null,
            'ended_at' => $validated['ended_at'] ?? null,
            'created_by' => Auth::id(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Campaign created successfully.',
            'data' => ['id' => $campaign->id, 'slug' => $campaign->slug, 'status' => $campaign->status],
        ], 201);
    }

    /**
     * PATCH /api/admin/donation-campaigns/{id} — ubah campaign
     * (draft/active/closed, target, jadwal).
     */
    public function update(UpdateCampaignRequest $request, int $id): JsonResponse
    {
        $campaign = DonationCampaign::find($id);

        if (! $campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found.',
            ], 404);
        }

        $campaign->update($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Campaign updated successfully.',
            'data' => ['id' => $campaign->id, 'slug' => $campaign->slug, 'status' => $campaign->status],
        ]);
    }

    /**
     * POST /api/admin/vouchers — buat voucher didanai campaign.
     * Total kebutuhan = stock × rupiah_value, wajib ≤ available.
     * Rollback total jika dana kurang (cek di dalam transaction + lock).
     */
    public function storeVoucher(CreateFundedVoucherRequest $request): JsonResponse
    {
        $validated = $request->validated();

        try {
            $result = DB::transaction(function () use ($validated) {
                $campaign = DonationCampaign::where('id', $validated['campaign_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $campaign) {
                    throw new HttpException(404, 'Campaign not found.');
                }

                $mitra = MitraProfile::where('id', $validated['mitra_profile_id'])->first();

                if (! $mitra || $mitra->status_verifikasi !== 'verified' || ! $mitra->is_active) {
                    throw new HttpException(403, 'Mitra is not verified or inactive.');
                }

                $needed = (int) $validated['stock'] * (int) $validated['rupiah_value'];
                $available = $campaign->availableAmount();

                if ($needed > $available) {
                    throw new HttpException(
                        422,
                        sprintf(
                            'Insufficient campaign funds. Needed %s, available %s.',
                            number_format($needed, 2, '.', ''),
                            number_format($available, 2, '.', '')
                        )
                    );
                }

                $voucher = Voucher::create([
                    'mitra_profile_id' => $mitra->id,
                    'title' => $validated['title'],
                    'description' => $validated['description'],
                    'category' => $validated['category'] ?? 'kuliner',
                    'image_url' => $validated['image_url'] ?? null,
                    'points_cost' => $validated['points_cost'],
                    'rupiah_value' => $validated['rupiah_value'],
                    'stock' => $validated['stock'],
                    'claimed_count' => 0,
                    'expired_at' => $validated['expired_at'],
                    'is_active' => true,
                ]);

                VoucherFundAllocation::create([
                    'voucher_id' => $voucher->id,
                    'campaign_id' => $campaign->id,
                    'amount' => $needed,
                    'allocated_by' => Auth::id(),
                ]);

                $campaign->increment('allocated_amount', $needed);

                return [
                    'voucher_id' => $voucher->id,
                    'campaign_id' => $campaign->id,
                    'allocated_amount' => number_format($needed, 2, '.', ''),
                    'campaign_available' => number_format($campaign->refresh()->availableAmount(), 2, '.', ''),
                ];
            });
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        }

        return response()->json([
            'success' => true,
            'message' => 'Voucher created and funded successfully.',
            'data' => $result,
        ], 201);
    }
}
