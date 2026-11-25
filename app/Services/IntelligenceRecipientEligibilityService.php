<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;

/**
 * Kelayakan penerima laporan. Hasilnya hanya memuat alasan dan fingerprint, tidak pernah alamat email.
 */
class IntelligenceRecipientEligibilityService
{
    public const REASON_EVALUATION_NOT_FINAL = 'evaluation_not_final';

    public const REASON_NO_CONSENT = 'no_consent';

    public const REASON_NO_BUSINESS_EMAIL = 'no_business_email';

    public const REASON_INVALID_EMAIL = 'invalid_email';

    public const REASON_SUPPRESSED = 'suppressed';

    public function __construct(
        private readonly IntelligenceRecipientFingerprint $fingerprints,
        private readonly EmailSuppressionService $suppressions,
    ) {}

    /**
     * @return array{eligible: bool, reason: ?string, recipient_fingerprint: ?string}
     */
    public function evaluate(IntelligenceReport $report): array
    {
        return $this->evaluateRecipient($report->evaluation, $report->umkm);
    }

    /** @return array{eligible: bool, reason: ?string, recipient_fingerprint: ?string} */
    public function evaluateRecipient(?SixFactorEvaluation $evaluation, Umkm $umkm): array
    {
        if ($evaluation?->status !== SixFactorEvaluation::FINAL) {
            return $this->result(false, self::REASON_EVALUATION_NOT_FINAL);
        }

        if (! $umkm->hasNotificationConsent()) {
            return $this->result(false, self::REASON_NO_CONSENT);
        }

        $email = trim((string) $umkm->business_email);
        if ($email === '') {
            return $this->result(false, self::REASON_NO_BUSINESS_EMAIL);
        }

        $fingerprint = $this->fingerprints->fingerprint($email);
        if ($fingerprint === null) {
            return $this->result(false, self::REASON_INVALID_EMAIL);
        }

        if ($this->suppressions->containsFingerprint($fingerprint)) {
            return $this->result(false, self::REASON_SUPPRESSED, $fingerprint);
        }

        return $this->result(true, null, $fingerprint);
    }

    /**
     * @return array{eligible: bool, reason: ?string, recipient_fingerprint: ?string}
     */
    private function result(bool $eligible, ?string $reason, ?string $fingerprint = null): array
    {
        return ['eligible' => $eligible, 'reason' => $reason, 'recipient_fingerprint' => $fingerprint];
    }
}
