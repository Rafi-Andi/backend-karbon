<?php

namespace App\Http\Requests;

use App\Models\Voucher;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class UpdateMitraProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:2000'],
            'category' => ['sometimes', 'string', Rule::in(Voucher::CATEGORIES)],
            'image_url' => ['nullable', 'string', 'max:2048'],
            'rupiah_value' => ['sometimes', 'integer', 'min:1000', 'max:10000000'],
            'is_active' => ['sometimes', 'boolean'],
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
