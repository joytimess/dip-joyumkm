<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Intelligence\IntelligenceArtifactUploadRequest;
use App\Services\IntelligenceArtifactReceiver;
use Illuminate\Http\JsonResponse;

class IntelligenceArtifactController extends Controller
{
    public function __construct(private readonly IntelligenceArtifactReceiver $receiver) {}

    public function update(IntelligenceArtifactUploadRequest $request): JsonResponse
    {
        $outcome = $this->receiver->receive(
            $request->eventId(), $request->kind(), $request->bodyStream(), $request->signedSha256(), $request->signedLength(),
        );

        return response()->json([
            'result' => $outcome['result'],
            'event_id' => $outcome['report']->event_id,
            'report_id' => $outcome['report']->id,
            'kind' => $outcome['kind'],
            'sha256' => $outcome['sha256'],
            'size_bytes' => $outcome['size_bytes'],
            'artifact_status' => $outcome['report']->artifact_status,
            'recovered' => $outcome['recovered'],
        ], $outcome['result'] === IntelligenceArtifactReceiver::STORED ? 201 : 200);
    }
}
