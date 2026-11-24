<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceReport;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use App\Models\User;
use App\Models\WebhookOutbox;
use App\Services\EmailSuppressionService;
use App\Services\HermesWebhookOutbox;
use App\Services\IntelligenceRecipientFingerprint;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class IntelligenceRecipientPullTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const SECRET = 'synthetic-recipient-key-0123456789abcdef';

    private const EMAIL = 'owner@example.test';

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeSecond();
        config([
            'services.hermes_intelligence_recipient.secret' => self::SECRET,
            'services.hermes_intelligence_recipient.test_mode' => true,
            'services.hermes_intelligence_recipient.test_allowlist' => ' OWNER@example.test ',
            'services.intelligence.recipient_pepper' => str_repeat('p', 32),
        ]);
    }

    private function headers(string $path, int $offset = 0): array
    {
        $timestamp = now()->timestamp + $offset;

        return [
            'X-Intelligence-Signature-Version' => '1',
            'X-Intelligence-Timestamp' => (string) $timestamp,
            'X-Intelligence-Signature' => hash_hmac('sha256', "JMI-RECIPIENT-V1\nGET\n{$path}\n{$timestamp}", self::SECRET),
        ];
    }

    private function fixture(): array
    {
        $umkm = Umkm::factory()->create(['business_email' => self::EMAIL]);
        $evaluation = SixFactorEvaluation::factory()->for($umkm)->final()->create();
        app(HermesWebhookOutbox::class)->recordFinalization($evaluation);
        $event = WebhookOutbox::query()->where('aggregate_id', $evaluation->id)->sole();

        return [$umkm, $event, '/api/internal/intelligence/recipients/'.$event->event_id];
    }

    public function test_consent_mutation_and_pull_are_bound_to_the_current_address_without_p4(): void
    {
        [$umkm, $event, $path] = $this->fixture();
        $edit = '/api/umkms/'.$umkm->id;
        $grant = [
            'notification_consent' => 'grant',
            'expected_business_email' => self::EMAIL,
            'consented_business_email' => self::EMAIL,
        ];
        $headers = $this->headers($path);

        $this->get($path, $headers)->assertOk()->assertExactJson([
            'event_id' => $event->event_id, 'eligible' => false, 'reason' => 'no_consent',
        ]);
        $this->patchJson($edit, $grant)->assertUnauthorized();
        Sanctum::actingAs(User::factory()->create());
        $this->patchJson($edit, $grant)->assertNotFound();
        Sanctum::actingAs($umkm->user);
        $this->patchJson($edit, $grant + ['notification_consent_by_user_id' => 999, 'notification_consent_version' => 'forged'])
            ->assertOk()->assertJsonPath('data.notification_consent_active', true);
        $row = $umkm->fresh();
        $this->assertSame($umkm->user_id, $row->notification_consent_by_user_id);
        $this->assertSame(now()->timestamp, $row->notification_consent_at->timestamp);
        $this->assertSame('v1', $row->notification_consent_version);
        $this->assertSame(0, IntelligenceReport::query()->count());

        $response = $this->get($path, $headers)->assertOk()->assertExactJson([
            'event_id' => $event->event_id,
            'umkm_id' => $umkm->id,
            'eligible' => true,
            'recipient_email' => self::EMAIL,
            'recipient_fingerprint' => app(IntelligenceRecipientFingerprint::class)->fingerprint(self::EMAIL),
            'consent_version' => 'v1',
            'issued_at' => now()->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => now()->utc()->addMinute()->format('Y-m-d\TH:i:s\Z'),
        ]);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));

        $this->patchJson($edit, ['pic_name' => 'PIC baru'])->assertOk()->assertJsonPath('data.notification_consent_active', true);
        $this->patchJson($edit, ['business_email' => 'different@example.test'])->assertOk()
            ->assertJsonPath('data.notification_consent_active', false);
        $this->patchJson($edit, $grant + ['business_email' => self::EMAIL])->assertConflict();
        $this->assertSame('different@example.test', $umkm->fresh()->business_email);

        $this->patchJson($edit, [
            'business_email' => self::EMAIL,
            'notification_consent' => 'grant',
            'expected_business_email' => 'different@example.test',
            'consented_business_email' => self::EMAIL,
        ])->assertOk()->assertJsonPath('data.notification_consent_active', true);

        config(['services.hermes_intelligence_recipient.test_allowlist' => 'different@example.test']);
        $this->get($path, $headers)->assertOk()->assertExactJson([
            'event_id' => $event->event_id, 'eligible' => false, 'reason' => 'not_allowlisted',
        ]);
        config(['services.hermes_intelligence_recipient.test_allowlist' => '']);
        $this->get($path, $headers)->assertStatus(503)->assertExactJson(['code' => 'recipient_unavailable']);
        config(['services.hermes_intelligence_recipient.test_mode' => 'invalid']);
        $this->get($path, $headers)->assertStatus(503)->assertExactJson(['code' => 'recipient_unavailable']);
        config([
            'services.hermes_intelligence_recipient.test_mode' => true,
            'services.hermes_intelligence_recipient.test_allowlist' => self::EMAIL,
            'services.intelligence.recipient_pepper' => 'short',
        ]);
        $this->get($path, $headers)->assertStatus(503)->assertExactJson(['code' => 'recipient_unavailable']);
        config([
            'services.hermes_intelligence_recipient.test_allowlist' => '',
            'services.intelligence.recipient_pepper' => str_repeat('p', 32),
        ]);
        app(EmailSuppressionService::class)->suppress(self::EMAIL, 'manual', 'test');
        $this->get($path, $headers)->assertOk()->assertExactJson([
            'event_id' => $event->event_id, 'eligible' => false, 'reason' => 'suppressed',
        ]);

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->patchJson($edit, ['notification_consent' => 'revoke'])->assertOk()
                ->assertJsonPath('data.notification_consent_active', false);
        }
        $row = $umkm->fresh();
        $this->assertNull($row->notification_consent_at);
        $this->assertNull($row->notification_consent_version);
        $this->assertNull($row->notification_consent_by_user_id);
        $this->get($path, $headers)->assertOk()->assertJsonPath('reason', 'no_consent');
    }

    public function test_authentication_framing_and_event_identity_fail_without_contact_data(): void
    {
        [$umkm, $event, $path] = $this->fixture();
        $unknown = '/api/internal/intelligence/recipients/'.Str::uuid();
        $this->get($path)->assertUnauthorized();
        $this->get($path, $this->headers($unknown))->assertUnauthorized();
        $this->get($path, $this->headers($path, -301))->assertUnauthorized();
        $this->get($path, $this->headers($path, 301))->assertUnauthorized();
        $this->get($path.'?umkm_id=1', $this->headers($path))->assertBadRequest();
        $this->call('GET', $path, [], [], [], [], 'body')->assertBadRequest();
        $this->postJson($path, [], $this->headers($path))->assertMethodNotAllowed();
        $this->head($path, $this->headers($path))->assertMethodNotAllowed();
        $this->call('OPTIONS', $path)->assertMethodNotAllowed();
        $this->get($unknown, $this->headers($unknown))->assertNotFound();

        $event->forceFill(['event_version' => 1])->save();
        $this->get($path, $this->headers($path))->assertNotFound();
        $event->forceFill(['event_version' => 2])->save();

        $draft = SixFactorEvaluation::factory()->for($umkm)->create();
        $draftPayload = $event->payload;
        $draftPayload['data']['six_factor']['evaluation_id'] = $draft->id;
        $event->forceFill(['payload' => $draftPayload, 'aggregate_id' => $draft->id])->save();
        $this->get($path, $this->headers($path))->assertOk()->assertExactJson([
            'event_id' => $event->event_id, 'eligible' => false, 'reason' => 'evaluation_not_final',
        ]);

        $event->refresh();
        $payload = $event->payload;
        $payload['data']['umkm_id'] = $umkm->id + 1;
        $event->forceFill(['payload' => $payload])->save();
        $this->get($path, $this->headers($path))->assertConflict()
            ->assertExactJson(['code' => 'event_identity_conflict']);

        config(['services.hermes_intelligence_recipient.secret' => 'short']);
        $this->get($path, $this->headers($path))->assertStatus(503)
            ->assertExactJson(['code' => 'recipient_unavailable']);
    }
}
