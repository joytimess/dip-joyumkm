<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Requests\Intelligence\IntelligenceCallbackRequest;
use App\Services\IntelligenceArtifactContract;
use App\Services\IntelligenceCallbackPayload;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class IntelligenceHermesManifestContractTest extends TestCase
{
    public function test_offline_hermes_projections_match_the_laravel_contract_and_preserve_report_hash(): void
    {
        $root = base_path('docs/evidence');
        $flags = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES;
        $vectors = json_decode(file_get_contents($root.'/p3-05/hash-vectors.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->assertSame(['complete', 'no-previous-month', 'no-three-dimensions', 'decline', 'multi-month-history'], array_keys($vectors));

        foreach ($vectors as $sample => $vector) {
            $manifest = json_decode(file_get_contents($root."/p3-05/samples/{$sample}/manifest.json"), true, 32, JSON_THROW_ON_ERROR);
            $projection = json_decode(file_get_contents($root."/p3-05/samples/{$sample}/transport.json"), true, 32, JSON_THROW_ON_ERROR);
            $this->assertSame(['artifacts'], array_keys($projection), $sample);
            $entries = IntelligenceArtifactContract::normalizeManifest($projection['artifacts']);
            $this->assertSame($projection['artifacts'], $entries, $sample);
            $this->assertSame($vector['artifacts'], $entries, $sample);
            $this->assertSame($vector['transport_artifact_set_hash'], IntelligenceArtifactContract::setHash($entries), $sample);
            $this->assertSame($vector['transport_artifact_set_hash'], $manifest['transport_artifact_set_hash'], $sample);
            $this->assertSame($vector['transport_artifact_set_hash'], IntelligenceArtifactContract::setHash(
                IntelligenceArtifactContract::normalizeManifest(array_reverse($entries)),
            ), $sample);

            $canonical = file_get_contents($root."/p3-02/samples/{$sample}/report.json");
            $this->assertSame(hash('sha256', $canonical), $vector['source_report_sha256'], $sample);
            $this->assertSame($vector['artifact_set_hash'], $manifest['artifact_set_hash'], $sample);
            $this->assertSame($vector['artifact_set_hash'], hash('sha256', json_encode([
                'event_id' => $vector['event_id'],
                'source_report_sha256' => $vector['source_report_sha256'],
                'artifacts' => $entries,
            ], $flags)), $sample);

            $body = json_decode($canonical, false, 32, JSON_THROW_ON_ERROR);
            $this->assertSame($body->event_id, $vector['event_id'], $sample);
            unset($body->monthly_extension);
            $without = IntelligenceCallbackPayload::fromJson(json_encode($body, $flags));
            $body->artifacts = $entries;
            $raw = json_encode($body, $flags);
            $request = new IntelligenceCallbackRequest(content: $raw);
            $validation = Validator::make($request->validationData(), $request->rules());
            $this->assertFalse($validation->fails(), $sample.' '.json_encode($validation->errors()->keys()));
            $this->assertLessThanOrEqual(config('services.hermes_intelligence_callback.max_body_bytes'), strlen($raw));
            $this->assertSame($without->hash(), $request->payload()->hash(), $sample);
        }
    }
}
