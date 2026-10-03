<?php

namespace App\Http\Requests;

use App\Models\Mission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class VerifyWasteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mission_id' => ['required', 'integer', 'exists:missions,id'],
            'image' => ['required', 'file', 'mimes:jpeg,png,jpg', 'max:5120'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('mission_id')) {
                return;
            }

            $mission = Mission::find($this->input('mission_id'));

            if (! $mission || $mission->category !== 'waste' || ! $mission->is_active) {
                $validator->errors()->add(
                    'mission_id',
                    'Mission must be an active waste mission.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'mission_id.required' => 'Mission ID is required.',
            'mission_id.integer' => 'Mission ID must be an integer.',
            'mission_id.exists' => 'Mission not found.',
            'image.required' => 'Proof image is required.',
            'image.file' => 'Proof must be a file.',
            'image.mimes' => 'Proof image must be a JPEG or PNG file.',
            'image.max' => 'Proof image may not be larger than 5 MB.',
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
