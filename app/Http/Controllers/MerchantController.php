<?php

namespace App\Http\Controllers;

use App\Http\Requests\MerchantStatusRequest;
use App\Http\Requests\RedeemVoucherRequest;
use App\Models\Disbursement;
use App\Models\MitraProfile;
use App\Models\Voucher;
use App\Models\VoucherClaim;
use App\Services\XenditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

class MerchantController extends Controller
{
    /**
     * GET /api/merchant/dashboard — saldo rupiah, status Buka/Tutup,
     * statistik voucher & riwayat pencairan milik toko sendiri.
     *
     * Mapping kontrak ↔ DB: is_active ↔ is_open, balance ↔ saldo,
     * nama_usaha ↔ store_name. Mitra pending tetap 200 dengan
     * can_redeem=false agar kasir tahu penyebabnya.
     */
    public function dashboard(): JsonResponse
    {
        $user = Auth::user()->load('mitraProfile');
        $mitra = $user->mitraProfile;

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $today = now()->toDateString();

        $totalVouchers = Voucher::where('mitra_profile_id', $mitra->id)->count();
        $activeVouchers = Voucher::where('mitra_profile_id', $mitra->id)
            ->where('is_active', true)
            ->where('stock', '>', 0)
            ->where('expired_at', '>=', $today)
            ->count();

        $totalRedeemed = VoucherClaim::where('status', 'used')
            ->whereHas('voucher', fn ($q) => $q->where('mitra_profile_id', $mitra->id))
            ->count();

        $totalDisbursed = (string) Disbursement::where('mitra_profile_id', $mitra->id)
            ->where('status', 'completed')
            ->sum('amount');

        $recent = Disbursement::where('mitra_profile_id', $mitra->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'voucher_claim_id', 'amount', 'status', 'raw_status', 'payout_id', 'reference_id', 'xendit_disbursement_id', 'failure_code', 'created_at']);

        $isOpen = (bool) $mitra->is_active;

        return response()->json([
            'success' => true,
            'message' => 'Merchant dashboard retrieved successfully.',
            'data' => [
                'store_name' => $mitra->nama_usaha,
                'verification_status' => $mitra->status_verifikasi,
                'is_open' => $isOpen,
                'can_redeem' => $mitra->status_verifikasi === 'verified' && $isOpen,
                'balance' => number_format((float) $mitra->balance, 2, '.', ''),
                'stats' => [
                    'total_vouchers' => $totalVouchers,
                    'active_vouchers' => $activeVouchers,
                    'total_redeemed' => $totalRedeemed,
                    'total_disbursed' => number_format((float) $totalDisbursed, 2, '.', ''),
                ],
                'recent_disbursements' => $recent->map(fn ($d) => [
                    'id' => $d->id,
                    'voucher_claim_id' => $d->voucher_claim_id,
                    'amount' => number_format((float) $d->amount, 2, '.', ''),
                    'status' => $d->status,
                    'raw_status' => $d->raw_status,
                    'payout_id' => $d->payout_id ?? $d->xendit_disbursement_id,
                    'reference_id' => $d->reference_id,
                    'xendit_disbursement_id' => $d->xendit_disbursement_id,
                    'failure_code' => $d->failure_code,
                    'processed_at' => $d->created_at?->format('Y-m-d\TH:i:s'),
                ])->values(),
            ],
        ]);
    }

    /**
     * GET /api/merchant/disbursements — riwayat pencairan dana toko sendiri.
     * Paginated + filter `?status=completed|pending|failed|...`.
     * Eager load claim→user/voucher agar halaman riwayat bisa menampilkan
     * nama warga & judul voucher tanpa N+1.
     */
    public function disbursements(Request $request): JsonResponse
    {
        $user = Auth::user()->load('mitraProfile');
        $mitra = $user->mitraProfile;

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $status = (string) $request->query('status', 'all');
        $allowed = ['all', 'completed', 'pending', 'failed', 'reversed', 'rejected', 'cancelled', 'expired'];

        if (! in_array($status, $allowed, true)) {
            return response()->json([
                'success' => false,
                'message' => 'Validation failed.',
                'errors' => ['status' => ['Status riwayat tidak dikenali.']],
            ], 422);
        }

        $query = Disbursement::with([
            'voucherClaim:id,user_id,voucher_id,qr_token,status,used_at',
            'voucherClaim.user:id,name,rt,rw',
            'voucherClaim.voucher:id,title,rupiah_value',
        ])->where('mitra_profile_id', $mitra->id);

        if ($status !== 'all') {
            $query->where('status', $status);
        }

        $paginated = $query->orderByDesc('id')->paginate(15);

        $items = collect($paginated->items())->map(fn ($d) => [
            'id' => $d->id,
            'voucher_claim_id' => $d->voucher_claim_id,
            'amount' => number_format((float) $d->amount, 2, '.', ''),
            'status' => $d->status,
            'raw_status' => $d->raw_status,
            'payout_id' => $d->payout_id ?? $d->xendit_disbursement_id,
            'reference_id' => $d->reference_id,
            'bank_name' => $d->bank_name,
            'bank_account_number' => $d->bank_account_number,
            'bank_account_name' => $d->bank_account_name,
            'failure_code' => $d->failure_code,
            'failure_reason' => $d->failure_reason,
            'processed_at' => $d->created_at?->format('Y-m-d\TH:i:s'),
            'warga_name' => $d->voucherClaim?->user?->name,
            'warga_rt' => $d->voucherClaim?->user?->rt,
            'warga_rw' => $d->voucherClaim?->user?->rw,
            'voucher_title' => $d->voucherClaim?->voucher?->title,
        ])->values();

        return response()->json([
            'success' => true,
            'message' => 'Disbursements retrieved successfully.',
            'data' => $items,
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'per_page' => $paginated->perPage(),
                'total' => $paginated->total(),
            ],
        ]);
    }

    /**
     * PATCH /api/merchant/status — toggle Buka/Tutup toko sendiri.
     * Ekstra di luar kontrak (kontrak hanya menampilkan status), lihat D9.
     */
    public function updateStatus(MerchantStatusRequest $request): JsonResponse
    {
        $user = Auth::user()->load('mitraProfile');
        $mitra = $user->mitraProfile;

        if (! $mitra) {
            return response()->json([
                'success' => false,
                'message' => 'Merchant profile not found.',
            ], 403);
        }

        $mitra->update(['is_active' => (bool) $request->validated()['is_open']]);

        return response()->json([
            'success' => true,
            'message' => 'Store status updated successfully.',
            'data' => [
                'store_name' => $mitra->nama_usaha,
                'is_open' => (bool) $mitra->is_active,
            ],
        ]);
    }

    /**
     * POST /api/vouchers/redeem — kasir scan QR warga lalu cairkan rupiah
     * via Xendit Payout API v3.
     *
     * Kontrak terima `unique_code`, kolom DB-nya `qr_token`; status DB
     * `claimed` = `unused` kontrak. Nominal diambil dari
     * `vouchers.rupiah_value` (tidak pernah dari client).
     * Seluruh mutasi dalam DB::transaction + lockForUpdate; Xendit gagal
     * → exception → rollback total, claim tetap `claimed`.
     * reference_id + idempotency_key pakai claim id (deterministic, anti-double-payout).
     */
    public function redeem(RedeemVoucherRequest $request): JsonResponse
    {
        $uniqueCode = $request->validated()['unique_code'];
        $userId = Auth::id();

        try {
            $result = DB::transaction(function () use ($uniqueCode, $userId) {
                $mitra = MitraProfile::where('user_id', $userId)
                    ->lockForUpdate()
                    ->first();

                if (! $mitra) {
                    throw new HttpException(403, 'Merchant profile not found.');
                }

                if ($mitra->status_verifikasi !== 'verified' || ! $mitra->is_active) {
                    throw new HttpException(
                        403,
                        'Merchant not verified or inactive. Redemption is not allowed.'
                    );
                }

                $claim = VoucherClaim::where('qr_token', $uniqueCode)
                    ->lockForUpdate()
                    ->first();

                if (! $claim) {
                    throw new HttpException(404, 'Voucher code not found.');
                }

                $claim->load('voucher');
                $voucher = $claim->voucher;

                if (! $voucher) {
                    throw new HttpException(404, 'Voucher not found.');
                }

                if ($claim->status === 'used') {
                    throw new HttpException(409, 'Voucher has already been redeemed.');
                }

                if (
                    $claim->status === 'expired'
                    || ! $voucher->is_active
                    || ! $voucher->expired_at
                    || $voucher->expired_at->isPast()
                ) {
                    throw new HttpException(422, 'Voucher is expired or inactive.');
                }

                if ((int) $voucher->mitra_profile_id !== (int) $mitra->id) {
                    throw new HttpException(
                        403,
                        'This voucher belongs to another merchant.'
                    );
                }

                $xendit = XenditService::fromConfig();

                // Stable identifiers: retrying the same claim must not create
                // a different logical payout.
                $referenceId = $xendit->buildReferenceId((int) $claim->id);
                $idempotencyKey = 'KBK-CLAIM-'.$claim->id;

                $amount = (int) round((float) $voucher->rupiah_value);

                if ($amount <= 0) {
                    throw new HttpException(422, 'Voucher amount must be greater than zero.');
                }

                $accountHolderName = trim((string) $mitra->nama_pemilik_rekening);
                $accountNumber = preg_replace(
                    '/\s+/',
                    '',
                    trim((string) $mitra->nomor_rekening)
                ) ?? '';

                if ($accountHolderName === '') {
                    throw new HttpException(422, 'Merchant bank account holder name is required.');
                }

                if ($accountNumber === '') {
                    throw new HttpException(422, 'Merchant bank account number is required.');
                }

                $bankName = trim((string) $mitra->nama_bank);

                if ($bankName === '') {
                    throw new HttpException(422, 'Merchant bank name is required.');
                }

                try {
                    $routing = $xendit->mapBankRouting($bankName);
                    $xendit->validateAccountNumber($bankName, $accountNumber);
                } catch (RuntimeException $e) {
                    // Unsupported bank / bad format is a client configuration error, not a gateway failure
                    throw new HttpException(422, $e->getMessage());
                }

                /*
                 * Current KarbonKita model stores the bank account holder as a
                 * single name field. Treat it as an INDIVIDUAL recipient.
                 *
                 * Xendit requires given_name + surname for INDIVIDUAL.
                 */
                $nameParts = preg_split(
                    '/\s+/u',
                    $accountHolderName,
                    -1,
                    PREG_SPLIT_NO_EMPTY
                ) ?: [];

                $givenName = array_shift($nameParts) ?: $accountHolderName;
                $surname = trim(implode(' ', $nameParts));

                if ($surname === '') {
                    // Xendit requires surname for INDIVIDUAL.
                    $surname = $givenName;
                }

                $city = trim((string) ($mitra->user->kota ?? ''));
                if ($city === '') {
                    $city = 'Surabaya';
                }

                $street = trim((string) ($mitra->alamat_usaha ?? ''));
                if ($street === '') {
                    $street = 'Jl. KarbonKita No 1 Surabaya';
                }

                $payoutPayload = [
                    'reference_id' => $referenceId,

                    'recipient' => [
                        'type' => 'INDIVIDUAL',
                        'given_name' => mb_substr($givenName, 0, 50),
                        'surname' => mb_substr($surname, 0, 50),
                        'relationship' => 'BUSINESS_PARTNER',

                        'address' => [
                            'country' => 'ID',
                            'city' => mb_substr($city, 0, 255),
                            'street_line_1' => mb_substr($street, 0, 255),
                            'province_state' => mb_substr($city, 0, 255),
                            'postal_code' => '60111',
                        ],

                        'account_details' => [
                            'currency' => 'IDR',
                            'account_country' => 'ID',
                            'account_holder_name' => mb_substr($accountHolderName, 0, 255),
                            'account_number' => $accountNumber,
                            'routing_type_1' => $routing['routing_type'],
                            'routing_value_1' => $routing['routing_value'],
                            'account_type' => 'SAVINGS',
                        ],
                    ],

                    'payout_details' => [
                        'source_currency' => 'IDR',
                        'source_amount' => $amount,
                        'destination_currency' => 'IDR',
                    ],

                    'source_of_fund' => 'BUSINESS_REVENUE',
                    'purpose_code' => 'OTHER',
                    'description' => 'KarbonKita redeem '.$claim->qr_token,
                ];

                /*
                 * Idempotency key is deliberately NOT inside the JSON body.
                 * XenditService sends it as the idempotency-key HTTP header.
                 */
                $xenditResult = $xendit->createPayout(
                    $payoutPayload,
                    $idempotencyKey
                );

                Log::info('Xendit payout created', [
                    'reference_id' => $referenceId,
                    'payout_id' => $xenditResult['payout_id'] ?? null,
                    'status' => $xenditResult['status'] ?? null,
                    'raw_status' => $xenditResult['raw_status'] ?? null,
                    'amount' => $xenditResult['amount'] ?? $amount,
                ]);

                if (empty($xenditResult['payout_id'])) {
                    throw new RuntimeException(
                        'Xendit accepted the request but did not return a payout_id.'
                    );
                }

                $status = in_array(
                    $xenditResult['status'] ?? null,
                    ['pending', 'completed', 'failed', 'reversed', 'rejected', 'cancelled', 'expired'],
                    true
                )
                    ? $xenditResult['status']
                    : 'pending';

                /*
                 * Keep the existing application behaviour: once Xendit has
                 * accepted/created the payout, the voucher is consumed so a
                 * second redeem request cannot create another payout.
                 *
                 * Final payout success/failure should be reconciled from the
                 * Xendit Payout v3 webhook.
                 */
                $claim->update([
                    'status' => 'used',
                    'used_at' => now(),
                ]);

                $disbursement = Disbursement::create([
                    'mitra_profile_id' => $mitra->id,
                    'voucher_claim_id' => $claim->id,
                    'xendit_disbursement_id' => $xenditResult['payout_id'],
                    'payout_id' => $xenditResult['payout_id'],
                    'reference_id' => $referenceId,
                    'amount' => $amount,
                    'bank_name' => $bankName,
                    'bank_account_number' => $accountNumber,
                    'bank_account_name' => $accountHolderName,
                    'status' => $status,
                    'raw_status' => $xenditResult['raw_status'] ?? null,
                    'currency' => $xenditResult['currency'] ?? 'IDR',
                    'destination_amount' => $xenditResult['destination_amount'] ?? $amount,
                    'destination_currency' => $xenditResult['destination_currency'] ?? 'IDR',
                    'failure_code' => $xenditResult['failure_code'] ?? null,
                    'estimated_arrival_time' => $xenditResult['estimated_arrival_time'] ?? null,
                    'response_log' => $xenditResult['raw'] ?? [],
                ]);

                /*
                 * Only preserve the old immediate balance increment for a payout
                 * that is already SUCCEEDED (normally mock mode). For real Payout
                 * v3, ACCEPTED is pending and the webhook should perform the
                 * final accounting update.
                 */
                if ($status === 'completed') {
                    $mitra->increment('balance', $amount);
                }

                $mitra->refresh();

                return [
                    'claim_id' => $claim->id,
                    'qr_token' => $claim->qr_token,
                    'voucher_title' => $voucher->title,
                    'amount' => number_format($amount, 2, '.', ''),
                    'new_balance' => number_format((float) $mitra->balance, 2, '.', ''),
                    'xendit_payout_id' => $xenditResult['payout_id'],
                    'reference_id' => $referenceId,
                    'disbursement_status' => $status,
                    'disbursement_id' => $disbursement->id,
                ];
            });
        } catch (HttpException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], $e->getStatusCode());
        } catch (RuntimeException $e) {
            report($e);

            return response()->json([
                'success' => false,
                'message' => 'Disbursement failed. Please retry.',
                'error' => $e->getMessage(),
                'debug' => app()->environment('local')
                    ? [
                        'exception_class' => get_class($e),
                        'file' => $e->getFile().':'.$e->getLine(),
                        'trace_head' => collect($e->getTrace())
                            ->take(3)
                            ->map(
                                fn ($t) => ($t['file'] ?? '')
                                    .':'.($t['line'] ?? '')
                                    .' '.($t['function'] ?? '')
                            )
                            ->values(),
                    ]
                    : null,
            ], 502);
        }

        return response()->json([
            'success' => true,
            'message' => 'Voucher redeemed and payout initiated.',
            'data' => $result,
        ]);
    }
}
