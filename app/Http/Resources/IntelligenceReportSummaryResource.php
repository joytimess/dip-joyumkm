<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class IntelligenceReportSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_id' => $this->event_id,
            'evaluation_id' => $this->evaluation_id,
            'umkm_id' => $this->umkm_id,
            'source_event_version' => $this->source_event_version,
            'schema_version' => $this->schema_version,
            'prompt_version' => $this->prompt_version,
            'knowledge_version' => $this->knowledge_version,
            'methodology_status' => $this->methodology_status,
            'artifact_status' => $this->artifact_status,
            'provider' => $this->provider,
            'model' => $this->model,
            'generated_at' => $this->generated_at->toISOString(),
            'received_at' => $this->received_at->toISOString(),
        ];
    }
}
