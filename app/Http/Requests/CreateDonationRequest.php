<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CreateDonationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $min = (int) env('DONATION_MIN_AMOUNT', 10000);

        return [
            'campaign_id' => ['required', 'integer', 'exists:donation_campaigns,id'],
            'amount' => ['required', 'integer', "min:{$min}", 'max:1000000000'],
            'payer_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        $min = number_format((int) env('DONATION_MIN_AMOUNT', 10000), 0, ',', '.');

        return [
            'campaign_id.exists' => 'Campaign donasi tidak ditemukan.',
            'amount.min' => "Nominal donasi minimal Rp{$min}.",
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
