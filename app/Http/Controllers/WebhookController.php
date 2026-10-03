<?php

namespace App\Http\Controllers;

use App\Models\Disbursement;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Models\WargaProfile;
use App\Services\LevelService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    public function payout(Request $request): JsonResponse
    {
        $signature = $request->header('x-callback-token');

        if (! $this->verifySignature($signature)) {
            Log::warning('Xendit webhook signature verification failed', [
                'ip' => $request->ip(),
                'signature_present' => (bool) $signature,
            ]);

            return response()->json(['success' => false, 'message' => 'Unauthorized webhook'], 401);
        }

        $payload = $request->json()->all();
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];

        if (! $event) {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload'], 400);
        }

        $allowedEvents = [
            'v3_payout.succeeded',
            'v3_payout.failed',
            'v3_payout.reversed',
            'v3_payout.rejected',
            'v3_payout.pending_compliance',
        ];

        if (! in_array($event, $allowedEvents, true)) {
            Log::info('Xendit webhook event not handled', ['event' => $event]);

            return response()->json(null, 204);
        }

        $payoutId = $data['payout_id'] ?? null;
        $referenceId = $data['reference_id'] ?? null;

        $statusMap = [
            'v3_payout.succeeded' => 'completed',
            'v3_payout.failed' => 'failed',
            'v3_payout.reversed' => 'reversed',
            'v3_payout.rejected' => 'rejected',
            'v3_payout.pending_compliance' => 'pending',
        ];

        $newStatus = $statusMap[$event] ?? 'pending';
        $failureCode = $data['failure_code'] ?? null;

        return DB::transaction(function () use ($payoutId, $referenceId, $event, $data, $newStatus, $failureCode) {
            $disbursement = Disbursement::where(function ($q) use ($payoutId, $referenceId) {
                if ($payoutId) {
                    $q->orWhere('payout_id', $payoutId)
                        ->orWhere('xendit_disbursement_id', $payoutId);
                }
                if ($referenceId) {
                    $q->orWhere('reference_id', $referenceId);
                }
            })->lockForUpdate()->first();

            if (! $disbursement) {
                Log::warning('Xendit webhook received for unknown payout', [
                    'payout_id' => $payoutId,
                    'reference_id' => $referenceId,
                ]);

                return response()->json(['success' => false, 'message' => 'Payout not found'], 404);
            }

            // Idempotency: already in target status — no-op
            if ($disbursement->status === $newStatus) {
                Log::info('Xendit webhook skipped — already in target status', [
                    'payout_id' => $payoutId,
                    'reference_id' => $referenceId,
                    'status' => $newStatus,
                ]);

                return response()->json(null, 204);
            }

            // Prevent double-credit: completed is terminal for credit; duplicate succeeded should be ignored
            if ($disbursement->status === 'completed' && $newStatus === 'completed') {
                return response()->json(null, 204);
            }

            $previousStatus = $disbursement->status;

            $disbursement->update([
                'status' => $newStatus,
                'raw_status' => $data['status'] ?? null,
                'failure_code' => $failureCode,
                'payout_id' => $data['payout_id'] ?? $disbursement->payout_id,
                'xendit_disbursement_id' => $data['payout_id'] ?? $disbursement->xendit_disbursement_id,
                'response_log' => array_merge(
                    $disbursement->response_log ?? [],
                    ['webhook_event' => $event, 'data' => $data, 'received_at' => now()->toISOString()]
                ),
            ]);

            // Balance accounting:
            // - pending -> completed : credit mitra
            // - completed -> reversed : debit mitra (bounce back, funds returned to platform)
            // - other transitions: no balance change
            if ($previousStatus !== 'completed' && $newStatus === 'completed') {
                DB::table('mitra_profiles')
                    ->where('id', $disbursement->mitra_profile_id)
                    ->increment('balance', (float) $disbursement->amount);
                Log::info('Xendit payout succeeded — mitra balance credited', [
                    'payout_id' => $payoutId,
                    'reference_id' => $referenceId,
                    'amount' => $disbursement->amount,
                    'mitra_profile_id' => $disbursement->mitra_profile_id,
                    'new_balance' => DB::table('mitra_profiles')->where('id', $disbursement->mitra_profile_id)->value('balance'),
                ]);
            } elseif ($previousStatus === 'completed' && $newStatus === 'reversed') {
                DB::table('mitra_profiles')
                    ->where('id', $disbursement->mitra_profile_id)
                    ->decrement('balance', (float) $disbursement->amount);
                Log::info('Xendit payout reversed — mitra balance debited', [
                    'payout_id' => $payoutId,
                    'reference_id' => $referenceId,
                    'amount' => $disbursement->amount,
                    'mitra_profile_id' => $disbursement->mitra_profile_id,
                    'new_balance' => DB::table('mitra_profiles')->where('id', $disbursement->mitra_profile_id)->value('balance'),
                ]);
            }

            return response()->json(null, 204);
        });
    }

    /**
     * POST /api/webhooks/xendit/invoice — callback Invoice API v2.
     * Payload FLAT (bukan envelope event/data seperti payout):
     * {id, external_id, status: PAID|SETTLED|EXPIRED|PENDING,
     *  paid_amount, payment_channel, paid_at, ...}.
     * Header auth sama: x-callback-token (plain compare).
     *
     * PAID -> donation paid + campaign.collected += amount + XP donatur
     * (tanpa eco_points, anti pay-to-win). EXPIRED -> donation expired.
     * Idempoten: status sama / sudah paid -> 204 no-op.
     */
    public function invoice(Request $request): JsonResponse
    {
        $signature = $request->header('x-callback-token');

        if (! $this->verifySignature($signature)) {
            Log::warning('Xendit invoice webhook signature verification failed', [
                'ip' => $request->ip(),
                'signature_present' => (bool) $signature,
            ]);

            return response()->json(['success' => false, 'message' => 'Unauthorized webhook'], 401);
        }

        $payload = $request->json()->all();
        $rawStatus = strtoupper((string) ($payload['status'] ?? ''));

        if ($rawStatus === '') {
            return response()->json(['success' => false, 'message' => 'Invalid webhook payload'], 400);
        }

        $newStatus = match ($rawStatus) {
            'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            default => null,
        };

        if ($newStatus === null) {
            Log::info('Xendit invoice webhook event not handled', ['status' => $rawStatus]);

            return response()->json(null, 204);
        }

        $invoiceId = $payload['id'] ?? null;
        $externalId = $payload['external_id'] ?? null;

        return DB::transaction(function () use ($invoiceId, $externalId, $payload, $newStatus, $rawStatus) {
            $donation = Donation::where(function ($q) use ($invoiceId, $externalId) {
                if ($invoiceId) {
                    $q->orWhere('xendit_invoice_id', $invoiceId);
                }
                if ($externalId) {
                    $q->orWhere('external_id', $externalId);
                }
            })->lockForUpdate()->first();

            if (! $donation) {
                Log::warning('Xendit invoice webhook received for unknown donation', [
                    'invoice_id' => $invoiceId,
                    'external_id' => $externalId,
                ]);

                return response()->json(['success' => false, 'message' => 'Donation not found'], 404);
            }

            if ($donation->status === $newStatus) {
                Log::info('Xendit invoice webhook skipped — already in target status', [
                    'invoice_id' => $invoiceId,
                    'external_id' => $externalId,
                    'status' => $newStatus,
                ]);

                return response()->json(null, 204);
            }

            // Paid is terminal: late EXPIRED after PAID must not revert.
            if ($donation->status === 'paid') {
                return response()->json(null, 204);
            }

            $paidAmount = isset($payload['paid_amount']) ? (float) $payload['paid_amount'] : null;

            if ($newStatus === 'paid' && $paidAmount !== null && $paidAmount < (float) $donation->amount) {
                Log::warning('Xendit invoice paid_amount less than donation amount', [
                    'invoice_id' => $invoiceId,
                    'external_id' => $externalId,
                    'expected' => $donation->amount,
                    'paid_amount' => $paidAmount,
                ]);
            }

            $donation->update([
                'status' => $newStatus,
                'payment_channel' => $payload['payment_channel'] ?? $donation->payment_channel,
                'paid_at' => $newStatus === 'paid' ? ($payload['paid_at'] ?? now()) : $donation->paid_at,
                'xendit_invoice_id' => $invoiceId ?? $donation->xendit_invoice_id,
                'response_log' => array_merge(
                    $donation->response_log ?? [],
                    ['webhook_status' => $rawStatus, 'data' => $payload, 'received_at' => now()->toISOString()]
                ),
            ]);

            $xpEarned = 0;

            if ($newStatus === 'paid') {
                $campaign = DonationCampaign::where('id', $donation->campaign_id)->lockForUpdate()->first();

                if ($campaign) {
                    $campaign->increment('collected_amount', (float) $donation->amount);
                }

                // Reward XP (tanpa eco_points). Hanya untuk warga.
                $profile = WargaProfile::where('user_id', $donation->user_id)->lockForUpdate()->first();

                if ($profile) {
                    $xpEarned = Donation::xpForAmount((float) $donation->amount);
                    $newXp = $profile->xp + $xpEarned;
                    $profile->update([
                        'xp' => $newXp,
                        'level' => LevelService::resolveLevel($newXp),
                    ]);
                }

                Log::info('Xendit invoice paid — campaign collected updated', [
                    'invoice_id' => $invoiceId,
                    'external_id' => $externalId,
                    'amount' => $donation->amount,
                    'campaign_id' => $donation->campaign_id,
                    'xp_earned' => $xpEarned,
                ]);
            }

            return response()->json(null, 204);
        });
    }

    protected function verifySignature(?string $signature): bool
    {
        $expected = config('services.xendit.callback_token');

        // Xendit sends plain token in header x-callback-token.
        // Docs: compare directly, constant-time.
        if (! $signature || ! $expected) {
            return false;
        }

        return hash_equals((string) $expected, (string) $signature);
    }
}
