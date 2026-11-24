<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\SixFactorEvaluation;
use App\Models\WebhookOutbox;
use App\Services\IntelligenceArtifactContract;
use App\Services\IntelligenceArtifactSignature;
use RuntimeException;

/** Fixture unggah artefak: byte PDF/XLSX sintetis nyata dari P3-07, proyeksi manifest transport, dan penandatangan uji. */
final class IntelligenceArtifactFixture
{
    public const SECRET = 'test-only-artifact-secret-0123456789abcdef-UPLOAD';

    public const SAMPLES = ['complete', 'decline', 'multi-month-history', 'no-previous-month', 'no-three-dimensions'];

    /**
     * @return array{pdf: string, xlsx: string}
     */
    public static function sample(string $name): array
    {
        $dir = base_path('docs/evidence/p3-07/samples/'.$name);
        $pdf = @file_get_contents($dir.'/report.pdf');
        $xlsx = @file_get_contents($dir.'/report.xlsx');
        if ($pdf === false || $xlsx === false) {
            throw new RuntimeException('Sample P3-07 tidak ditemukan: '.$name);
        }

        return ['pdf' => $pdf, 'xlsx' => $xlsx];
    }

    /**
     * Proyeksi manifest transport dari byte aktual.
     *
     * @param  array{pdf: string, xlsx: string}  $files
     * @return list<array{kind: string, mime: string, size_bytes: int, sha256: string}>
     */
    public static function projection(array $files): array
    {
        $entries = [];
        foreach (['pdf', 'xlsx'] as $kind) {
            $entries[] = [
                'kind' => $kind,
                'mime' => IntelligenceArtifactContract::KINDS[$kind],
                'size_bytes' => strlen($files[$kind]),
                'sha256' => hash('sha256', $files[$kind]),
            ];
        }

        return $entries;
    }

    /**
     * @return array{evaluation: SixFactorEvaluation, event: WebhookOutbox}
     */
    public static function finalization(): array
    {
        return IntelligenceCallbackFixture::finalization();
    }

    /**
     * @param  array{pdf: string, xlsx: string}  $files
     * @return array{headers: array<string, string>, body: string}
     */
    public static function callback(SixFactorEvaluation $evaluation, WebhookOutbox $event, array $files, array $overrides = []): array
    {
        return IntelligenceCallbackFixture::signed(
            IntelligenceCallbackFixture::payload($evaluation, $event, ['artifacts' => self::projection($files), ...$overrides]),
        );
    }

    /**
     * @return array<string, string> header HTTP_* / CONTENT_* untuk TestCase::call()
     */
    public static function server(string $eventId, string $kind, string $body, ?int $timestamp = null, ?string $secret = null): array
    {
        $server = IntelligenceCallbackFixture::server(
            IntelligenceArtifactSignature::headers($secret ?? self::SECRET, $eventId, $kind, $body, $timestamp),
        );
        $server['CONTENT_LENGTH'] = $server['HTTP_CONTENT_LENGTH'];
        unset($server['HTTP_CONTENT_LENGTH']);

        return $server;
    }

    public static function path(string $eventId, string $kind): string
    {
        return IntelligenceArtifactSignature::path($eventId, $kind);
    }
}
