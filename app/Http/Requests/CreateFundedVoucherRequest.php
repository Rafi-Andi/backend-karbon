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
            'mitra_profile_id' => ['required', 'integer', 'exists:mitra_profiles,id'],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', 'in:kuliner,sembako,fashion,jasa,donasi,transportasi'],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'points_cost' => ['required', 'integer', 'min:0'],
            'rupiah_value' => ['required', 'integer', 'min:1000'],
            'stock' => ['required', 'integer', 'min:1', 'max:10000'],
            'expired_at' => ['required', 'date', 'after:today'],
        ];
    }

    public function messages(): array
    {
        return [
            'campaign_id.exists' => 'Campaign dana tidak ditemukan.',
            'mitra_profile_id.exists' => 'Mitra tidak ditemukan.',
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
