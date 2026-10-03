<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateDonationRequest;
use App\Models\Donation;
use App\Models\DonationCampaign;
use App\Services\XenditInvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class DonationController extends Controller
{
    /**
     * GET /api/donation-campaigns — daftar campaign terbuka + progress.
     * Publik agar CSR/komunitas bisa melihat tanpa login.
     */
    public function campaigns(): JsonResponse
    {
        $campaigns = DonationCampaign::where('status', 'active')
            ->orderByDesc('id')
            ->get()
            ->filter(fn ($c) => $c->isOpen())
            ->values();

        return response()->json([
            'success' => true,
            'message' => 'Donation campaigns retrieved successfully.',
            'data' => $campaigns->map(fn ($c) => $this->campaignSummary($c))->values(),
        ]);
    }

    /**
     * GET /api/donation-campaigns/{slug} — detail + leaderboard donatur.
     * Publik. Nama donatur ditampilkan apa adanya (ditulis saat donasi).
     */
    public function campaignDetail(string $slug): JsonResponse
    {
        $campaign = DonationCampaign::where('slug', $slug)->first();

        if (! $campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign not found.',
            ], 404);
        }

        $donorCount = Donation::where('campaign_id', $campaign->id)
            ->where('status', 'paid')
            ->distinct('user_id')
            ->count('user_id');

        $topDonors = Donation::where('campaign_id', $campaign->id)
            ->where('status', 'paid')
            ->selectRaw('user_id, MAX(payer_name) as payer_name, SUM(amount) as total')
            ->groupBy('user_id')
            ->orderByDesc('total')
            ->limit(5)
            ->get()
            ->map(fn ($row) => [
                'payer_name' => $row->payer_name ?? 'Donatur',
                'total' => number_format((float) $row->total, 2, '.', ''),
                'tier' => Donation::tierForTotal((float) $row->total),
            ])->values();

        $recent = Donation::where('campaign_id', $campaign->id)
            ->where('status', 'paid')
            ->orderByDesc('paid_at')
            ->limit(10)
            ->get(['payer_name', 'amount', 'paid_at'])
            ->map(fn ($d) => [
                'payer_name' => $d->payer_name ?? 'Donatur',
                'amount' => number_format((float) $d->amount, 2, '.', ''),
                'paid_at' => $d->paid_at?->format('Y-m-d\TH:i:s'),
            ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Campaign detail retrieved successfully.',
            'data' => array_merge($this->campaignSummary($campaign), [
                'description' => $campaign->description,
                'donor_count' => $donorCount,
                'top_donors' => $topDonors,
                'recent_donations' => $recent,
            ]),
        ]);
    }

    /**
     * POST /api/donations — buat donasi + invoice Xendit.
     * Nominal diambil dari request (min DONATION_MIN_AMOUNT=10000),
     * invoice kedaluwarsa invoice_duration (default 24 jam).
     */
    public function store(CreateDonationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = Auth::user();

        $campaign = DonationCampaign::where('id', $validated['campaign_id'])->first();

        if (! $campaign || ! $campaign->isOpen()) {
            return response()->json([
                'success' => false,
                'message' => 'Campaign is not open for donations.',
            ], 422);
        }

        $invoice = XenditInvoiceService::fromConfig();
        $externalId = $invoice->buildExternalId();
        $amount = (int) $validated['amount'];

        $donation = Donation::create([
            'user_id' => $user->id,
            'campaign_id' => $campaign->id,
            'external_id' => $externalId,
            'amount' => $amount,
            'status' => 'pending',
            'payer_name' => $validated['payer_name'] ?? $user->name,
            'payer_email' => $user->email,
        ]);

        try {
            $payload = [
                'external_id' => $externalId,
                'amount' => $amount,
                'description' => mb_substr("Donasi {$campaign->title} - KarbonKita", 0, 255),
                'payer_email' => $user->email,
                'currency' => 'IDR',
                'invoice_duration' => $invoice->getDuration(),
            ];

            if (config('services.xendit.success_redirect_url')) {
                $payload['success_redirect_url'] = config('services.xendit.success_redirect_url');
            }
            if (config('services.xendit.failure_redirect_url')) {
                $payload['failure_redirect_url'] = config('services.xendit.failure_redirect_url');
            }

            $result = $invoice->createInvoice($payload);
        } catch (RuntimeException $e) {
            $donation->update(['status' => 'failed', 'response_log' => ['error' => $e->getMessage()]]);
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment. Please retry.',
            ], 502);
        }

        if (empty($result['invoice_id']) || empty($result['invoice_url'])) {
            $donation->update(['status' => 'failed', 'response_log' => $result['raw'] ?? []]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to create payment. Please retry.',
            ], 502);
        }

        $donation->update([
            'xendit_invoice_id' => $result['invoice_id'],
            'expires_at' => $result['expiry'] ?? now()->addSeconds($invoice->getDuration()),
            'response_log' => array_merge($result['raw'] ?? [], ['invoice_url' => $result['invoice_url']]),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Donation created. Complete your payment before it expires.',
            'data' => [
                'donation_id' => $donation->id,
                'external_id' => $externalId,
                'amount' => number_format($amount, 2, '.', ''),
                'invoice_url' => $result['invoice_url'],
                'expires_at' => $donation->refresh()->expires_at?->format('Y-m-d\TH:i:s'),
            ],
        ], 201);
    }

    /**
     * GET /api/user/my-donations — riwayat donasi sendiri + tier badge.
     */
    public function myDonations(): JsonResponse
    {
        $userId = Auth::id();

        $donations = Donation::with('campaign:id,title,slug')
            ->where('user_id', $userId)
            ->orderByDesc('id')
            ->get()
            ->map(fn ($d) => [
                'id' => $d->id,
                'campaign' => $d->campaign ? ['id' => $d->campaign->id, 'title' => $d->campaign->title, 'slug' => $d->campaign->slug] : null,
                'amount' => number_format((float) $d->amount, 2, '.', ''),
                'status' => $d->status,
                'invoice_url' => $d->status === 'pending' && $d->xendit_invoice_id
                    ? ($d->response_log['invoice_url'] ?? null)
                    : null,
                'paid_at' => $d->paid_at?->format('Y-m-d\TH:i:s'),
                'payment_channel' => $d->payment_channel,
            ])->values();

        $totalPaid = (float) Donation::where('user_id', $userId)->where('status', 'paid')->sum('amount');

        return response()->json([
            'success' => true,
            'message' => 'My donations retrieved successfully.',
            'data' => [
                'donations' => $donations,
                'lifetime_total' => number_format($totalPaid, 2, '.', ''),
                'tier' => Donation::tierForTotal($totalPaid),
            ],
        ]);
    }

    /**
     * POST /api/donations/{id}/cancel — batalkan donasi pending sendiri.
     * Invoice di-expire ke Xendit agar tidak bisa dibayar lagi.
     */
    public function cancel(int $id): JsonResponse
    {
        $donation = Donation::where('id', $id)->where('user_id', Auth::id())->first();

        if (! $donation) {
            return response()->json([
                'success' => false,
                'message' => 'Donation not found.',
            ], 404);
        }

        if ($donation->status !== 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Only pending donations can be cancelled.',
            ], 409);
        }

        try {
            if ($donation->xendit_invoice_id) {
                XenditInvoiceService::fromConfig()->expireInvoice($donation->xendit_invoice_id);
            }
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel payment. Please retry.',
            ], 502);
        }

        $donation->update(['status' => 'expired']);

        return response()->json([
            'success' => true,
            'message' => 'Donation cancelled successfully.',
            'data' => ['id' => $donation->id, 'status' => 'expired'],
        ]);
    }

    private function campaignSummary(DonationCampaign $campaign): array
    {
        return [
            'id' => $campaign->id,
            'title' => $campaign->title,
            'slug' => $campaign->slug,
            'image_url' => $campaign->image_url,
            'target_amount' => number_format((float) $campaign->target_amount, 2, '.', ''),
            'collected_amount' => number_format((float) $campaign->collected_amount, 2, '.', ''),
            'available_amount' => number_format($campaign->availableAmount(), 2, '.', ''),
            'progress_percent' => $campaign->progressPercent(),
            'status' => $campaign->status,
            'is_open' => $campaign->isOpen(),
            'ended_at' => $campaign->ended_at?->format('Y-m-d\TH:i:s'),
        ];
    }
}
