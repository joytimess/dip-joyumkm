<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuestionnaireResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'factors' => $this->resource['factors'],
            'scoring' => $this->resource['scoring'],
        ];
    }
}
