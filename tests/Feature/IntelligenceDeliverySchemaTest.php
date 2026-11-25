<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceDelivery;
use App\Models\IntelligenceReport;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class IntelligenceDeliverySchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TABLE = 'intelligence_deliveries';

    private const FINGERPRINT_A = 'a1b2c3d4e5f60718293a4b5c6d7e8f901a2b3c4d5e6f708192a3b4c5d6e7f809';

    private const FINGERPRINT_B = '0f1e2d3c4b5a69788796a5b4c3d2e1f00f1e2d3c4b5a69788796a5b4c3d2e1f0';

    public function test_deliveries_track_status_attempts_errors_and_idempotency_columns(): void
    {
        $columns = collect(Schema::getColumns(self::TABLE))->keyBy('name');

        $this->assertSame([
            'id', 'event_id', 'channel', 'recipient_fingerprint', 'artifact_set_hash', 'idempotency_key', 'status',
            'attempt_no', 'provider_message_id', 'error_code', 'sent_at', 'created_at', 'updated_at',
            'email_snapshot', 'email_snapshot_sha256', 'synced_email_event',
        ], $columns->keys()->all());
        $this->assertFalse($columns['recipient_fingerprint']['nullable'], 'Sentinel kosong wajib, bukan NULL.');
        $this->assertSame('', (string) $this->columnDefault($columns['recipient_fingerprint']['default']));
        $this->assertTrue($columns['provider_message_id']['nullable']);
        $this->assertTrue($columns['error_code']['nullable']);
    }

    public function test_unique_keys_are_declared_and_the_event_foreign_key_is_restricted(): void
    {
        $indexes = collect(Schema::getIndexes(self::TABLE))->keyBy('name');
        $this->assertTrue($indexes['intelligence_delivery_synced_email_unique']['unique']);
        $this->assertTrue($indexes['intelligence_deliveries_recipient_unique']['unique']);
        $this->assertSame(['event_id', 'channel', 'recipient_fingerprint'], $indexes['intelligence_deliveries_recipient_unique']['columns']);
        $this->assertTrue($indexes['intelligence_deliveries_idempotency_unique']['unique']);
        $this->assertSame(['idempotency_key'], $indexes['intelligence_deliveries_idempotency_unique']['columns']);

        $foreignKeys = Schema::getForeignKeys(self::TABLE);
        $this->assertCount(1, $foreignKeys);
        $this->assertSame('intelligence_reports', $foreignKeys[0]['foreign_table']);
        $this->assertSame(['event_id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('restrict', $foreignKeys[0]['on_delete']);
    }

    public function test_artifact_channel_is_unique_per_event_even_with_no_recipient(): void
    {
        $delivery = IntelligenceDelivery::factory()->create();

        $this->assertSame('', $delivery->recipient_fingerprint);
        $this->assertMysqlRejects(1062, fn () => IntelligenceDelivery::factory()->create(['event_id' => $delivery->event_id]));
        $this->assertMysqlRejects(1048, fn () => DB::table(self::TABLE)->insert($this->row($delivery->event_id, [
            'channel' => IntelligenceDelivery::CHANNEL_ARTIFACT, 'recipient_fingerprint' => null,
        ])));
    }

    public function test_email_is_idempotent_per_recipient_and_allows_distinct_recipients(): void
    {
        $report = IntelligenceReport::factory()->create();
        $first = IntelligenceDelivery::factory()->email(self::FINGERPRINT_A)->create(['event_id' => $report->event_id]);
        IntelligenceDelivery::factory()->email(self::FINGERPRINT_B)->create(['event_id' => $report->event_id]);

        $this->assertMysqlRejects(1062, fn () => IntelligenceDelivery::factory()->email(self::FINGERPRINT_A)->create(['event_id' => $report->event_id]));
        $this->assertMysqlRejects(1062, fn () => DB::table(self::TABLE)->insert($this->row($report->event_id, [
            'channel' => IntelligenceDelivery::CHANNEL_EMAIL,
            'recipient_fingerprint' => self::FINGERPRINT_A,
            'idempotency_key' => str_repeat('e', 64),
        ])));

        $this->assertSame(2, $report->deliveries()->where('channel', IntelligenceDelivery::CHANNEL_EMAIL)->count());
        $this->assertSame($first->idempotency_key, $report->deliveries()->where('recipient_fingerprint', self::FINGERPRINT_A)->value('idempotency_key'));
    }

    public function test_idempotency_key_is_unique_across_all_deliveries(): void
    {
        $artifact = IntelligenceDelivery::factory()->create();
        $other = IntelligenceReport::factory()->create();

        $this->assertMysqlRejects(1062, fn () => DB::table(self::TABLE)->insert($this->row($other->event_id, [
            'idempotency_key' => $artifact->idempotency_key,
        ])));
    }

    public function test_idempotency_key_is_a_hash_of_the_delivery_identity_without_raw_email(): void
    {
        $key = IntelligenceDelivery::idempotencyKeyFor(strtoupper('AAAAAAAA-BBBB-CCCC-DDDD-EEEEEEEEEEEE'), 'email', self::FINGERPRINT_A, str_repeat('f', 64));

        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $key);
        $this->assertSame($key, IntelligenceDelivery::idempotencyKeyFor('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'email', self::FINGERPRINT_A, str_repeat('f', 64)));
        $this->assertNotSame($key, IntelligenceDelivery::idempotencyKeyFor('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'artifact', '', str_repeat('f', 64)));
    }

    public function test_channel_status_and_recipient_rules_are_enforced_by_mysql(): void
    {
        $report = IntelligenceReport::factory()->create();
        $event = $report->event_id;

        $invalid = [
            ['channel' => 'sms'],
            ['status' => 'delivered'],
            ['status' => 'sent'],
            ['status' => 'failed_retryable'],
            ['status' => 'failed_permanent', 'error_code' => null],
            ['channel' => IntelligenceDelivery::CHANNEL_ARTIFACT, 'recipient_fingerprint' => self::FINGERPRINT_A],
            ['channel' => IntelligenceDelivery::CHANNEL_EMAIL, 'recipient_fingerprint' => 'bukan-hash'],
            ['channel' => IntelligenceDelivery::CHANNEL_EMAIL, 'recipient_fingerprint' => ''],
            ['channel' => IntelligenceDelivery::CHANNEL_ARTIFACT, 'status' => 'skipped_ineligible', 'error_code' => 'no_consent'],
            ['artifact_set_hash' => 'pendek'],
            ['idempotency_key' => strtoupper(str_repeat('a', 64))],
        ];
        foreach ($invalid as $override) {
            $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->insert($this->row($event, $override)));
        }

        DB::table(self::TABLE)->insert($this->row($event, [
            'channel' => IntelligenceDelivery::CHANNEL_EMAIL,
            'recipient_fingerprint' => '',
            'status' => 'skipped_ineligible',
            'error_code' => 'no_consent',
        ]));
        $this->assertDatabaseHas(self::TABLE, ['event_id' => $event, 'status' => 'skipped_ineligible', 'recipient_fingerprint' => '']);
    }

    public function test_sent_state_requires_timestamp_and_factory_states_are_valid(): void
    {
        $sent = IntelligenceDelivery::factory()->sent()->create();

        $this->assertSame(IntelligenceDelivery::SENT, $sent->status);
        $this->assertNotNull($sent->sent_at);
        $this->assertNotNull($sent->provider_message_id);
        $this->assertSame(1, $sent->attempt_no);
        $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->where('id', $sent->id)->update(['sent_at' => null]));
        $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->where('id', $sent->id)->update(['status' => 'failed_permanent']));
    }

    public function test_no_email_is_sent_while_deliveries_are_recorded(): void
    {
        Mail::fake();

        IntelligenceDelivery::factory()->email(self::FINGERPRINT_A)->sent()->create();

        Mail::assertNothingSent();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function row(string $eventId, array $overrides = []): array
    {
        return [
            'event_id' => $eventId,
            'channel' => IntelligenceDelivery::CHANNEL_ARTIFACT,
            'recipient_fingerprint' => '',
            'artifact_set_hash' => str_repeat('c', 64),
            'idempotency_key' => IntelligenceDelivery::idempotencyKeyFor($eventId, IntelligenceDelivery::CHANNEL_ARTIFACT, '', str_repeat('c', 64)),
            'status' => IntelligenceDelivery::QUEUED,
            'attempt_no' => 0,
            'created_at' => now(),
            'updated_at' => now(),
            ...$overrides,
        ];
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
