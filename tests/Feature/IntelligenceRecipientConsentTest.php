<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\WebhookOutbox;
use App\Services\EmailSuppressionService;
use App\Services\HermesWebhookOutbox;
use App\Services\IntelligenceRecipientEligibilityService;
use App\Services\IntelligenceRecipientFingerprint;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class IntelligenceRecipientConsentTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const PEPPER = 'uji-pepper-kuat-0123456789abcdefghij';

    private const EMAIL = 'Pemilik.Uji@Example.test';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.intelligence.recipient_pepper' => self::PEPPER,
        ]);
    }

    public function test_legacy_umkm_has_no_notification_consent_by_default(): void
    {
        $umkm = Umkm::factory()->create(['business_email' => self::EMAIL]);

        $row = DB::table('umkms')->where('id', $umkm->id)->sole();
        $this->assertNull($row->notification_consent_at);
        $this->assertNull($row->notification_consent_version);
        $this->assertNull($row->notification_consent_by_user_id);
        $this->assertNull($umkm->fresh()->notification_consent_at);
    }

    public function test_consent_timestamp_and_version_must_be_recorded_together(): void
    {
        $umkm = Umkm::factory()->create();

        $this->assertMysqlRejects(3819, fn () => DB::table('umkms')->where('id', $umkm->id)->update(['notification_consent_at' => now()]));
        $this->assertMysqlRejects(3819, fn () => DB::table('umkms')->where('id', $umkm->id)->update(['notification_consent_version' => 'v1']));

        DB::table('umkms')->where('id', $umkm->id)->update([
            'notification_consent_at' => now(),
            'notification_consent_version' => 'v1',
            'notification_consent_by_user_id' => $umkm->user_id,
        ]);

        $consented = $umkm->fresh();
        $this->assertNotNull($consented->notification_consent_at);
        $this->assertSame('v1', $consented->notification_consent_version);
        $this->assertSame($umkm->user_id, $consented->notification_consent_by_user_id);
    }

    public function test_fingerprint_is_a_normalized_keyed_hash_that_never_contains_the_address(): void
    {
        $fingerprints = app(IntelligenceRecipientFingerprint::class);

        $value = $fingerprints->fingerprint('  '.strtoupper(self::EMAIL).' ');
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $value);
        $this->assertSame($value, $fingerprints->fingerprint('pemilik.uji@example.test'));
        $this->assertStringNotContainsStringIgnoringCase('example.test', $value);
        $this->assertNotSame($value, $fingerprints->fingerprint('lain@example.test'));
        $this->assertNull($fingerprints->fingerprint('bukan-email'));
        $this->assertNull($fingerprints->fingerprint(null));

        config(['services.intelligence.recipient_pepper' => 'lain-pepper-kuat-0123456789abcdefghij']);
        $this->assertNotSame($value, $fingerprints->fingerprint(self::EMAIL));

        config(['services.intelligence.recipient_pepper' => 'pendek']);
        $this->assertThrows(fn () => $fingerprints->fingerprint(self::EMAIL), RuntimeException::class);
    }

    public function test_suppression_stores_only_the_keyed_fingerprint_and_is_idempotent(): void
    {
        $service = app(EmailSuppressionService::class);

        $this->assertTrue($service->suppress(self::EMAIL, 'unsubscribe', 'uji'));
        $this->assertFalse($service->suppress(' pemilik.uji@example.test ', 'bounce', 'uji'));

        $row = DB::table('email_suppressions')->sole();
        $this->assertSame('unsubscribe', $row->reason);
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $row->email_fingerprint);
        foreach ((array) $row as $value) {
            $this->assertStringNotContainsStringIgnoringCase('pemilik.uji', (string) $value);
        }

        $this->assertTrue($service->isSuppressed('PEMILIK.UJI@example.test'));
        $this->assertFalse($service->isSuppressed('lain@example.test'));
    }

    public function test_suppression_rejects_unknown_reasons_and_invalid_addresses(): void
    {
        $service = app(EmailSuppressionService::class);

        $this->assertThrows(fn () => $service->suppress(self::EMAIL, 'spam', 'uji'), InvalidArgumentException::class);
        $this->assertThrows(fn () => $service->suppress('bukan-email', 'manual', 'uji'), InvalidArgumentException::class);
        $this->assertDatabaseCount('email_suppressions', 0);
    }

    public function test_suppression_table_rejects_raw_addresses_and_unknown_reasons(): void
    {
        $fingerprint = app(IntelligenceRecipientFingerprint::class)->fingerprint(self::EMAIL);

        $this->assertMysqlRejects(3819, fn () => DB::table('email_suppressions')->insert([
            'email_fingerprint' => self::EMAIL, 'reason' => 'manual', 'source' => 'uji', 'created_at' => now(),
        ]));
        $this->assertMysqlRejects(3819, fn () => DB::table('email_suppressions')->insert([
            'email_fingerprint' => $fingerprint, 'reason' => 'spam', 'source' => 'uji', 'created_at' => now(),
        ]));
    }

    public function test_eligibility_reports_reasons_in_order_and_never_exposes_the_address(): void
    {
        $report = IntelligenceReport::factory()->create();
        $umkm = Umkm::query()->findOrFail($report->umkm_id);
        $eligibility = app(IntelligenceRecipientEligibilityService::class);
        $evaluate = fn (): array => $eligibility->evaluate(IntelligenceReport::query()->findOrFail($report->id));

        $this->assertSame(['eligible' => false, 'reason' => 'no_consent', 'recipient_fingerprint' => null], $evaluate());

        $umkm->forceFill(['notification_consent_at' => now(), 'notification_consent_version' => 'v1'])->save();
        $this->assertSame(['eligible' => false, 'reason' => 'no_business_email', 'recipient_fingerprint' => null], $evaluate());

        $umkm->forceFill(['business_email' => 'bukan-email'])->save();
        $this->assertSame(['eligible' => false, 'reason' => 'no_consent', 'recipient_fingerprint' => null], $evaluate());

        $umkm->forceFill(['notification_consent_at' => now(), 'notification_consent_version' => 'v1'])->save();
        $this->assertSame(['eligible' => false, 'reason' => 'invalid_email', 'recipient_fingerprint' => null], $evaluate());

        $umkm->forceFill(['business_email' => self::EMAIL])->save();
        $this->assertSame(['eligible' => false, 'reason' => 'no_consent', 'recipient_fingerprint' => null], $evaluate());

        $umkm->forceFill(['notification_consent_at' => now(), 'notification_consent_version' => 'v1'])->save();
        $expected = app(IntelligenceRecipientFingerprint::class)->fingerprint(self::EMAIL);
        $eligible = $evaluate();
        $this->assertSame(['eligible' => true, 'reason' => null, 'recipient_fingerprint' => $expected], $eligible);
        $this->assertStringNotContainsStringIgnoringCase('example.test', json_encode($eligible, JSON_THROW_ON_ERROR));

        app(EmailSuppressionService::class)->suppress(self::EMAIL, 'complaint', 'uji');
        $suppressed = $evaluate();
        $this->assertSame(['eligible' => false, 'reason' => 'suppressed', 'recipient_fingerprint' => $expected], $suppressed);
        $this->assertStringNotContainsStringIgnoringCase('example.test', json_encode($suppressed, JSON_THROW_ON_ERROR));
    }

    public function test_changing_business_email_revokes_the_previous_consent(): void
    {
        $report = IntelligenceReport::factory()->create();
        $umkm = Umkm::query()->findOrFail($report->umkm_id);
        $umkm->forceFill([
            'business_email' => self::EMAIL,
            'notification_consent_at' => now(),
            'notification_consent_version' => 'v1',
            'notification_consent_by_user_id' => $umkm->user_id,
        ])->save();

        $umkm->forceFill(['business_email' => 'alamat-baru@example.test'])->save();

        $row = DB::table('umkms')->where('id', $umkm->id)->sole();
        $this->assertNull($row->notification_consent_at);
        $this->assertNull($row->notification_consent_version);
        $this->assertNull($row->notification_consent_by_user_id);
        $this->assertSame(
            ['eligible' => false, 'reason' => 'no_consent', 'recipient_fingerprint' => null],
            app(IntelligenceRecipientEligibilityService::class)->evaluate(IntelligenceReport::query()->findOrFail($report->id)),
        );
    }

    public function test_consent_recorded_in_the_same_save_as_a_new_business_email_is_kept(): void
    {
        $umkm = Umkm::factory()->create(['business_email' => self::EMAIL]);

        $umkm->forceFill([
            'business_email' => 'alamat-baru@example.test',
            'notification_consent_at' => now(),
            'notification_consent_version' => 'v1',
        ])->save();

        $this->assertNotNull($umkm->fresh()->notification_consent_at);
        $this->assertSame('v1', $umkm->fresh()->notification_consent_version);
    }

    public function test_saving_other_fields_keeps_consent_for_the_unchanged_business_email(): void
    {
        $umkm = Umkm::factory()->create(['business_email' => self::EMAIL]);
        $umkm->forceFill(['notification_consent_at' => now(), 'notification_consent_version' => 'v1'])->save();

        $umkm->forceFill(['owner_name' => 'Nama Pemilik Baru'])->save();

        $this->assertNotNull($umkm->fresh()->notification_consent_at);
        $this->assertSame('v1', $umkm->fresh()->notification_consent_version);
    }

    public function test_outbox_snapshot_and_no_mail_keep_contact_details_out_of_the_event(): void
    {
        Mail::fake();
        $umkm = Umkm::factory()->create([
            'business_email' => self::EMAIL,
            'owner_name' => 'Pemilik Uji',
            'pic_name' => 'PIC Uji',
            'whatsapp_number' => '081234567890',
        ]);
        $evaluation = SixFactorEvaluation::factory()->for($umkm)->final()->create();

        app(HermesWebhookOutbox::class)->recordFinalization($evaluation);

        $payload = WebhookOutbox::query()->sole()->getRawOriginal('payload');
        foreach ([self::EMAIL, 'pemilik.uji@example.test', 'Pemilik Uji', 'PIC Uji', '081234567890'] as $contact) {
            $this->assertStringNotContainsStringIgnoringCase($contact, $payload);
        }
        Mail::assertNothingSent();
    }

    private function assertMysqlRejects(int $code, Closure $operation): void
    {
        try {
            $operation();
            $this->fail("MySQL menerima operasi yang seharusnya ditolak dengan kode {$code}.");
        } catch (QueryException $exception) {
            $this->assertSame($this->databaseErrorCode($code), $exception->errorInfo[1], $exception->getMessage());
        }
    }
}
