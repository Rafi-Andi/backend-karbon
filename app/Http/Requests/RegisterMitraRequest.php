<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class RegisterMitraRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalisasi nomor HP Indonesia sebelum validasi:
     * "0812-3456-7890" / "0812 3456 7890" -> "+6281234567890",
     * "62812..." -> "+62812...". Validasi tetap format internasional.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('phone') && is_string($this->input('phone'))) {
            $phone = preg_replace('/[\s\-_.()]/', '', trim((string) $this->input('phone'))) ?? '';

            if (str_starts_with($phone, '08')) {
                $phone = '+62'.substr($phone, 1);
            } elseif (str_starts_with($phone, '62')) {
                $phone = '+'.$phone;
            }

            $this->merge(['phone' => $phone]);
        }
    }

    public function rules(): array
    {
        $maxKb = (int) env('MITRA_MAX_FILE_KB', 5120);
        $image = ['required', 'file', 'mimes:jpeg,png,jpg', "max:{$maxKb}"];

        return [
            // Owner (akun user)
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['required', 'string', 'regex:/^\+[0-9]{10,15}$/', 'unique:users,phone'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'city' => ['required', 'string', 'max:255'],
            'district' => ['required', 'string', 'max:255'],
            'sub_district' => ['required', 'string', 'max:255'],
            'rt' => ['required', 'string', 'max:10'],
            'rw' => ['required', 'string', 'max:10'],

            // Toko
            'nama_usaha' => ['required', 'string', 'max:255'],
            'jenis_usaha' => ['required', 'string', 'max:255'],
            'alamat_usaha' => ['required', 'string', 'max:500'],
            'usaha_kelurahan' => ['required', 'string', 'max:255'],
            'usaha_kecamatan' => ['required', 'string', 'max:255'],
            'usaha_kota' => ['required', 'string', 'max:255'],
            'usaha_provinsi' => ['required', 'string', 'max:255'],
            'usaha_kode_pos' => ['nullable', 'string', 'max:10'],

            // Dokumen
            'nomor_ktp' => ['required', 'string', 'regex:/^[0-9]{16}$/', 'unique:mitra_profiles,nomor_ktp'],
            'nomor_nib' => ['required', 'string', 'max:30', 'unique:mitra_profiles,nomor_nib'],
            'foto_ktp' => $image,
            'foto_nib' => $image,
            'foto_toko' => $image,
            'foto_toko_2' => ['nullable', 'file', 'mimes:jpeg,png,jpg', "max:{$maxKb}"],
            'foto_toko_3' => ['nullable', 'file', 'mimes:jpeg,png,jpg', "max:{$maxKb}"],

            // Rekening pencairan
            'nama_bank' => ['required', 'string', 'max:255'],
            'nomor_rekening' => ['required', 'string', 'max:30'],
            'nama_pemilik_rekening' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.regex' => 'Nomor HP harus format internasional (contoh +628123456789). Awalan 08 otomatis dikonversi.',
            'phone.unique' => 'Nomor HP ini sudah terdaftar.',
            'email.unique' => 'Email ini sudah terdaftar.',
            'password.confirmed' => 'Konfirmasi password tidak cocok.',
            'nomor_ktp.regex' => 'Nomor KTP harus 16 digit angka.',
            'nomor_ktp.unique' => 'Nomor KTP ini sudah terdaftar.',
            'nomor_nib.unique' => 'Nomor NIB ini sudah terdaftar.',
            'foto_toko.required' => 'Minimal 1 foto depan toko wajib diunggah.',
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
