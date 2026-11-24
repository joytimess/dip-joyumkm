<?php

declare(strict_types=1);

namespace App\Http\Requests\Intelligence;

use App\Http\Requests\ApiRequest;
use App\Services\IntelligenceArtifactContract;
use Illuminate\Validation\Rule;

/** Validasi identitas rute unggahan artefak; autentikasi dilakukan middleware HMAC. */
class IntelligenceArtifactUploadRequest extends ApiRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function validationData(): array
    {
        return ['eventId' => $this->route('eventId'), 'kind' => $this->route('kind')];
    }

    public function rules(): array
    {
        return [
            'eventId' => ['required', 'string', 'uuid'],
            'kind' => ['required', 'string', Rule::in(array_keys(IntelligenceArtifactContract::KINDS))],
        ];
    }

    public function eventId(): string
    {
        return (string) $this->route('eventId');
    }

    public function kind(): string
    {
        return (string) $this->route('kind');
    }

    public function signedSha256(): string
    {
        return (string) $this->attributes->get('artifact_signed')['sha256'];
    }

    public function signedLength(): int
    {
        return (int) $this->attributes->get('artifact_signed')['length'];
    }

    /** @return resource */
    public function bodyStream()
    {
        return $this->getContent(true);
    }
}
