<?php

declare(strict_types=1);

namespace App\Http\Requests\Evaluation;

use App\Http\Requests\ApiRequest;
use App\Models\SixFactorEvaluation;
use Illuminate\Validation\ValidationException;
use stdClass;

class SixFactorPredictionRequest extends ApiRequest
{
    public function authorize(): bool
    {
        $digits = ltrim($this->route('id'), '0');
        $id = (int) $digits;
        abort_unless((string) $id === $digits && $this->user()->accessibleUmkms()->whereKey($id)->exists(), 404);

        return true;
    }

    public function rules(): array
    {
        $factors = SixFactorEvaluation::FACTORS;
        $rules = ['scores' => ['bail', 'required', 'array:'.implode(',', $factors)]];
        foreach ($factors as $factor) {
            $rules['scores.'.$factor] = ['bail', 'required', 'integer:strict', 'between:1,5'];
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'scores.array' => 'Skor memuat kolom yang tidak dikenal.',
            'scores.*.between' => 'Nilai faktor harus antara 1 dan 5.',
        ];
    }

    public function validationData(): array
    {
        // Membaca JSON asli menjaga perbedaan tipe sebelum middleware menormalisasi nilai.
        $body = json_decode($this->getContent(), depth: 16);
        if (! $body instanceof stdClass) {
            throw ValidationException::withMessages(['body' => ['Isi permintaan harus berupa objek JSON yang valid.']]);
        }
        if (array_diff(array_keys((array) $body), ['scores']) !== []) {
            throw ValidationException::withMessages(['body' => ['Permintaan hanya boleh memuat skor.']]);
        }
        if (! property_exists($body, 'scores')) {
            return [];
        }
        if (! $body->scores instanceof stdClass) {
            throw ValidationException::withMessages(['scores' => ['Skor harus berupa objek JSON.']]);
        }

        return ['scores' => (array) $body->scores];
    }
}
