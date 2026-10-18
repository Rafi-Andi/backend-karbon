<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * PATCH /api/admin/vouchers/{id} — admin hanya boleh mengubah stok
 * (sisa) dan tenggat; nominal & judul dikunci oleh produk mitra.
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
            'stock' => ['sometimes', 'integer', 'min:0', 'max:10000'],
            'expired_at' => ['sometimes', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (! $this->has('stock') && ! $this->has('expired_at')) {
                $validator->errors()->add(
                    'stock',
                    'Isi minimal satu field: stock atau expired_at.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'stock.integer' => 'Stok harus berupa angka.',
            'stock.min' => 'Stok tidak boleh negatif.',
            'stock.max' => 'Stok maksimal 10000.',
            'expired_at.date' => 'Tenggat harus berupa tanggal yang valid.',
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
