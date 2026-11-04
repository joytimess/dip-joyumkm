<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

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

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['name'] = [
            'required', 'string', 'max:120',
            Rule::unique('umkms', 'name')->where('user_id', $this->user()->id)
                ->ignore((int) $this->route('id')),
        ];

        if ($this->isMethod('PATCH')) {
            foreach (['name', 'province', 'sector_id', 'omzet'] as $field) {
                array_unshift($rules[$field], 'sometimes');
            }
        }

        $rules['record_id'] = ['required', 'integer', 'min:1'];

        return $rules;
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->only(['name', 'province', 'sector_id', 'omzet']) === []) {
                    $validator->errors()->add('body', 'Kirim setidaknya satu field UMKM yang dapat diubah.');
                }
            },
        ];
    }
}
