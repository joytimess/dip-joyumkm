<?php

declare(strict_types=1);

namespace App\Http\Requests\Evaluation;

use App\Http\Requests\ApiRequest;
use Illuminate\Validation\ValidationException;
use stdClass;

class SixFactorEvaluationRequest extends ApiRequest
{
    public function authorize(): bool
    {
        if (! $this->isMethodSafe()) {
            abort_unless($this->user()->umkms()->whereKey($this->umkmId())->exists(), 404);
        }

        return true;
    }

    public function rules(): array
    {
        return ['scores' => ['array']];
    }

    public function validationData(): array
    {
        if ($this->isMethodSafe() || $this->getContent() === '') {
            return [];
        }

        // Decode the original body to preserve JSON types before middleware normalization.
        $body = json_decode($this->getContent());
        if (! $body instanceof stdClass) {
            throw ValidationException::withMessages(['body' => ['Isi permintaan harus berupa objek JSON yang valid.']]);
        }

        $isDraftSave = $this->isMethod('PUT') || $this->isMethod('PATCH');
        $errors = [];
        foreach ($body as $field => $value) {
            if (! $isDraftSave || $field !== 'scores') {
                $errors[$field] = ['Kolom ini tidak boleh dikirim.'];
            } elseif (! $value instanceof stdClass) {
                $errors['scores'] = ['Skor harus berupa objek JSON.'];
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return property_exists($body, 'scores') ? ['scores' => (array) $body->scores] : [];
    }

    public function umkmId(): int
    {
        return $this->numericId('id');
    }

    public function evaluationId(): int
    {
        return $this->numericId('evaluationId');
    }

    private function numericId(string $parameter): int
    {
        $digits = ltrim($this->route($parameter), '0');
        $id = (int) $digits;
        abort_unless((string) $id === $digits, 404);

        return $id;
    }
}
