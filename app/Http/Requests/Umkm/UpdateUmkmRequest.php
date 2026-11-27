<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

use App\Models\Umkm;
use App\Services\UmkmService;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateUmkmRequest extends StoreUmkmRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $this->merge(['record_id' => $this->route('id')]);
    }

    public function authorize(UmkmService $umkmService): bool
    {
        $umkmService->owned($this->user(), (int) $this->route('id'));

        return true;
    }

    /** Keunikan nama dinilai terhadap pemilik UMKM, bukan pelaku (Superadmin dapat mengubah milik orang lain). */
    private function resourceOwnerId(): int
    {
        return (int) (Umkm::query()->whereKey((int) $this->route('id'))->value('user_id') ?? $this->user()->id);
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = [
            'required', 'string', 'max:120',
            Rule::unique('umkms', 'name')->where('user_id', $this->resourceOwnerId())
                ->ignore((int) $this->route('id')),
        ];

        if ($this->isMethod('PATCH')) {
            foreach (['name', 'province', 'sector_id', 'omzet'] as $field) {
                array_unshift($rules[$field], 'sometimes');
            }
        }
        foreach (['city', 'address', 'owner_name', 'whatsapp_number', 'business_email'] as $field) {
            array_unshift($rules[$field], 'sometimes');
        }
        // Data UMKM lama boleh belum memiliki periode omzet dan modal usaha; keduanya wajib saat create.
        $rules['omzet_period'] = ['sometimes', 'nullable', Rule::in(['monthly', 'yearly'])];
        $rules['business_capital'] = ['sometimes', 'nullable', 'integer', 'min:0', 'max:9007199254740991'];
        $rules['latitude'] = ['present_with:longitude', 'required_with:longitude', 'nullable', 'numeric', 'between:-90,90'];
        $rules['longitude'] = ['present_with:latitude', 'required_with:latitude', 'nullable', 'numeric', 'between:-180,180'];

        $rules['record_id'] = ['required', 'integer', 'min:1'];
        $rules['notification_consent'] = ['sometimes', Rule::in(['grant', 'revoke'])];
        $rules['expected_business_email'] = ['present_if:notification_consent,grant', 'nullable', 'string', 'max:254'];
        $rules['consented_business_email'] = ['required_if:notification_consent,grant', 'nullable', 'string', 'email', 'max:254'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->only(array_diff(array_keys($validator->getRules()), [
                    'record_id', 'user_id', 'expected_business_email', 'consented_business_email',
                ])) === []) {
                    $validator->errors()->add('body', 'Kirim setidaknya satu field UMKM yang dapat diubah.');
                }
            },
        ];
    }
}
