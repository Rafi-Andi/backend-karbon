<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreMissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', 'string', 'in:mobility,waste'],
            'xp_reward' => ['nullable', 'integer', 'min:0'],
            'points_reward' => ['nullable', 'integer', 'min:0'],
            'icon' => ['nullable', 'string', 'max:100'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['nullable', 'boolean'],
            // Khusus mobility: aktivitas pengikat + target jarak wajib.
            'activity_type' => ['required_if:category,mobility', 'prohibited_if:category,waste', 'string', 'in:walking,running,cycling'],
            'target_distance_km' => ['required_if:category,mobility', 'prohibited_if:category,waste', 'numeric', 'min:0.1', 'max:500'],
            // Khusus waste: kriteria foto untuk AI wajib.
            'validation_prompt' => ['required_if:category,waste', 'prohibited_if:category,mobility', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.in' => 'Category hanya boleh mobility atau waste.',
            'activity_type.required_if' => 'Activity type wajib untuk misi mobility.',
            'activity_type.prohibited_if' => 'Activity type hanya untuk misi mobility.',
            'activity_type.in' => 'Activity type harus walking, running, atau cycling.',
            'target_distance_km.required_if' => 'Target jarak wajib untuk misi mobility.',
            'target_distance_km.prohibited_if' => 'Target jarak hanya untuk misi mobility.',
            'target_distance_km.min' => 'Target jarak minimal 0.1 km.',
            'validation_prompt.required_if' => 'Validation prompt wajib untuk misi waste.',
            'validation_prompt.prohibited_if' => 'Validation prompt hanya untuk misi waste.',
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
