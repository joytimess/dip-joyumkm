<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceArtifact;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Fixtures\IntelligenceArtifactFixture as Fixture;
use Tests\Fixtures\InteractsWithIntelligenceArtifacts;
use Tests\TestCase;
use ZipArchive;

/** P4-02: penyimpanan privat, integritas, registrasi append-only, dan pemulihan pada filesystem nyata. */
class IntelligenceArtifactStorageTest extends TestCase
{
    use InteractsWithIntelligenceArtifacts;
    use LazilyRefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpArtifacts();
    }

    protected function tearDown(): void
    {
        IntelligenceArtifact::flushEventListeners();
        $this->tearDownArtifacts();
        parent::tearDown();
    }

    public function test_valid_pdf_and_xlsx_are_stored_byte_identical_with_server_built_paths(): void
    {
        $bound = $this->boundEvent();
        foreach (['pdf', 'xlsx'] as $kind) {
            $this->upload($bound['event_id'], $kind, $bound['files'][$kind])->assertCreated();
            $sha = hash('sha256', $bound['files'][$kind]);
            $row = IntelligenceArtifact::query()->where('kind', $kind)->sole();

            $this->assertSame($bound['event_id'].'/'.$sha.'.'.$kind, $row->storage_path);
            $this->assertSame('intelligence', $row->storage_disk);
            $this->assertSame($sha, $row->sha256);
            $this->assertSame(strlen($bound['files'][$kind]), $row->size_bytes);
            $this->assertSame($bound['files'][$kind], file_get_contents($this->artifactRoot.'/'.$row->storage_path));
        }
        $this->assertSame('complete', $bound['report']->fresh()->artifact_status);
        $this->assertSame([], glob($this->artifactRoot.'/.staging/*') ?: [], 'Staging harus bersih.');
    }

    public function test_upload_with_bytes_that_differ_from_the_manifest_is_rejected(): void
    {
        $bound = $this->boundEvent();
        $other = Fixture::sample('decline');

        $this->upload($bound['event_id'], 'pdf', $other['pdf'])->assertStatus(422)->assertJsonPath('code', 'manifest_mismatch');
        $this->assertDatabaseEmpty('intelligence_artifacts');
        $this->assertSame([], $this->storedFiles());
    }

    /** @return array<string, array{string}> */
    public static function invalidPdfs(): array
    {
        return [
            'tiny garbage' => ['not a pdf'],
            'wrong signature' => [str_repeat('x', 4096)],
        ];
    }

    #[DataProvider('invalidPdfs')]
    public function test_invalid_pdf_bytes_are_rejected_even_when_digest_and_manifest_match(string $bytes): void
    {
        $bound = $this->boundEvent([...Fixture::sample('complete'), 'pdf' => $bytes]);

        $this->upload($bound['event_id'], 'pdf', $bytes)->assertStatus(422)->assertJsonPath('code', 'invalid_artifact');
        $this->assertDatabaseEmpty('intelligence_artifacts');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_truncated_and_unsafe_pdfs_are_rejected(): void
    {
        $pdf = Fixture::sample('complete')['pdf'];
        $unsafe = substr_replace($pdf, "<< /S /JavaScript /JS (app.alert(1)) >>\n", (int) strrpos($pdf, '%%EOF'), 0);

        foreach ([substr($pdf, 0, -3000), $unsafe] as $bytes) {
            $bound = $this->boundEvent([...Fixture::sample('complete'), 'pdf' => $bytes]);
            $this->upload($bound['event_id'], 'pdf', $bytes)->assertStatus(422)->assertJsonPath('code', 'invalid_artifact');
        }
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    /** @return array<string, array{callable(ZipArchive): void}> */
    public static function hostileWorkbooks(): array
    {
        return [
            'path traversal' => [fn (ZipArchive $zip) => $zip->addFromString('../evil.xml', '<x/>')],
            'absolute path' => [fn (ZipArchive $zip) => $zip->addFromString('/etc/evil.xml', '<x/>')],
            'macro project' => [fn (ZipArchive $zip) => $zip->addFromString('xl/vbaProject.bin', 'MZ')],
            'external link part' => [fn (ZipArchive $zip) => $zip->addFromString('xl/externalLinks/externalLink1.xml', '<externalLink/>')],
            'embedded object' => [fn (ZipArchive $zip) => $zip->addFromString('xl/embeddings/oleObject1.bin', 'x')],
            'external relationship' => [fn (ZipArchive $zip) => $zip->addFromString(
                'xl/_rels/workbook.xml.rels',
                '<Relationships><Relationship Id="r1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/externalLink" Target="file:///c:/x.xlsx" TargetMode="External"/></Relationships>',
            )],
            'zip bomb' => [fn (ZipArchive $zip) => $zip->addFromString('xl/worksheets/bomb.xml', str_repeat("\0", 30 * 1024 * 1024))],
        ];
    }

    /** @param callable(ZipArchive): void $mutate */
    #[DataProvider('hostileWorkbooks')]
    public function test_hostile_workbooks_are_rejected(callable $mutate): void
    {
        $bytes = $this->mutatedWorkbook($mutate);
        $bound = $this->boundEvent([...Fixture::sample('complete'), 'xlsx' => $bytes]);

        $this->upload($bound['event_id'], 'xlsx', $bytes)->assertStatus(422)->assertJsonPath('code', 'invalid_artifact');
        $this->assertDatabaseEmpty('intelligence_artifacts');
        $this->assertSame([], $this->storedFiles());
    }

    public function test_non_zip_and_incomplete_workbooks_are_rejected(): void
    {
        $zip = $this->tempFile();
        $archive = new ZipArchive;
        $archive->open($zip, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $archive->addFromString('hello.txt', 'hi');
        $archive->close();

        foreach ([str_repeat('Z', 2048), (string) file_get_contents($zip)] as $bytes) {
            $bound = $this->boundEvent([...Fixture::sample('complete'), 'xlsx' => $bytes]);
            $this->upload($bound['event_id'], 'xlsx', $bytes)->assertStatus(422)->assertJsonPath('code', 'invalid_artifact');
        }
        @unlink($zip);
        $this->assertDatabaseEmpty('intelligence_artifacts');
    }

    public function test_encrypted_workbook_is_rejected(): void
    {
        $bytes = $this->mutatedWorkbook(function (ZipArchive $zip): void {
            $zip->addFromString('xl/secret.xml', '<x/>');
            if (! $zip->setEncryptionName('xl/secret.xml', ZipArchive::EM_AES_256, 'pw')) {
                $this->markTestSkipped('libzip tanpa dukungan enkripsi.');
            }
        });
        $bound = $this->boundEvent([...Fixture::sample('complete'), 'xlsx' => $bytes]);

        $this->upload($bound['event_id'], 'xlsx', $bytes)->assertStatus(422)->assertJsonPath('code', 'invalid_artifact');
    }

    public function test_disk_write_failure_returns_503_without_database_rows(): void
    {
        $bound = $this->boundEvent();
        $blocker = $this->artifactRoot.DIRECTORY_SEPARATOR.'blocker';
        file_put_contents($blocker, 'bukan direktori');
        config(['filesystems.disks.intelligence.root' => $blocker]);
        app('filesystem')->forgetDisk('intelligence');

        $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'])
            ->assertStatus(503)->assertJsonPath('code', 'temporary_storage_failure');
        $this->assertDatabaseEmpty('intelligence_artifacts');
        $this->assertSame('pending', $bound['report']->fresh()->artifact_status);
    }

    public function test_database_failure_after_publish_leaves_a_recoverable_orphan_and_retry_reconciles_it(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        IntelligenceArtifact::creating(fn () => throw new RuntimeException('simulasi crash DB'));

        $this->upload($bound['event_id'], 'pdf', $pdf)->assertStatus(500);
        IntelligenceArtifact::flushEventListeners();

        $this->assertDatabaseEmpty('intelligence_artifacts');
        $orphan = $this->artifactRoot.'/'.$bound['event_id'].'/'.hash('sha256', $pdf).'.pdf';
        $this->assertFileExists($orphan, 'File orphan tidak boleh dihapus otomatis.');
        $this->assertSame([], glob($this->artifactRoot.'/.staging/*') ?: []);

        $this->upload($bound['event_id'], 'pdf', $pdf)->assertCreated()->assertJsonPath('artifact_status', 'partial');
        $this->assertDatabaseCount('intelligence_artifacts', 1);
        $this->assertSame($pdf, file_get_contents($orphan));
    }

    public function test_interrupted_staging_files_are_left_alone_and_do_not_block_uploads(): void
    {
        mkdir($this->artifactRoot.'/.staging', 0700, true);
        $stale = $this->artifactRoot.'/.staging/crashed.part';
        file_put_contents($stale, 'sisa upload terputus');
        $bound = $this->boundEvent();

        $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'])->assertCreated();
        $this->assertFileExists($stale, 'Pembersihan hanya menyasar file staging milik request sendiri.');
    }

    public function test_lost_response_retry_returns_duplicate_without_touching_row_or_file(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $this->upload($bound['event_id'], 'pdf', $pdf)->assertCreated();
        $row = IntelligenceArtifact::query()->sole();
        $path = $this->artifactRoot.'/'.$row->storage_path;
        $mtime = filemtime($path);
        $this->travel(30)->seconds();

        $this->upload($bound['event_id'], 'pdf', $pdf)->assertOk()->assertJsonPath('result', 'duplicate')->assertJsonPath('recovered', false);

        $this->assertSame($row->id, IntelligenceArtifact::query()->sole()->id);
        $this->assertTrue($row->created_at->equalTo(IntelligenceArtifact::query()->sole()->created_at));
        $this->assertSame($mtime, filemtime($path));
    }

    public function test_row_with_missing_file_is_recovered_from_matching_bytes_not_reported_as_plain_duplicate(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $this->upload($bound['event_id'], 'pdf', $pdf)->assertCreated();
        $path = $this->artifactRoot.'/'.IntelligenceArtifact::query()->sole()->storage_path;
        unlink($path);

        $this->upload($bound['event_id'], 'pdf', $pdf)->assertOk()->assertJsonPath('recovered', true);

        $this->assertSame($pdf, file_get_contents($path));
        $this->assertDatabaseCount('intelligence_artifacts', 1);
    }

    public function test_row_with_corrupted_file_is_recovered_and_row_identity_is_unchanged(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $this->upload($bound['event_id'], 'pdf', $pdf)->assertCreated();
        $before = IntelligenceArtifact::query()->sole();
        $path = $this->artifactRoot.'/'.$before->storage_path;
        file_put_contents($path, 'rusak');

        $this->upload($bound['event_id'], 'pdf', $pdf)->assertOk()->assertJsonPath('recovered', true);

        $this->assertSame($pdf, file_get_contents($path));
        $after = IntelligenceArtifact::query()->sole();
        $this->assertSame([$before->id, $before->sha256, $before->storage_path], [$after->id, $after->sha256, $after->storage_path]);
    }

    public function test_conflicting_upload_for_the_same_kind_is_rejected_and_keeps_the_original(): void
    {
        $bound = $this->boundEvent();
        $pdf = $bound['files']['pdf'];
        $this->upload($bound['event_id'], 'pdf', $pdf)->assertCreated();
        $other = Fixture::sample('decline')['pdf'];

        $this->upload($bound['event_id'], 'pdf', $other)->assertStatus(409)->assertJsonPath('code', 'artifact_conflict');

        $row = IntelligenceArtifact::query()->sole();
        $this->assertSame(hash('sha256', $pdf), $row->sha256);
        $this->assertSame($pdf, file_get_contents($this->artifactRoot.'/'.$row->storage_path));
        $this->assertCount(1, $this->storedFiles());
    }

    public function test_same_bytes_for_different_events_are_bound_to_their_own_event_only(): void
    {
        $files = Fixture::sample('complete');
        $a = $this->boundEvent($files);
        $b = $this->boundEvent($files);

        $this->upload($a['event_id'], 'pdf', $files['pdf'])->assertCreated();
        $this->upload($b['event_id'], 'pdf', $files['pdf'])->assertCreated();

        $this->assertDatabaseCount('intelligence_artifacts', 2);
        $this->assertCount(2, $this->storedFiles());
        $this->assertSame(2, IntelligenceArtifact::query()->distinct()->count('storage_path'));
        $this->assertSame('partial', $a['report']->fresh()->artifact_status);
    }

    public function test_symlinked_event_directory_is_refused_and_nothing_escapes_the_root(): void
    {
        $outside = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jmi-outside-'.bin2hex(random_bytes(4));
        mkdir($outside);
        $bound = $this->boundEvent();
        if (! @symlink($outside, $this->artifactRoot.DIRECTORY_SEPARATOR.$bound['event_id'])) {
            @rmdir($outside);
            $this->markTestSkipped('Symlink tidak tersedia pada lingkungan ini.');
        }

        try {
            $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'])->assertStatus(503);
            $this->assertSame([], glob($outside.'/*') ?: []);
            $this->assertDatabaseEmpty('intelligence_artifacts');
        } finally {
            @rmdir($this->artifactRoot.DIRECTORY_SEPARATOR.$bound['event_id']);
            @unlink($this->artifactRoot.DIRECTORY_SEPARATOR.$bound['event_id']);
            @rmdir($outside);
        }
    }

    public function test_artifact_rows_stay_append_only_at_the_database_level(): void
    {
        $bound = $this->boundEvent();
        $this->upload($bound['event_id'], 'pdf', $bound['files']['pdf'])->assertCreated();

        $this->expectException(QueryException::class);
        DB::table('intelligence_artifacts')->update(['sha256' => str_repeat('a', 64)]);
    }

    /** @param callable(ZipArchive): void $mutate */
    private function mutatedWorkbook(callable $mutate): string
    {
        $path = $this->tempFile();
        file_put_contents($path, Fixture::sample('complete')['xlsx']);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $mutate($zip);
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function tempFile(): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.'jmi-zip-'.bin2hex(random_bytes(6)).'.xlsx';
    }
}
