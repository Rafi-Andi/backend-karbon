<?php

namespace App\Http\Controllers;

use App\Http\Requests\ClaimVoucherRequest;
use App\Http\Resources\VoucherClaimResource;
use App\Http\Resources\VoucherResource;
use App\Models\PointTransaction;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Models\WargaProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoucherController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'category' => 'sometimes|string|in:'.implode(',', Voucher::CATEGORIES),
        ]);

        $vouchers = Voucher::with('mitraProfile.user')
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->where('expired_at', '>=', now()->toDateString())
            ->when($request->get('category'), function ($query, $category) {
                $query->where('category', $category);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'success' => true,
            'message' => 'Vouchers retrieved successfully.',
            'data' => VoucherResource::collection($vouchers),
        ]);
    }

    public function claim(ClaimVoucherRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $userId = Auth::id();

        $result = DB::transaction(function () use ($validated, $userId) {
            $voucher = Voucher::where('id', $validated['voucher_id'])
                ->where('is_active', true)
                ->where('stock', '>', 0)
                ->where('expired_at', '>=', now()->toDateString())
                ->lockForUpdate()
                ->first();

            if (! $voucher) {
                throw new \Exception('Voucher not found, inactive, out of stock, or expired.');
            }

            $alreadyClaimed = VoucherClaim::where('user_id', $userId)
                ->where('voucher_id', $voucher->id)
                ->exists();

            if ($alreadyClaimed) {
                throw new \Exception('You have already claimed this voucher.');
            }

            $profile = WargaProfile::where('user_id', $userId)->lockForUpdate()->first();

            if (! $profile) {
                throw new \Exception('User profile not found.');
            }

            if ($profile->eco_points < $voucher->points_cost) {
                throw new \Exception('Insufficient eco points. You need '.$voucher->points_cost.' points.');
            }

            $qrToken = $this->generateUniqueToken();

            $claim = VoucherClaim::create([
                'user_id' => $userId,
                'voucher_id' => $voucher->id,
                'qr_token' => $qrToken,
                'status' => 'claimed',
                'claimed_at' => now(),
            ]);

            $voucher->decrement('stock');
            $voucher->increment('claimed_count');

            $newBalance = $profile->eco_points - $voucher->points_cost;
            $profile->update(['eco_points' => $newBalance]);

            PointTransaction::create([
                'user_id' => $userId,
                'type' => 'debit',
                'amount' => $voucher->points_cost,
                'balance_after' => $newBalance,
                'reference_type' => VoucherClaim::class,
                'reference_id' => $claim->id,
                'description' => 'Claimed voucher: '.$voucher->title,
            ]);

            return [
                'claim_id' => $claim->id,
                'qr_token' => $qrToken,
                'voucher_title' => $voucher->title,
                'points_cost' => $voucher->points_cost,
                'remaining_points' => $newBalance,
            ];
        });

        return response()->json([
            'success' => true,
            'message' => 'Voucher claimed successfully.',
            'data' => $result,
        ], 201);
    }

    public function myVouchers(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $claims = VoucherClaim::with(['voucher.mitraProfile.user'])
            ->where('user_id', $userId)
            ->get();

        $active = $claims->filter(function ($claim) {
            return $claim->status === 'claimed' &&
                   $claim->voucher &&
                   $claim->voucher->expired_at->isFuture();
        });

        $used = $claims->filter(fn ($claim) => $claim->status === 'used');

        $expired = $claims->filter(function ($claim) {
            return $claim->status === 'expired' ||
                   ($claim->status === 'claimed' && $claim->voucher && $claim->voucher->expired_at->isPast());
        });

        return response()->json([
            'success' => true,
            'message' => 'My vouchers retrieved successfully.',
            'data' => [
                'active' => VoucherClaimResource::collection($active->values()),
                'used' => VoucherClaimResource::collection($used->values()),
                'expired' => VoucherClaimResource::collection($expired->values()),
            ],
        ]);
    }

    private function generateUniqueToken(): string
    {
        do {
            $token = 'KBK-'.strtoupper(Str::random(3)).'-'.strtoupper(Str::random(3));
        } while (VoucherClaim::where('qr_token', $token)->exists());

        return $token;
    }
}
