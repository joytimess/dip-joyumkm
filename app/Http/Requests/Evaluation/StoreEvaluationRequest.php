<?php

declare(strict_types=1);

namespace App\Http\Requests\Evaluation;

use App\Http\Requests\ApiRequest;
use App\Services\UmkmService;
use Illuminate\Validation\Rule;

class StoreEvaluationRequest extends ApiRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['record_id' => $this->route('id')]);
    }

    public function authorize(UmkmService $umkmService): bool
    {
        $umkmService->owned($this->user(), (int) $this->route('id'));

        return true;
    }

    public function rules(): array
    {
        return [
            'record_id' => ['required', 'integer', 'min:1'],
            'status' => ['required', 'string', Rule::in(['draft', 'final'])],
            'answers' => ['present', 'array', 'list'],
            'answers.*' => ['required', 'array:question_id,value'],
            'answers.*.question_id' => ['required', 'integer', 'min:1', 'distinct'],
            'answers.*.value' => ['required', 'integer'],
            'factor_scores' => ['prohibited'],
            'ins_score' => ['prohibited'],
            'ect_score' => ['prohibited'],
            'ops_score' => ['prohibited'],
            'average_score' => ['prohibited'],
            'category' => ['prohibited'],
        ];
    }

    public function messages(): array
    {
        return [...parent::messages(), 'list' => 'Jawaban harus berupa daftar berurutan.'];
    }
}
