<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiRequest extends FormRequest
{
    public function messages(): array
    {
        return [
            'required' => ':attribute wajib diisi.',
            'present' => ':attribute wajib dikirim.',
            'string' => ':attribute harus berupa teks.',
            'email' => 'Format email tidak valid.',
            'integer' => ':attribute harus berupa bilangan bulat.',
            'numeric' => ':attribute harus berupa angka.',
            'array' => ':attribute harus berupa daftar.',
            'min.string' => ':attribute minimal :min karakter.',
            'max.string' => ':attribute maksimal :max karakter.',
            'min.numeric' => ':attribute minimal :min.',
            'max.numeric' => ':attribute maksimal :max.',
            'max.array' => ':attribute maksimal :max item.',
            'confirmed' => 'Konfirmasi kata sandi tidak sama.',
            'unique' => ':attribute sudah digunakan.',
            'exists' => ':attribute tidak valid.',
            'in' => ':attribute tidak valid.',
            'distinct' => ':attribute tidak boleh duplikat.',
            'prohibited' => ':attribute tidak boleh dikirim.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'Nama',
            'email' => 'Email',
            'password' => 'Kata sandi',
            'password_confirmation' => 'Konfirmasi kata sandi',
            'record_id' => 'ID UMKM',
            'province' => 'Provinsi',
            'sector_id' => 'Sektor',
            'omzet' => 'Omzet',
            'whatsapp_number' => 'Nomor WhatsApp',
            'search' => 'Pencarian',
            'category' => 'Kategori',
            'status' => 'Status',
            'sort' => 'Urutan',
            'direction' => 'Arah urutan',
            'page' => 'Halaman',
            'per_page' => 'Jumlah per halaman',
            'answers' => 'Jawaban',
            'answers.*.question_id' => 'ID pertanyaan',
            'answers.*.value' => 'Nilai jawaban',
            'umkm_id' => 'ID UMKM',
            'umkm_ids' => 'Pilihan UMKM',
            'umkm_ids.*' => 'ID UMKM',
        ];
    }
}
