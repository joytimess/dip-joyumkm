<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;

class IntelligenceReportResource extends IntelligenceReportSummaryResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $document = $this->resource->document();

        return [
            ...parent::toArray($request),
            'limitations' => $document->limitations ?? [],
            'report' => $document,
        ];
    }
}
