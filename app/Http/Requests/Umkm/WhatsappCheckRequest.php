<?php

declare(strict_types=1);

namespace App\Http\Requests\Umkm;

use App\Http\Requests\ApiRequest;
use App\Services\OpenWaNumberCheckService;

class WhatsappCheckRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge([
            'whatsapp_number' => OpenWaNumberCheckService::normalize($this->input('whatsapp_number')),
        ]);
    }

    public function rules(): array
    {
        return ['whatsapp_number' => self::numberRules()];
    }

    public static function numberRules(): array
    {
        return ['bail', 'required', 'string', 'regex:'.OpenWaNumberCheckService::NUMBER_PATTERN];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'whatsapp_number.regex' => 'Gunakan nomor WhatsApp Indonesia yang valid, misalnya 081234567890.',
        ];
    }
}
