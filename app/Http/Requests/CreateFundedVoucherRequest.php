<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateFundedVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'campaign_id' => ['required', 'integer', 'exists:donation_campaigns,id'],
            // Alur baru: admin pilih produk mitra (harga fix dari mitra).
            // points_cost TIDAK diterima agar tidak inflasi — dihitung
            // server via EcoRate::pointsForRupiah().
            'mitra_product_id' => ['required', 'integer', 'exists:mitra_products,id'],
            'stock' => ['required', 'integer', 'min:1', 'max:10000'],
            'expired_at' => ['required', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'campaign_id.exists' => 'Campaign dana tidak ditemukan.',
            'mitra_product_id.exists' => 'Produk mitra tidak ditemukan.',
            'expired_at.after' => 'Tanggal kedaluwarsa harus setelah hari ini.',
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
