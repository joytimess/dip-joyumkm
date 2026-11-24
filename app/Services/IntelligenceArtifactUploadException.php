<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** Kegagalan unggah artefak dengan status HTTP dan kode mesin yang stabil (kontrak P4-04). */
final class IntelligenceArtifactUploadException extends RuntimeException
{
    public function __construct(public readonly int $status, public readonly string $errorCode, string $message)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->errorCode], $this->status);
    }
}
