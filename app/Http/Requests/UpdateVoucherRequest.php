<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PATCH /api/admin/vouchers/{id} — admin hanya boleh mengubah tenggat.
 *
 * Stok dikunci sejak voucher didanai: stok × harga produk = alokasi dana
 * campaign (lihat AdminDonationController::storeVoucher), sehingga ubah
 * stok manual akan membuat saldo dana campaign tidak sinkron. Payload
 * `stock` ditolak (prohibited) alih-alih diam-diam diabaikan — tambah
 * stok lewat pendanaan voucher baru.
 */
class UpdateVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expired_at' => ['required', 'date'],
            'stock' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            'expired_at.required' => 'Tenggat wajib diisi.',
            'expired_at.date' => 'Tenggat harus berupa tanggal yang valid.',
            'stock.prohibited' => 'Stok tidak bisa diubah manual karena terikat alokasi dana campaign.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'success' => false,
            'message' => 'Validation failed.',
            'errors' => $validator->errors(),
        ], 422));
    }
}
