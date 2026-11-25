<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceArtifact;
use App\Models\IntelligenceReport;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class IntelligenceArtifactSchemaTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const TABLE = 'intelligence_artifacts';

    public function test_artifacts_store_metadata_only_without_binary_columns(): void
    {
        $columns = collect(Schema::getColumns(self::TABLE))->keyBy('name');

        $this->assertSame([
            'id', 'report_id', 'event_id', 'kind', 'storage_disk', 'storage_path', 'mime', 'size_bytes', 'sha256', 'created_at',
        ], $columns->keys()->all());
        foreach ($columns as $column) {
            $this->assertDoesNotMatchRegularExpression('/blob|binary/i', (string) $column['type_name']);
        }
        $this->assertFalse($columns['sha256']['nullable']);
        $this->assertSame('char(64)', $columns['sha256']['type']);
        $this->assertStringContainsString('local', (string) $columns['storage_disk']['default']);
    }

    public function test_unique_keys_and_composite_foreign_key_tie_artifacts_to_their_report(): void
    {
        $indexes = collect(Schema::getIndexes(self::TABLE))->keyBy('name');
        $this->assertTrue($indexes['intelligence_artifacts_report_kind_unique']['unique']);
        $this->assertSame(['report_id', 'kind'], $indexes['intelligence_artifacts_report_kind_unique']['columns']);
        $this->assertSame(['storage_disk', 'storage_path'], $indexes['intelligence_artifacts_storage_unique']['columns']);

        $foreignKeys = Schema::getForeignKeys(self::TABLE);
        $this->assertCount(1, $foreignKeys);
        $this->assertSame(['report_id', 'event_id'], $foreignKeys[0]['columns']);
        $this->assertSame(['id', 'event_id'], $foreignKeys[0]['foreign_columns']);
        $this->assertSame('intelligence_reports', $foreignKeys[0]['foreign_table']);
        $this->assertSame('restrict', $foreignKeys[0]['on_delete']);
    }

    public function test_check_constraints_are_enforced_by_mysql(): void
    {
        $checks = $this->checkConstraintEnforcement(self::TABLE);

        $this->assertSame([
            'intelligence_artifacts_kind_check' => 'YES',
            'intelligence_artifacts_mime_check' => 'YES',
            'intelligence_artifacts_path_check' => 'YES',
            'intelligence_artifacts_sha256_check' => 'YES',
            'intelligence_artifacts_size_check' => 'YES',
        ], $checks);
    }

    public function test_invalid_kind_mime_size_digest_and_path_are_rejected(): void
    {
        $report = IntelligenceReport::factory()->create();
        $valid = $this->artifactRow($report);

        $invalidRows = [
            ['kind' => 'docx'],
            ['mime' => 'application/octet-stream'],
            ['kind' => 'xlsx', 'mime' => 'application/pdf'],
            ['size_bytes' => 0],
            ['size_bytes' => 10 * 1024 * 1024 + 1],
            ['sha256' => str_repeat('a', 63)],
            ['sha256' => str_repeat('g', 64)],
            ['sha256' => strtoupper(str_repeat('a', 64))],
            ['storage_path' => ''],
        ];
        foreach ($invalidRows as $override) {
            $this->assertMysqlRejects(3819, fn () => DB::table(self::TABLE)->insert([...$valid, ...$override]));
        }

        DB::table(self::TABLE)->insert([...$valid, 'size_bytes' => 10 * 1024 * 1024]);
        $this->assertDatabaseCount(self::TABLE, 1);
    }

    public function test_one_artifact_per_kind_per_report_and_unique_storage_location(): void
    {
        $report = IntelligenceReport::factory()->create();
        $pdf = IntelligenceArtifact::factory()->for($report, 'report')->create();

        $this->assertMysqlRejects(1062, fn () => IntelligenceArtifact::factory()->for($report, 'report')->create());
        $this->assertMysqlRejects(1062, fn () => IntelligenceArtifact::factory()->for($report, 'report')->create(['storage_path' => $pdf->storage_path]));

        $xlsx = IntelligenceArtifact::factory()->for($report, 'report')->xlsx()->create();
        $other = IntelligenceReport::factory()->create();
        IntelligenceArtifact::factory()->for($other, 'report')->create(['storage_path' => 'intelligence/artifacts/'.Str::uuid().'.pdf']);

        $this->assertSame([IntelligenceArtifact::PDF, IntelligenceArtifact::XLSX], $report->artifacts()->orderBy('kind')->pluck('kind')->all());
        $this->assertSame($xlsx->id, $report->artifacts()->where('kind', IntelligenceArtifact::XLSX)->sole()->id);
    }

    public function test_artifact_must_reference_the_same_report_and_event_pair(): void
    {
        $first = IntelligenceReport::factory()->create();
        $second = IntelligenceReport::factory()->create();

        $this->assertMysqlRejects(1452, fn () => DB::table(self::TABLE)->insert($this->artifactRow($first, ['event_id' => $second->event_id])));
        $this->assertMysqlRejects(1452, fn () => DB::table(self::TABLE)->insert($this->artifactRow($first, ['report_id' => 999999])));
    }

    public function test_artifacts_are_append_only_at_the_model_and_database_level(): void
    {
        $artifact = IntelligenceArtifact::factory()->create(['size_bytes' => 2048]);

        $this->assertMysqlRejects(1644, fn () => DB::table(self::TABLE)->where('id', $artifact->id)->update(['size_bytes' => 1]));
        $this->assertMysqlRejects(1644, fn () => DB::table(self::TABLE)->where('id', $artifact->id)->delete());
        $this->assertThrows(fn () => $artifact->forceFill(['size_bytes' => 1])->save(), LogicException::class);
        $this->assertThrows(fn () => $artifact->delete(), LogicException::class);

        $this->assertSame(2048, (int) DB::table(self::TABLE)->where('id', $artifact->id)->value('size_bytes'));
    }

    public function test_report_artifact_status_defaults_to_pending_and_only_moves_forward_once(): void
    {
        $report = IntelligenceReport::factory()->create();
        $before = DB::table('intelligence_reports')->where('id', $report->id)->sole();
        $this->assertSame(IntelligenceReport::ARTIFACT_PENDING, $before->artifact_status);

        $this->assertMysqlRejects(3819, fn () => DB::table('intelligence_reports')->where('id', $report->id)->update(['artifact_status' => 'unknown']));

        $report->forceFill(['artifact_status' => IntelligenceReport::ARTIFACT_COMPLETE])->save();
        $after = DB::table('intelligence_reports')->where('id', $report->id)->sole();
        $this->assertSame(IntelligenceReport::ARTIFACT_COMPLETE, $after->artifact_status);
        $this->assertSame($before->report, $after->report, 'JSON laporan tidak boleh berubah saat status artefak diperbarui.');
        $this->assertSame($before->report_hash, $after->report_hash);

        $this->assertMysqlRejects(1644, fn () => DB::table('intelligence_reports')->where('id', $report->id)->update(['artifact_status' => IntelligenceReport::ARTIFACT_PARTIAL]));
    }

    public function test_report_identity_and_json_remain_immutable_at_the_database_level(): void
    {
        $report = IntelligenceReport::factory()->create();

        $this->assertMysqlRejects(1644, fn () => DB::table('intelligence_reports')->where('id', $report->id)->update(['report' => '{"lain":true}']));
        $this->assertMysqlRejects(1644, fn () => DB::table('intelligence_reports')->where('id', $report->id)->update(['report_hash' => str_repeat('b', 64)]));
        $this->assertMysqlRejects(1644, fn () => DB::table('intelligence_reports')->where('id', $report->id)->delete());
        $this->assertThrows(fn () => $report->forceFill(['report_hash' => str_repeat('c', 64)])->save(), LogicException::class);
        $this->assertDatabaseCount('intelligence_reports', 1);
    }

    public function test_report_exposes_artifacts_and_summary_includes_artifact_status(): void
    {
        $report = IntelligenceReport::factory()->create();
        IntelligenceArtifact::factory()->for($report, 'report')->create();

        $this->assertCount(1, $report->artifacts);
        $this->assertSame($report->id, $report->artifacts->first()->report->id);
        $this->assertContains('artifact_status', IntelligenceReport::SUMMARY_COLUMNS);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function artifactRow(IntelligenceReport $report, array $overrides = []): array
    {
        return [
            'report_id' => $report->id,
            'event_id' => $report->event_id,
            'kind' => 'pdf',
            'storage_disk' => 'local',
            'storage_path' => 'intelligence/artifacts/'.Str::uuid().'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => str_repeat('a', 64),
            'created_at' => now(),
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
