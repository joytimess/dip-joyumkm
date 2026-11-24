<?php

declare(strict_types=1);

namespace Tests\Fixtures;

use App\Models\IntelligenceReport;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/** Setup bersama: secret uji, disk privat terisolasi di direktori sementara, dan helper callback/unggah. */
trait InteractsWithIntelligenceArtifacts
{
    protected string $artifactRoot;

    protected function setUpArtifacts(): void
    {
        $this->artifactRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR.'jmi-artifacts-'.bin2hex(random_bytes(6));
        mkdir($this->artifactRoot, 0700, true);
        config([
            'filesystems.disks.intelligence.root' => $this->artifactRoot,
            'services.hermes_intelligence_callback.secret' => IntelligenceCallbackFixture::SECRET,
            'services.hermes_intelligence_artifact.secret' => IntelligenceArtifactFixture::SECRET,
        ]);
        Storage::forgetDisk('intelligence');
        Http::preventStrayRequests();
    }

    protected function tearDownArtifacts(): void
    {
        $this->removeTree($this->artifactRoot);
    }

    /**
     * Callback ber-manifest yang berhasil; mengembalikan event dan byte file.
     *
     * @param  array{pdf: string, xlsx: string}|null  $files
     * @return array{event_id: string, files: array{pdf: string, xlsx: string}, report: IntelligenceReport}
     */
    protected function boundEvent(?array $files = null, string $sample = 'complete'): array
    {
        $files ??= IntelligenceArtifactFixture::sample($sample);
        ['evaluation' => $evaluation, 'event' => $event] = IntelligenceArtifactFixture::finalization();
        $request = IntelligenceArtifactFixture::callback($evaluation, $event, $files);
        $this->callbackRequest($request)->assertCreated();

        return ['event_id' => $event->event_id, 'files' => $files, 'report' => IntelligenceReport::query()->where('event_id', $event->event_id)->sole()];
    }

    /** @param array{headers: array<string, string>, body: string} $request */
    protected function callbackRequest(array $request): TestResponse
    {
        return $this->call('POST', IntelligenceCallbackFixture::PATH, [], [], [], IntelligenceCallbackFixture::server($request['headers']), $request['body']);
    }

    protected function upload(string $eventId, string $kind, string $body, ?int $timestamp = null): TestResponse
    {
        return $this->call(
            'PUT', IntelligenceArtifactFixture::path($eventId, $kind), [], [], [],
            [...IntelligenceArtifactFixture::server($eventId, $kind, $body, $timestamp), 'HTTP_ACCEPT' => 'application/json'], $body,
        );
    }

    /** @return list<string> */
    protected function storedFiles(): array
    {
        $files = [];
        foreach (glob($this->artifactRoot.'/*/*.*') ?: [] as $file) {
            if (! str_contains($file, '.staging')) {
                $files[] = $file;
            }
        }

        return $files;
    }

    protected function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = $path.DIRECTORY_SEPARATOR.$entry;
            is_dir($full) && ! is_link($full) ? $this->removeTree($full) : @unlink($full);
        }
        @rmdir($path);
    }
}
