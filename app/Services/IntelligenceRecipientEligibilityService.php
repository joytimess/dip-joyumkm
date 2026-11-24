<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use RuntimeException;

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

        $testMode = config('services.hermes_intelligence_recipient.test_mode');
        if (! is_bool($testMode)) {
            throw new RuntimeException('Mode pengujian penerima tidak valid.');
        }

        if ($testMode) {
            $configured = config('services.hermes_intelligence_recipient.test_allowlist');
            $allowlist = is_string($configured) ? array_map(trim(...), explode(',', strtolower($configured))) : [];
            if ($allowlist === [] || array_filter($allowlist, static fn (string $email): bool => ! filter_var($email, FILTER_VALIDATE_EMAIL))) {
                throw new RuntimeException('Allowlist penerima belum dikonfigurasi dengan valid.');
            }
            if (! in_array(strtolower($email), $allowlist, true)) {
                return $this->result(false, 'not_allowlisted', $fingerprint);
            }
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
