<?php

namespace App\Http\Requests;

use App\Models\Mission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateMissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:5000'],
            'xp_reward' => ['sometimes', 'integer', 'min:0'],
            'points_reward' => ['sometimes', 'integer', 'min:0'],
            'icon' => ['nullable', 'string', 'max:100'],
            'max_participants' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'activity_type' => ['sometimes', 'string', 'in:walking,running,cycling'],
            'target_distance_km' => ['sometimes', 'numeric', 'min:0.1', 'max:500'],
            'validation_prompt' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // Kategori tidak boleh diubah: beda kategori = beda kolom wajib
            // + riwayat user_missions yang sudah berjalan.
            if ($this->has('category')) {
                $validator->errors()->add(
                    'category',
                    'Category tidak dapat diubah setelah misi dibuat.'
                );

                return;
            }

            $mission = Mission::find($this->route('id'));

            if (! $mission || ! in_array($mission->category, ['mobility', 'waste'], true)) {
                return;
            }

            if ($mission->category === 'mobility') {
                if ($this->has('validation_prompt') && $this->input('validation_prompt') !== null) {
                    $validator->errors()->add(
                        'validation_prompt',
                        'Validation prompt hanya untuk misi waste.'
                    );
                }
            } else {
                foreach (['activity_type', 'target_distance_km'] as $field) {
                    if ($this->has($field) && $this->input($field) !== null) {
                        $validator->errors()->add(
                            $field,
                            'Field ini hanya untuk misi mobility.'
                        );
                    }
                }
            }
        });
    }

    public function messages(): array
    {
        return [
            'activity_type.in' => 'Activity type harus walking, running, atau cycling.',
            'target_distance_km.min' => 'Target jarak minimal 0.1 km.',
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
