<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

use App\Http\Requests\ApiRequest;
use App\Services\OpenWaNumberCheckService;
use Illuminate\Validation\Rule;

class StoreUmkmRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
        if ($this->has('whatsapp_number')) {
            $this->merge(['whatsapp_number' => OpenWaNumberCheckService::normalize($this->input('whatsapp_number'))]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:120',
                Rule::unique('umkms', 'name')->where('user_id', $this->user()->id),
            ],
            'province' => ['required', 'string', Rule::in(config('provinces'))],
            'sector_id' => ['required', 'integer', 'exists:sectors,id'],
            'omzet' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'omzet_period' => ['required', Rule::in(['monthly', 'yearly'])],
            'business_capital' => ['required', 'integer', 'min:0', 'max:9007199254740991'],
            'year_founded' => ['nullable', 'integer', 'min:1800', 'max:'.now()->year],
            'employee_count' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'nib' => ['nullable', 'string', 'regex:/\A[0-9]{13}\z/'],
            'description' => ['nullable', 'string', 'max:2000'],
            'city' => ['required', 'string', 'max:120'],
            'address' => ['required', 'string', 'max:2000'],
            'district' => ['nullable', 'string', 'max:120'],
            'subdistrict' => ['nullable', 'string', 'max:120'],
            'postal_code' => ['nullable', 'string', 'regex:/\A[0-9]{5}\z/'],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'owner_name' => ['required', 'string', 'max:120'],
            'whatsapp_number' => WhatsappCheckRequest::numberRules(),
            'business_email' => ['required', 'string', 'email', 'max:254'],
            'notification_consent' => ['sometimes', 'boolean'],
            'pic_name' => ['nullable', 'string', 'max:120'],
            'website_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'social_media_url' => ['nullable', 'string', 'url:http,https', 'max:2048'],
            'user_id' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'whatsapp_number.regex' => 'Gunakan nomor WhatsApp Indonesia yang valid, misalnya 081234567890.',
            'nib.regex' => 'NIB harus terdiri dari 13 digit.',
            'postal_code.regex' => 'Kode pos harus terdiri dari 5 digit.',
            'between' => ':attribute harus bernilai antara :min dan :max.',
            'url' => ':attribute harus berupa URL HTTP atau HTTPS yang valid.',
            'required_with' => 'Isi latitude dan longitude secara berpasangan.',
            'present_with' => 'Kirim latitude dan longitude secara berpasangan.',
        ];
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'omzet_period' => 'Periode omzet', 'business_capital' => 'Modal usaha',
            'year_founded' => 'Tahun mulai usaha', 'employee_count' => 'Jumlah tenaga kerja',
            'nib' => 'NIB', 'description' => 'Deskripsi',
            'city' => 'Kabupaten/kota', 'address' => 'Alamat lengkap', 'district' => 'Kecamatan',
            'subdistrict' => 'Kelurahan/desa', 'postal_code' => 'Kode pos',
            'latitude' => 'Latitude', 'longitude' => 'Longitude', 'owner_name' => 'Nama pemilik',
            'business_email' => 'Email usaha', 'pic_name' => 'Nama PIC',
            'website_url' => 'Website', 'social_media_url' => 'Media sosial',
        ];
    }
}
