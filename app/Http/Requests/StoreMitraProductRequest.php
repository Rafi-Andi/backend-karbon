<?php

namespace App\Http\Requests;

use App\Models\Voucher;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreMitraProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) env('MITRA_MAX_FILE_KB', 5120);

        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', Rule::in(Voucher::CATEGORIES)],
            'image_url' => ['nullable', 'string', 'max:2048'],
            // Foto produk opsional (multipart). Bila ada, dipakai sebagai
            // foto produk dan otomatis jadi foto voucher saat didanai.
            'foto_produk' => ['nullable', 'file', 'mimes:jpeg,png,jpg', "max:{$maxKb}"],
            'rupiah_value' => ['required', 'integer', 'min:1000', 'max:10000000'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'foto_produk.mimes' => 'Foto produk harus jpeg/png/jpg.',
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
