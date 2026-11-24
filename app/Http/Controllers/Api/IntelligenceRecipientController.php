<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SixFactorEvaluation;
use App\Models\WebhookOutbox;
use App\Services\IntelligenceRecipientEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class IntelligenceRecipientController extends Controller
{
    public function show(string $eventId, IntelligenceRecipientEligibilityService $eligibility): JsonResponse
    {
        return DB::transaction(function () use ($eventId, $eligibility): JsonResponse {
            $event = WebhookOutbox::query()->where('event_id', $eventId)->first();
            abort_unless($event?->event_type === 'evaluation.finalized' && $event->event_version === 2, 404);

            $evaluation = SixFactorEvaluation::query()->with('umkm')->find($event->aggregate_id);
            $umkm = $evaluation?->umkm;
            abort_unless(
                $umkm !== null
                    && data_get($event->payload, 'event_id') === $eventId
                    && data_get($event->payload, 'event_type') === $event->event_type
                    && data_get($event->payload, 'event_version') === $event->event_version
                    && data_get($event->payload, 'source') === 'joyumkm'
                    && data_get($event->payload, 'data.six_factor.evaluation_id') === $evaluation->id
                    && data_get($event->payload, 'data.six_factor.status') === SixFactorEvaluation::FINAL
                    && data_get($event->payload, 'data.umkm_id') === $umkm->id,
                409,
            );
            abort_if($evaluation->status === SixFactorEvaluation::FINAL && $evaluation->finalized_at === null, 409);

            $ttl = filter_var(config('services.hermes_intelligence_recipient.ttl_seconds'), FILTER_VALIDATE_INT);
            abort_unless($ttl === 60, 503);

            $decision = $eligibility->evaluateRecipient($evaluation, $umkm);
            if (! $decision['eligible']) {
                return response()->json(['event_id' => $eventId, 'eligible' => false, 'reason' => $decision['reason']]);
            }

            $issuedAt = now()->toImmutable()->utc();

            return response()->json([
                'event_id' => $eventId,
                'umkm_id' => $umkm->id,
                'eligible' => true,
                'recipient_email' => trim($umkm->business_email),
                'recipient_fingerprint' => $decision['recipient_fingerprint'],
                'consent_version' => $umkm->notification_consent_version,
                'issued_at' => $issuedAt->format('Y-m-d\TH:i:s\Z'),
                'expires_at' => $issuedAt->addSeconds($ttl)->format('Y-m-d\TH:i:s\Z'),
            ]);
        });
    }
}
