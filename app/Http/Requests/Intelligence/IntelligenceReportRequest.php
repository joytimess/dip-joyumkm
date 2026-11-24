<?php

declare(strict_types=1);

namespace App\Http\Requests\Intelligence;

use App\Http\Requests\ApiRequest;

class IntelligenceReportRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return $this->query->all();
    }

    public function rules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }

    public function umkmId(): int
    {
        return $this->numericId('id');
    }

    public function reportId(): int
    {
        return $this->numericId('reportId');
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 10);
    }

    public function pageNumber(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }

    private function numericId(string $parameter): int
    {
        $digits = ltrim((string) $this->route($parameter), '0');
        $id = (int) $digits;
        abort_unless($digits !== '' && (string) $id === $digits, 404);

        return $id;
    }
}
