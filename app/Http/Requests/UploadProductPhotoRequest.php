<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UploadProductPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKb = (int) env('MITRA_MAX_FILE_KB', 5120);

        return [
            'foto_produk' => ['required', 'file', 'mimes:jpeg,png,jpg', "max:{$maxKb}"],
        ];
    }

    public function messages(): array
    {
        return [
            'foto_produk.required' => 'Foto produk wajib diunggah.',
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
