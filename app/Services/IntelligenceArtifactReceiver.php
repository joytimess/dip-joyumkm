<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\IntelligenceArtifact;
use App\Models\IntelligenceArtifactManifest;
use App\Models\IntelligenceReport;
use App\Models\WebhookOutbox;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemException;
use Throwable;

/**
 * Penerima artefak: streaming berbatas ke staging, verifikasi digest/manifest/format, publikasi tanpa overwrite
 * di disk privat, lalu registrasi append-only di bawah kunci baris laporan.
 *
 * Urutan kunci: (1) baris intelligence_reports FOR UPDATE; (2) insert intelligence_artifacts. Tidak ada kunci lain.
 */
class IntelligenceArtifactReceiver
{
    public const STORED = 'stored';

    public const DUPLICATE = 'duplicate';

    /** Disk privat khusus artefak (config/filesystems.php); BUKAN disk 'local' bawaan. */
    public const DISK = 'intelligence';

    private const CHUNK = 65536;

    public function __construct(private readonly IntelligenceArtifactFormatValidator $formats) {}

    /**
     * @param  resource  $stream
     * @return array{result: string, recovered: bool, report: IntelligenceReport, kind: string, sha256: string, size_bytes: int}
     *
     * @throws IntelligenceArtifactUploadException
     */
    public function receive(string $eventId, string $kind, $stream, string $signedSha256, int $signedLength): array
    {
        [$report, $manifest] = $this->resolveBinding($eventId);
        $expected = $manifest->entryFor($kind);
        if ($expected === null) {
            throw new IntelligenceArtifactUploadException(409, 'manifest_required', 'Manifest artefak untuk jenis ini tidak tersedia.');
        }

        $staging = $this->stage($stream, $signedLength);
        try {
            ['sha256' => $sha, 'size' => $size] = $staging;
            if ($size !== $signedLength) {
                throw new IntelligenceArtifactUploadException(422, 'size_mismatch', 'Ukuran body tidak sesuai Content-Length.');
            }
            if (! hash_equals($signedSha256, $sha)) {
                throw new IntelligenceArtifactUploadException(422, 'checksum_mismatch', 'Digest body tidak sesuai tanda tangan.');
            }

            return $this->register($report, $kind, $expected, $staging['path'], $sha, $size);
        } finally {
            if (is_file($staging['path'])) {
                @unlink($staging['path']);
            }
        }
    }

    /** Verifikasi file tersimpan terhadap baris DB: ada, ukuran sama, SHA-256 sama. */
    public function verifyStored(IntelligenceArtifact $artifact): bool
    {
        $path = $this->absolutePath($artifact->storage_path);

        return is_file($path) && ! is_link($path) && filesize($path) === $artifact->size_bytes
            && hash_equals($artifact->sha256, (string) hash_file('sha256', $path));
    }

    /** Return the verified bytes themselves so a later path replacement cannot change the response. */
    public function verifiedBytes(IntelligenceArtifact $artifact): ?string
    {
        if ($artifact->storage_disk !== self::DISK || $artifact->size_bytes < 1
            || $artifact->size_bytes > IntelligenceArtifactContract::MAX_BYTES
            || ! preg_match('/\A[0-9a-f-]{36}\/[0-9a-f]{64}\.(pdf|xlsx)\z/', $artifact->storage_path)) {
            return null;
        }
        $path = $this->absolutePath($artifact->storage_path);
        $root = realpath($this->root());
        $directory = dirname($path);
        if ($root === false || is_link($this->root()) || is_link($directory) || is_link($path)
            || realpath($directory) !== $root.DIRECTORY_SEPARATOR.basename($directory)
            || ! $this->verifyStored($artifact)) {
            return null;
        }
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        try {
            $bytes = stream_get_contents($handle, IntelligenceArtifactContract::MAX_BYTES + 1);
        } finally {
            fclose($handle);
        }

        return is_string($bytes) && strlen($bytes) === $artifact->size_bytes
            && hash_equals($artifact->sha256, hash('sha256', $bytes)) ? $bytes : null;
    }

    /**
     * @return array{0: IntelligenceReport, 1: IntelligenceArtifactManifest}
     */
    private function resolveBinding(string $eventId): array
    {
        $report = IntelligenceReport::query()->where('event_id', $eventId)->first();
        if ($report === null) {
            $known = WebhookOutbox::query()->where('event_id', $eventId)->exists();
            throw new IntelligenceArtifactUploadException(404, $known ? 'report_not_found' : 'event_not_found', 'Data tidak ditemukan.');
        }

        $manifest = IntelligenceArtifactManifest::query()->where('report_id', $report->id)->first();
        if ($manifest === null) {
            throw new IntelligenceArtifactUploadException(409, 'manifest_required', 'Laporan ini tidak memiliki manifest artefak.');
        }

        return [$report, $manifest];
    }

    /**
     * @param  resource  $stream
     * @return array{path: string, sha256: string, size: int}
     */
    private function stage($stream, int $declaredLength): array
    {
        $dir = $this->root().DIRECTORY_SEPARATOR.'.staging';
        $this->ensureDirectory($dir);
        $path = $dir.DIRECTORY_SEPARATOR.bin2hex(random_bytes(16)).'.part';

        $handle = @fopen($path, 'xb');
        if ($handle === false) {
            throw $this->storageFailure();
        }

        $hash = hash_init('sha256');
        $size = 0;
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, self::CHUNK);
                if ($chunk === false) {
                    throw new IntelligenceArtifactUploadException(422, 'invalid_artifact', 'Body tidak dapat dibaca.');
                }
                if ($chunk === '') {
                    continue;
                }
                $size += strlen($chunk);
                if ($size > IntelligenceArtifactContract::MAX_BYTES) {
                    throw new IntelligenceArtifactUploadException(413, 'artifact_too_large', 'Artefak melebihi batas ukuran.');
                }
                if ($size > $declaredLength) {
                    throw new IntelligenceArtifactUploadException(422, 'size_mismatch', 'Ukuran body melebihi Content-Length.');
                }
                hash_update($hash, $chunk);
                if (@fwrite($handle, $chunk) !== strlen($chunk)) {
                    throw $this->storageFailure();
                }
            }
            if (! @fflush($handle)) {
                throw $this->storageFailure();
            }
        } catch (Throwable $e) {
            fclose($handle);
            @unlink($path);
            throw $e;
        }
        fclose($handle);

        return ['path' => $path, 'sha256' => hash_final($hash), 'size' => $size];
    }

    /**
     * @param  array{kind: string, mime: string, size_bytes: int, sha256: string}  $expected
     * @return array{result: string, recovered: bool, report: IntelligenceReport, kind: string, sha256: string, size_bytes: int}
     */
    private function register(IntelligenceReport $report, string $kind, array $expected, string $stagedPath, string $sha, int $size): array
    {
        $existingPrecheck = IntelligenceArtifact::query()->where('report_id', $report->id)->where('kind', $kind)->first();
        if ($existingPrecheck !== null && $existingPrecheck->sha256 !== $sha) {
            throw new IntelligenceArtifactUploadException(409, 'artifact_conflict', 'Artefak lain sudah terdaftar untuk jenis ini.');
        }
        if ($sha !== $expected['sha256'] || $size !== $expected['size_bytes']) {
            throw new IntelligenceArtifactUploadException(422, 'manifest_mismatch', 'Artefak tidak sesuai manifest yang diharapkan.');
        }
        if (($reason = $this->formats->validate($kind, $stagedPath)) !== null) {
            throw new IntelligenceArtifactUploadException(422, 'invalid_artifact', 'Struktur artefak tidak valid ('.$reason.').');
        }

        try {
            return DB::transaction(function () use ($report, $kind, $expected, $stagedPath, $sha, $size): array {
                $locked = IntelligenceReport::query()->whereKey($report->id)->lockForUpdate()->firstOrFail();
                $existing = IntelligenceArtifact::query()->where('report_id', $locked->id)->where('kind', $kind)->first();
                $recovered = false;

                if ($existing !== null && $existing->sha256 !== $sha) {
                    throw new IntelligenceArtifactUploadException(409, 'artifact_conflict', 'Artefak lain sudah terdaftar untuk jenis ini.');
                }

                if ($existing !== null) {
                    $result = self::DUPLICATE;
                    if (! $this->verifyStored($existing)) {
                        Log::warning('intelligence.artifact.integrity_incident', ['event_id' => $locked->event_id, 'kind' => $kind]);
                        $this->publish($stagedPath, $existing->storage_path, $sha, $size);
                        $recovered = true;
                    }
                } else {
                    $relative = $locked->event_id.'/'.$sha.'.'.$kind;
                    $this->publish($stagedPath, $relative, $sha, $size);
                    (new IntelligenceArtifact)->forceFill([
                        'report_id' => $locked->id,
                        'event_id' => $locked->event_id,
                        'kind' => $kind,
                        'storage_disk' => self::DISK,
                        'storage_path' => $relative,
                        'mime' => $expected['mime'],
                        'size_bytes' => $size,
                        'sha256' => $sha,
                        'created_at' => now()->startOfSecond(),
                    ])->save();
                    $result = self::STORED;
                }

                $this->reconcileStatus($locked);

                return [
                    'result' => $result, 'recovered' => $recovered, 'report' => $locked->refresh(),
                    'kind' => $kind, 'sha256' => $sha, 'size_bytes' => $size,
                ];
            }, attempts: 3);
        } catch (QueryException) {
            throw new IntelligenceArtifactUploadException(503, 'temporary_storage_failure', 'Artefak gagal dicatat; coba lagi.');
        }
    }

    /**
     * pending -> partial -> complete berdasarkan baris artefak yang cocok dengan manifest; tidak pernah turun.
     */
    public function reconcileStatus(IntelligenceReport $report): void
    {
        $manifest = IntelligenceArtifactManifest::query()->where('report_id', $report->id)->first();
        if ($manifest === null) {
            return;
        }

        $matching = 0;
        foreach (IntelligenceArtifact::query()->where('report_id', $report->id)->get() as $artifact) {
            $entry = $manifest->entryFor($artifact->kind);
            if ($entry !== null && $entry['sha256'] === $artifact->sha256 && $entry['size_bytes'] === $artifact->size_bytes) {
                $matching++;
            }
        }

        $target = match (true) {
            $matching >= count($manifest->entries()) => IntelligenceReport::ARTIFACT_COMPLETE,
            $matching >= 1 => IntelligenceReport::ARTIFACT_PARTIAL,
            default => IntelligenceReport::ARTIFACT_PENDING,
        };
        $rank = [IntelligenceReport::ARTIFACT_PENDING => 0, IntelligenceReport::ARTIFACT_PARTIAL => 1, IntelligenceReport::ARTIFACT_COMPLETE => 2];
        if ($rank[$target] > $rank[$report->artifact_status]) {
            $report->forceFill(['artifact_status' => $target])->save();
        }
    }

    /** Publikasi file staging ke path final tanpa menimpa file valid yang sudah ada. */
    private function publish(string $stagedPath, string $relative, string $sha, int $size): void
    {
        $final = $this->absolutePath($relative);
        $dir = dirname($final);
        $this->ensureDirectory($dir);
        $root = realpath($this->root());
        $realDir = realpath($dir);
        if ($root === false || $realDir === false || is_link($dir) || ! str_starts_with($realDir.DIRECTORY_SEPARATOR, $root.DIRECTORY_SEPARATOR)) {
            throw $this->storageFailure();
        }

        if (is_file($final) && ! is_link($final) && filesize($final) === $size && hash_equals($sha, (string) hash_file('sha256', $final))) {
            return;
        }
        if (is_link($final)) {
            throw $this->storageFailure();
        }
        if (! @rename($stagedPath, $final)) {
            throw $this->storageFailure();
        }
        $this->restrict($final, 0600);
    }

    private function absolutePath(string $relative): string
    {
        return $this->root().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    private function root(): string
    {
        try {
            return rtrim(Storage::disk(self::DISK)->path(''), '/\\');
        } catch (FilesystemException) {
            throw $this->storageFailure();
        }
    }

    private function ensureDirectory(string $dir): void
    {
        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw $this->storageFailure();
        }
    }

    private function restrict(string $path, int $mode): void
    {
        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($path, $mode);
        }
    }

    private function storageFailure(): IntelligenceArtifactUploadException
    {
        return new IntelligenceArtifactUploadException(503, 'temporary_storage_failure', 'Penyimpanan artefak sementara tidak tersedia.');
    }
}
