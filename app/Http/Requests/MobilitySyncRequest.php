<?php

namespace App\Http\Requests;

use App\Models\Mission;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MobilitySyncRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mission_id' => ['nullable', 'integer', 'exists:missions,id'],
            'activity_type' => ['required', 'string', 'in:walking,running,cycling'],
            'distance_km' => ['required', 'numeric', 'min:0.1', 'max:500'],
            'duration_seconds' => ['required', 'integer', 'min:60'],
            'gps_coordinates_path' => ['required', 'array', 'min:2', 'max:2000'],
            'gps_coordinates_path.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'gps_coordinates_path.*.lng' => ['required', 'numeric', 'between:-180,180'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('mission_id') || $this->input('mission_id') === null) {
                return;
            }

            $mission = Mission::find($this->input('mission_id'));

            if (! $mission || $mission->category !== 'mobility' || ! $mission->is_active) {
                $validator->errors()->add(
                    'mission_id',
                    'Mission must be an active mobility mission.'
                );

                return;
            }

            if ($mission->activity_type !== null
                && $this->input('activity_type') !== $mission->activity_type
            ) {
                $validator->errors()->add(
                    'activity_type',
                    'Activity type does not match this mission.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'mission_id.integer' => 'Mission ID must be an integer.',
            'mission_id.exists' => 'Mission not found.',
            'activity_type.required' => 'Activity type is required.',
            'activity_type.in' => 'Activity type must be walking, running, or cycling.',
            'distance_km.required' => 'Distance is required.',
            'distance_km.numeric' => 'Distance must be a number.',
            'distance_km.min' => 'Distance must be at least 0.1 km.',
            'distance_km.max' => 'Distance may not exceed 500 km.',
            'duration_seconds.required' => 'Duration is required.',
            'duration_seconds.integer' => 'Duration must be in seconds.',
            'duration_seconds.min' => 'Duration must be at least 60 seconds.',
            'gps_coordinates_path.required' => 'GPS route is required.',
            'gps_coordinates_path.array' => 'GPS route must be an array of coordinates.',
            'gps_coordinates_path.min' => 'GPS route must contain at least 2 points.',
            'gps_coordinates_path.max' => 'GPS route may not exceed 2000 points.',
            'gps_coordinates_path.*.lat.required' => 'Each GPS point needs a latitude.',
            'gps_coordinates_path.*.lat.between' => 'Latitude must be between -90 and 90.',
            'gps_coordinates_path.*.lng.required' => 'Each GPS point needs a longitude.',
            'gps_coordinates_path.*.lng.between' => 'Longitude must be between -180 and 180.',
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
