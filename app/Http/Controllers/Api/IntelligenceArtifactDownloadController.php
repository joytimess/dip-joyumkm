<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Intelligence\IntelligenceReportRequest;
use App\Models\IntelligenceArtifact;
use App\Models\IntelligenceArtifactManifest;
use App\Models\IntelligenceDelivery;
use App\Models\IntelligenceReport;
use App\Services\IntelligenceArtifactContract;
use App\Services\IntelligenceArtifactReceiver;
use App\Services\IntelligenceReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class IntelligenceArtifactDownloadController extends Controller
{
    public function __construct(private readonly IntelligenceReportService $reports, private readonly IntelligenceArtifactReceiver $receiver) {}

    public function index(IntelligenceReportRequest $request): JsonResponse
    {
        $report = $this->report($request);
        $manifest = IntelligenceArtifactManifest::query()->where('report_id', $report->id)->where('event_id', $report->event_id)->first();
        $artifacts = [];
        foreach (array_keys(IntelligenceArtifactContract::KINDS) as $kind) {
            $entry = $manifest?->entryFor($kind);
            $artifact = $this->matchingArtifact($report, $kind, $entry);
            $registered = $report->artifacts()->where('kind', $kind)->exists();
            $available = $artifact !== null && $this->receiver->verifiedBytes($artifact) !== null;
            if ($registered && ! $available) {
                $this->incident($report, $kind);
            }
            $artifacts[$kind] = [
                'availability' => $available ? 'available' : ($registered ? 'unavailable' : ($entry === null ? 'not_expected' : 'pending')),
                'mime' => $entry['mime'] ?? null,
                'size_bytes' => $entry['size_bytes'] ?? null,
                'download_url' => $available ? route('intelligence.artifacts.download', ['id' => $report->umkm_id, 'reportId' => $report->id, 'kind' => $kind], false) : null,
            ];
        }

        $snapshot = IntelligenceDelivery::query()->where('synced_email_event', $report->event_id)->first()?->email_snapshot;

        return response()->json(['report_id' => $report->id, 'event_id' => $report->event_id, 'evaluation_id' => $report->evaluation_id,
            'artifact_status' => $report->artifact_status, 'artifacts' => $artifacts,
            'email_delivery' => $snapshot === null ? null : [
                'status' => $snapshot['status'], 'sent_at' => $snapshot['sent_at'],
                'reason' => match ($snapshot['error_code']) {
                    'NO_CONSENT' => 'no_consent',
                    'SUPPRESSED' => 'suppressed',
                    'NO_BUSINESS_EMAIL', 'INVALID_EMAIL' => 'email_unavailable',
                    default => null,
                },
            ],
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(IntelligenceReportRequest $request, string $id, string $reportId, string $kind): Response|JsonResponse
    {
        $report = $this->report($request);
        $manifest = IntelligenceArtifactManifest::query()->where('report_id', $report->id)->where('event_id', $report->event_id)->first();
        $artifact = $this->matchingArtifact($report, $kind, $manifest?->entryFor($kind));
        abort_if($artifact === null, 404);
        $bytes = $this->receiver->verifiedBytes($artifact);
        if ($bytes === null) {
            $this->incident($report, $kind);

            return response()->json(['code' => 'artifact_unavailable', 'message' => 'Artefak tidak tersedia atau gagal verifikasi.'], 409);
        }

        return response($bytes, 200, ['Content-Type' => IntelligenceArtifactContract::KINDS[$kind],
            'Content-Disposition' => 'attachment; filename="intelligence-report-'.$report->id.'.'.$kind.'"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function report(IntelligenceReportRequest $request): IntelligenceReport
    {
        return $this->reports->find($request->user(), $request->umkmId(), $request->reportId());
    }

    private function matchingArtifact(IntelligenceReport $report, string $kind, ?array $entry): ?IntelligenceArtifact
    {
        if ($entry === null) {
            return null;
        }

        return $report->artifacts()->where('event_id', $report->event_id)->where('kind', $kind)
            ->where('sha256', $entry['sha256'])->where('size_bytes', $entry['size_bytes'])->where('mime', $entry['mime'])->first();
    }

    private function incident(IntelligenceReport $report, string $kind): void
    {
        Log::warning('intelligence.artifact.integrity_incident', ['event_id' => $report->event_id, 'kind' => $kind]);
    }
}
