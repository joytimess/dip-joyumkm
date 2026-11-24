<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\IntelligenceArtifact;
use App\Models\IntelligenceReport;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\Fixtures\IntelligenceCallbackFixture;
use Tests\Fixtures\InteractsWithIntelligenceArtifacts;
use Tests\TestCase;

class IntelligenceArtifactDownloadTest extends TestCase
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
        $this->tearDownArtifacts();
        parent::tearDown();
    }

    public function test_owner_can_check_status_and_download_verified_bytes_and_corruption_is_rejected(): void
    {
        $bound = $this->boundEvent();
        $report = $bound['report'];
        $owner = $report->umkm->user;
        $url = "/api/umkms/{$report->umkm_id}/intelligence-reports/{$report->id}/artifacts";
        $this->getJson($url)->assertUnauthorized();
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url)->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('artifact_status', 'pending')
            ->assertJsonPath('artifacts.pdf.availability', 'pending');

        foreach (['pdf', 'xlsx'] as $kind) {
            $this->upload($bound['event_id'], $kind, $bound['files'][$kind])->assertCreated();
            if ($kind === 'pdf') {
                $this->actingAs($owner, 'sanctum')->getJson($url)->assertJsonPath('artifact_status', 'partial')
                    ->assertJsonPath('artifacts.xlsx.availability', 'pending');
            }
            $download = $this->actingAs($owner, 'sanctum')->get($url.'/'.$kind, ['Accept' => 'application/json']);
            $download->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
            $this->assertSame($bound['files'][$kind], $download->getContent());
            $this->upload($bound['event_id'], $kind, $bound['files'][$kind])->assertOk()->assertJsonPath('result', 'duplicate');
        }
        $status = $this->actingAs($owner, 'sanctum')->getJson($url)->assertOk()->assertJsonPath('artifact_status', 'complete')
            ->assertJsonPath('artifacts.pdf.availability', 'available');
        $this->assertStringNotContainsString($this->artifactRoot, $status->getContent());
        $this->actingAs(User::factory()->create(), 'sanctum')->getJson($url.'/pdf')->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson($url.'/invalid')->assertNotFound();
        $this->actingAs($owner, 'sanctum')->getJson($url.'/../999999/artifacts')->assertNotFound();
        $artifact = IntelligenceArtifact::query()->where('kind', 'pdf')->sole();
        file_put_contents($this->artifactRoot.'/'.$artifact->storage_path, 'corrupt');
        $this->actingAs($owner, 'sanctum')->getJson($url)->assertJsonPath('artifacts.pdf.availability', 'unavailable');
        $this->actingAs($owner, 'sanctum')->getJson($url.'/pdf')->assertStatus(409)->assertJsonPath('code', 'artifact_unavailable');
        unlink($this->artifactRoot.'/'.$artifact->storage_path);
        $this->actingAs($owner, 'sanctum')->getJson($url.'/pdf')->assertStatus(409);
    }

    public function test_legacy_report_has_no_fabricated_artifact_metadata(): void
    {
        ['evaluation' => $evaluation, 'event' => $event] = IntelligenceCallbackFixture::finalization();
        $this->callbackRequest(IntelligenceCallbackFixture::signed(IntelligenceCallbackFixture::payload($evaluation, $event)))->assertCreated();
        $report = IntelligenceReport::query()->where('event_id', $event->event_id)->sole();
        $url = "/api/umkms/{$report->umkm_id}/intelligence-reports/{$report->id}/artifacts";
        $this->actingAs($report->umkm->user, 'sanctum')->getJson($url)->assertOk()
            ->assertJsonPath('artifacts.pdf.availability', 'not_expected')->assertJsonPath('artifacts.pdf.mime', null);
        $this->getJson($url.'/pdf')->assertNotFound();
    }
}
