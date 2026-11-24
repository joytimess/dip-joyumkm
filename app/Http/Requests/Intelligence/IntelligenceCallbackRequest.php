<?php

declare(strict_types=1);

namespace App\Http\Requests\Intelligence;

use App\Http\Requests\ApiRequest;
use App\Models\IntelligenceReport;
use App\Services\IntelligenceCallbackPayload;
use Closure;
use Illuminate\Validation\Rule;

class IntelligenceCallbackRequest extends ApiRequest
{
    private const IDENTIFIER = '/^[A-Za-z0-9][A-Za-z0-9._:+\-]*$/';

    private const TIMESTAMP = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    private ?IntelligenceCallbackPayload $payload = null;

    /** Tanda tangan HMAC sudah menjamin pengirim; kontrol akses dilakukan oleh middleware. */
    public function authorize(): bool
    {
        return true;
    }

    public function payload(): IntelligenceCallbackPayload
    {
        return $this->payload ??= IntelligenceCallbackPayload::fromJson($this->getContent());
    }

    /** Validasi memakai raw body agar tipe JSON asli (misalnya "11" versus 11) tidak dinormalisasi middleware. */
    public function validationData(): array
    {
        return $this->payload()->data;
    }

    public function rules(): array
    {
        $identifier = ['required', 'string', 'max:96', 'regex:'.self::IDENTIFIER];

        return [
            'event_id' => ['required', 'string', 'uuid'],
            'evaluation_id' => ['required', $this->integerRule(1)],
            'umkm_id' => ['required', $this->integerRule(1)],
            'status' => ['required', 'string', Rule::in(['completed'])],
            'source_event_version' => ['required', $this->integerRule(1, 2)],
            'schema_version' => ['required', $this->integerRule(1, 1)],
            'prompt_version' => [...$identifier, 'max:64'],
            'knowledge_version' => $identifier,
            'methodology_status' => ['required', 'string', Rule::in([IntelligenceReport::COMPLETE, IntelligenceReport::PARTIAL])],
            'provider' => $identifier,
            'model' => ['required', 'string', 'max:96', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:+\-\[\]]*$/'],
            'generated_at' => ['required', 'string', 'regex:'.self::TIMESTAMP, 'date'],

            'report' => ['required', $this->objectRule()],
            'report.executive_summary' => ['required', 'string', 'min:1', 'max:20000'],
            'report.business_profile' => ['required', $this->objectRule()],
            'report.business_profile.data_gaps' => ['present', $this->listRule()],
            'report.six_factor_analysis' => ['required', $this->objectRule()],
            'report.six_factor_analysis.summary' => ['required', 'string', 'max:20000'],
            'report.six_factor_analysis.findings' => ['present', $this->listRule()],
            'report.three_dimension_analysis' => ['required', $this->objectRule()],
            'report.three_dimension_analysis.available' => [
                'required',
                fn (string $attribute, mixed $value, Closure $fail) => is_bool($value) || $fail(':attribute harus berupa true atau false.'),
            ],
            'report.three_dimension_analysis.summary' => ['present', 'nullable', 'string', 'max:20000'],
            'report.three_dimension_analysis.findings' => ['present', $this->listRule()],
            'report.perspectives' => ['present', $this->objectRule()],
            'report.perspectives.*' => [$this->objectRule()],
            'report.perspectives.*.findings' => ['present', $this->listRule()],
            'report.cross_instrument_insights' => ['present', $this->listRule()],
            'report.top_priorities' => ['present', $this->listRule(), 'max:3'],
            'report.top_priorities.*' => [$this->objectRule()],
            'report.top_priorities.*.id' => ['required', 'string', 'max:32'],
            'report.top_priorities.*.title' => ['required', 'string', 'max:500'],
            'report.top_priorities.*.perspective' => ['required', 'string', 'max:64'],
            'report.top_priorities.*.priority' => ['required', 'string', 'max:32'],
            'report.top_priorities.*.evidence_refs' => ['present', $this->listRule()],
            'report.top_priorities.*.rationale' => ['required', 'string', 'max:20000'],
            'report.top_priorities.*.actions' => ['present', $this->listRule()],
            'report.top_priorities.*.success_metrics' => ['present', $this->listRule()],
            'report.top_priorities.*.assumptions' => ['present', $this->listRule()],
            'report.top_priorities.*.confidence' => ['required', 'string', 'max:32'],
            'report.action_plan' => ['required', $this->objectRule()],
            'report.action_plan.days_1_30' => ['present', $this->listRule()],
            'report.action_plan.days_31_60' => ['present', $this->listRule()],
            'report.action_plan.days_61_90' => ['present', $this->listRule()],
            'report.limitations' => [
                'present', $this->listRule(),
                fn (string $attribute, mixed $value, Closure $fail) => $this->payloadValue('methodology_status') !== IntelligenceReport::PARTIAL
                    || $value !== [] || $fail('Laporan partial wajib mencantumkan keterbatasan.'),
            ],
            'report.limitations.*' => ['string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            ...parent::messages(),
            'uuid' => ':attribute harus berupa UUID.',
            'regex' => 'Format :attribute tidak valid.',
            'date' => 'Format :attribute tidak valid.',
        ];
    }

    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'event_id' => 'ID event',
            'evaluation_id' => 'ID evaluasi',
            'report' => 'Laporan',
        ];
    }

    private function payloadValue(string $key): mixed
    {
        return $this->payload()->data[$key] ?? null;
    }

    /** Bilangan bulat JSON asli; string numerik ("11") ditolak. */
    private function integerRule(int $min, ?int $max = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min, $max): void {
            if (! is_int($value) || $value < $min || ($max !== null && $value > $max)) {
                $fail(':attribute tidak valid.');
            }
        };
    }

    /** Objek JSON; `{}` kosong (terdekode `[]`) tetap diterima. */
    private function objectRule(): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => is_array($value) && ($value === [] || ! array_is_list($value))
            || $fail(':attribute harus berupa objek.');
    }

    private function listRule(): Closure
    {
        return fn (string $attribute, mixed $value, Closure $fail) => is_array($value) && array_is_list($value)
            || $fail(':attribute harus berupa daftar.');
    }
}
