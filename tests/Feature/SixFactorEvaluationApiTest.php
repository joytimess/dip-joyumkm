<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Question;
use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use stdClass;
use Tests\TestCase;

class SixFactorEvaluationApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const FACTORS = [
        'sumber_daya', 'kepemimpinan', 'lingkungan_kerja', 'nilai_organisasi', 'stabilitas', 'kinerja',
    ];

    private const NOT_FOUND = ['message' => 'Data tidak ditemukan.'];

    public function test_empty_reads_do_not_write(): void
    {
        $base = $this->endpoint($this->ownedUmkm());
        $this->getJson($base.'/draft')->assertOk()->assertExactJson(['data' => null]);
        $this->getJson($base.'/latest')->assertOk()->assertExactJson(['data' => null]);
        $this->assertSame('{"data":[]}', $this->getJson($base)->assertOk()->getContent());
        $this->assertDatabaseEmpty('six_factor_evaluations');
    }

    public function test_p0_rows_without_creation_timestamps_can_be_read_and_finalized(): void
    {
        $umkm = $this->ownedUmkm();
        $base = $this->endpoint($umkm);
        $id = DB::table('six_factor_evaluations')->insertGetId([
            'umkm_id' => $umkm->id,
            ...array_fill_keys(self::FACTORS, 4),
        ]);
        $this->getJson($base.'/draft')->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.created_at', null)->assertJsonPath('data.updated_at', null);

        $final = $this->rawJson('POST', $base.'/'.$id.'/finalize', '{}')->assertOk()
            ->assertJsonPath('data.id', $id)->assertJsonPath('data.created_at', null)->json('data');
        $this->assertUtc($final['finalized_at']);
        $this->assertUtc($final['updated_at']);
        $this->getJson($base.'/latest')->assertOk()->assertExactJson(['data' => $final]);
    }

    #[TestWith(['PUT', ''])]
    #[TestWith(['PATCH', '{}'])]
    #[TestWith(['PUT', '{"scores":{}}'])]
    public function test_empty_draft_payload_creates_once_and_ignores_query_scores(string $method, string $body): void
    {
        $umkm = $this->ownedUmkm();
        $url = $this->endpoint($umkm).'/draft?scores[kinerja]=5';
        $data = $this->rawJson($method, $url, $body)->assertCreated()->json('data');

        $this->assertSame([
            'id' => $data['id'], 'umkm_id' => $umkm->id, 'status' => 'draft',
            'scores' => array_fill_keys(self::FACTORS, null), 'total_score' => null, 'category' => null,
            'finalized_at' => null, 'cancelled_at' => null,
            'created_at' => $data['created_at'], 'updated_at' => $data['updated_at'],
        ], $data);
        $this->assertIsInt($data['id']);
        $this->assertUtc($data['created_at']);
        $this->assertUtc($data['updated_at']);
        $this->rawJson($method, $url, $body)->assertOk()->assertExactJson(['data' => $data]);
        $this->assertDatabaseCount('six_factor_evaluations', 1);
    }

    public function test_put_and_patch_merge_only_sent_factors_and_preserve_explicit_null(): void
    {
        $url = $this->endpoint($this->ownedUmkm()).'/draft';
        $id = $this->putJson($url, ['scores' => ['sumber_daya' => 4, 'kepemimpinan' => 3]])
            ->assertCreated()->json('data.id');
        $draft = $this->patchJson($url, ['scores' => ['kepemimpinan' => null, 'kinerja' => 5]])
            ->assertOk()->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.scores', [
                'sumber_daya' => 4, 'kepemimpinan' => null, 'lingkungan_kerja' => null,
                'nilai_organisasi' => null, 'stabilitas' => null, 'kinerja' => 5,
            ])->json('data');
        $this->rawJson('PUT', $url, '{"scores":{}}')->assertOk()->assertExactJson(['data' => $draft]);
        $this->getJson($url)->assertOk()->assertExactJson(['data' => $draft]);
        $this->assertDatabaseCount('six_factor_evaluations', 1);
    }

    #[TestWith(['{', 'body'])]
    #[TestWith([' ', 'body'])]
    #[TestWith(['null', 'body'])]
    #[TestWith(['[]', 'body'])]
    #[TestWith(['{"scores":[]}', 'scores'])]
    #[TestWith(['{"scores":[1]}', 'scores'])]
    #[TestWith(['{"scores":null}', 'scores'])]
    #[TestWith(['{"scores":"x"}', 'scores'])]
    #[TestWith(['{"scores":{"kinerja":"4"}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":""}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":" "}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":true}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":false}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":4.0}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":1e0}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":3.2}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":0}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":6}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":{}}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"kinerja":[]}}', 'scores.kinerja'])]
    #[TestWith(['{"scores":{"asing":null}}', 'scores.asing'])]
    #[TestWith(['{"scores":{"total_score":null}}', 'scores.total_score'])]
    #[TestWith(['{"status":null}', 'status'])]
    #[TestWith(['{"scores":{},"active_draft_umkm_id":null}', 'active_draft_umkm_id'])]
    #[TestWith(['{"sumber_daya":4}', 'sumber_daya'])]
    #[TestWith(['{"0":null}', '0'])]
    #[TestWith(['{"scores":{"kinerja.asing":null}}', 'scores.kinerja.asing'])]
    public function test_invalid_payload_leaves_empty_and_existing_drafts_unchanged(string $body, string $key): void
    {
        $url = $this->endpoint($this->ownedUmkm()).'/draft';
        $this->assertInvalid($this->rawJson('PUT', $url, $body), $key);
        $this->assertDatabaseEmpty('six_factor_evaluations');
        $this->putJson($url, ['scores' => ['kinerja' => 3]])->assertCreated();
        $before = $this->snapshot();
        $this->assertInvalid($this->rawJson('PATCH', $url, $body), $key);
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith([[1, 1, 1, 1, 1, 1], 6, 'Kurang Sehat'])]
    #[TestWith([[2, 2, 2, 2, 2, 2], 12, 'Kurang Sehat'])]
    #[TestWith([[3, 2, 2, 2, 2, 2], 13, 'Cukup Sehat'])]
    #[TestWith([[3, 3, 3, 3, 3, 3], 18, 'Cukup Sehat'])]
    #[TestWith([[4, 3, 3, 3, 3, 3], 19, 'Sehat'])]
    #[TestWith([[4, 4, 4, 4, 4, 4], 24, 'Sehat'])]
    #[TestWith([[5, 4, 4, 4, 4, 4], 25, 'Sangat Sehat'])]
    #[TestWith([[5, 5, 5, 5, 5, 5], 30, 'Sangat Sehat'])]
    public function test_finalize_returns_the_same_id_and_server_calculated_boundary(array $values, int $total, string $category): void
    {
        $this->travelTo(now()->setDate(2026, 11, 20)->setTime(8, 0, 0));
        $base = $this->endpoint($this->ownedUmkm());
        $scores = array_combine(self::FACTORS, $values);
        $draft = $this->putJson($base.'/draft', ['scores' => $scores])->assertCreated()->json('data');
        $this->rawJson('POST', $base.'/'.$draft['id'].'/finalize', '{}')->assertOk()
            ->assertExactJson(['data' => array_replace($draft, [
                'status' => 'final', 'total_score' => $total, 'category' => $category,
                'finalized_at' => '2026-11-20T08:00:00.000000Z',
            ])])->assertJsonPath('data.total_score', $total)->assertJsonPath('data.scores', $scores);
        $this->getJson($base.'/draft')->assertOk()->assertExactJson(['data' => null]);
    }

    public function test_partial_finalization_reports_missing_keys_and_keeps_the_row(): void
    {
        $base = $this->endpoint($this->ownedUmkm());
        $id = $this->putJson($base.'/draft', ['scores' => ['kinerja' => 5]])->assertCreated()->json('data.id');
        $before = $this->snapshot();
        $error = $this->rawJson('POST', $base.'/'.$id.'/finalize', '');
        $this->assertInvalid($error, 'scores');
        foreach (array_slice(self::FACTORS, 0, 5) as $factor) {
            $this->assertStringContainsString($factor, $error->json('errors.scores.0'));
        }
        $this->assertStringNotContainsString('kinerja', $error->json('errors.scores.0'));
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['finalize', '{', 'body'])]
    #[TestWith(['cancel', '{', 'body'])]
    #[TestWith(['finalize', '[]', 'body'])]
    #[TestWith(['cancel', 'null', 'body'])]
    #[TestWith(['finalize', '{"scores":{}}', 'scores'])]
    #[TestWith(['cancel', '{"cancelled_at":null}', 'cancelled_at'])]
    #[TestWith(['finalize', '{"_method":"DELETE"}', '_method'])]
    #[TestWith(['cancel', '{"_method":[]}', '_method'])]
    public function test_terminal_payload_is_validated_before_evaluation_lookup(string $action, string $body, string $key): void
    {
        $base = $this->endpoint($this->ownedUmkm());
        $id = $this->putJson($base.'/draft', ['scores' => array_fill_keys(self::FACTORS, 4)])
            ->assertCreated()->json('data.id');
        $before = $this->snapshot();
        $this->assertInvalid($this->rawJson('POST', $base.'/'.$id.'/'.$action, $body), $key);
        $this->assertInvalid($this->rawJson('POST', $base.'/999999/'.$action, $body), $key);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_method_override_cannot_change_six_factor_routes(): void
    {
        $base = $this->endpoint($this->ownedUmkm());
        $this->withHeader('X-HTTP-Method-Override', 'PUT');
        $this->rawJson('POST', $base.'/draft', '{}')->assertMethodNotAllowed();
        $id = $this->rawJson('PUT', $base.'/draft', '{}')->assertCreated()->json('data.id');
        $this->withHeader('X-HTTP-Method-Override', 'DELETE');
        $this->rawJson('POST', $base.'/'.$id.'/cancel', '{}')->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->withoutHeader('X-HTTP-Method-Override');
        $this->rawJson('POST', $base.'/'.$id.'/cancel?_method=DELETE', '{}')->assertConflict();
    }

    #[TestWith(['finalize', 'final', 'Evaluasi sudah Final dan tidak dapat diubah.'])]
    #[TestWith(['cancel', 'cancelled', 'Evaluasi sudah dibatalkan dan tidak dapat diubah.'])]
    public function test_terminal_row_is_immutable_and_old_id_cannot_touch_new_draft(string $action, string $status, string $message): void
    {
        $base = $this->endpoint($this->ownedUmkm());
        $scores = $action === 'finalize' ? array_fill_keys(self::FACTORS, 3) : ['sumber_daya' => 4];
        $draft = $this->putJson($base.'/draft', ['scores' => $scores])->assertCreated()->json('data');
        $terminal = $this->rawJson('POST', $base.'/'.$draft['id'].'/'.$action, '')
            ->assertOk()->assertJsonPath('data.id', $draft['id'])->assertJsonPath('data.status', $status)
            ->assertJsonPath('data.scores', $draft['scores'])->json('data');
        if ($status === 'cancelled') {
            $this->assertUtc($terminal['cancelled_at']);
            foreach (['finalized_at', 'total_score', 'category'] as $key) {
                $this->assertNull($terminal[$key]);
            }
        }
        $this->getJson($base.'/draft')->assertOk()->assertExactJson(['data' => null]);
        $next = $this->rawJson('PATCH', $base.'/draft', '{}')->assertCreated()->json('data');
        $this->assertNotSame($draft['id'], $next['id']);
        $before = $this->snapshot();
        foreach (['finalize', 'cancel'] as $retry) {
            $this->rawJson('POST', $base.'/'.$draft['id'].'/'.$retry, '{}')
                ->assertConflict()->assertExactJson(['message' => $message]);
        }
        $this->getJson($base.'/draft')->assertOk()->assertExactJson(['data' => $next]);
        $this->assertSame($before, $this->snapshot());
    }

    public function test_history_filters_final_by_parent_sorts_ties_and_preserves_legacy(): void
    {
        $umkm = $this->ownedUmkm();
        $other = Umkm::factory()->for($umkm->user)->create();
        $base = $this->endpoint($umkm);
        $scores = ['scores' => array_fill_keys(self::FACTORS, 4)];
        $legacy = $umkm->evaluations()->create(['status' => 'draft']);
        $answer = $legacy->answers()->create(['question_id' => Question::query()->firstOrFail()->id, 'value' => 3]);
        $legacy->refresh();
        $answer->refresh();
        $legacyUrl = '/api/umkms/'.$umkm->id.'/evaluations/latest';
        $legacyResponse = $this->getJson($legacyUrl)->assertOk()->json();

        $this->freezeSecond();
        $first = $this->saveAndFinalize($base, $scores);
        $tied = $this->saveAndFinalize($base, $scores);
        $this->travelTo(now()->subDay());
        $older = $this->saveAndFinalize($base, $scores);
        $this->saveAndFinalize($this->endpoint($other), $scores);
        $cancelled = $this->rawJson('PATCH', $base.'/draft', '')->assertCreated()->json('data.id');
        $this->rawJson('POST', $base.'/'.$cancelled.'/cancel', '{}')->assertOk();
        $this->rawJson('PATCH', $base.'/draft', '')->assertCreated();

        $this->getJson($base)->assertOk()->assertExactJson(['data' => [$tied, $first, $older]]);
        $this->getJson($base.'/latest')->assertOk()->assertExactJson(['data' => $tied]);
        $this->getJson($legacyUrl)->assertOk()->assertExactJson($legacyResponse);
        $this->assertSame($legacy->getRawOriginal(), $legacy->fresh()->getRawOriginal());
        $this->assertSame($answer->getRawOriginal(), $answer->fresh()->getRawOriginal());
        $this->postJson('/api/umkms/'.$umkm->id.'/evaluations', ['status' => 'draft', 'answers' => []])->assertCreated();
    }

    public function test_every_route_requires_sanctum_and_checks_numeric_route_ids_first(): void
    {
        $base = $this->endpoint(Umkm::factory()->create());
        $unauthorized = ['message' => 'Silakan masuk untuk melanjutkan.'];
        foreach (['GET /draft', 'PUT /draft', 'PATCH /draft', 'POST /1/finalize', 'POST /1/cancel', 'GET /latest', 'GET '] as $route) {
            [$method, $suffix] = explode(' ', $route, 2);
            $this->rawJson($method, $base.$suffix, '{')->assertUnauthorized()->assertExactJson($unauthorized);
            $this->withToken('invalid-token');
            $this->rawJson($method, $base.$suffix, '{')->assertUnauthorized()->assertExactJson($unauthorized);
            $this->withoutToken();
            $this->rawJson($method, '/api/umkms/nope/six-factor-evaluations'.$suffix, '{')
                ->assertNotFound()->assertExactJson(self::NOT_FOUND);
        }
        foreach (['finalize', 'cancel'] as $action) {
            $this->rawJson('POST', $base.'/nope/'.$action, '')->assertNotFound()->assertExactJson(self::NOT_FOUND);
        }
        $this->assertDatabaseEmpty('six_factor_evaluations');
    }

    public function test_foreign_and_missing_parents_hide_payload_and_wrong_evaluation_parent_is_404(): void
    {
        $umkm = $this->ownedUmkm();
        $foreign = Umkm::factory()->create();
        $foreignDraft = SixFactorEvaluation::factory()->for($foreign)->create();
        $otherDraft = SixFactorEvaluation::factory()->for(Umkm::factory()->for($umkm->user)->create())->create();
        $before = $this->snapshot();
        foreach ([$this->endpoint($foreign), '/api/umkms/999999/six-factor-evaluations'] as $base) {
            foreach (['', '/draft', '/latest'] as $suffix) {
                $this->getJson($base.$suffix)->assertNotFound()->assertExactJson(self::NOT_FOUND);
            }
            $this->rawJson('PUT', $base.'/draft', '{')->assertNotFound()->assertExactJson(self::NOT_FOUND);
            $this->rawJson('PATCH', $base.'/draft', '{"scores":null}')->assertNotFound()->assertExactJson(self::NOT_FOUND);
            foreach (['finalize', 'cancel'] as $action) {
                $this->rawJson('POST', $base.'/'.$foreignDraft->id.'/'.$action, '{')
                    ->assertNotFound()->assertExactJson(self::NOT_FOUND);
            }
        }
        foreach ([$foreignDraft->id, $otherDraft->id, 999999, '9999999999999999999999'] as $wrongId) {
            foreach (['finalize', 'cancel'] as $action) {
                $this->rawJson('POST', $this->endpoint($umkm).'/'.$wrongId.'/'.$action, '{}')
                    ->assertNotFound()->assertExactJson(self::NOT_FOUND);
            }
        }
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith(['draft'])]
    #[TestWith(['final'])]
    #[TestWith(['cancelled'])]
    public function test_delete_keeps_six_factor_history_in_every_status(string $status): void
    {
        $umkm = $this->ownedUmkm();
        $base = $this->endpoint($umkm);
        $id = $this->putJson($base.'/draft', ['scores' => array_fill_keys(self::FACTORS, 5)])
            ->assertCreated()->json('data.id');
        if ($status !== 'draft') {
            $action = $status === 'final' ? 'finalize' : 'cancel';
            $this->rawJson('POST', $base.'/'.$id.'/'.$action, '')->assertOk();
        }
        $before = $this->snapshot();
        $this->deleteJson('/api/umkms/'.$umkm->id)->assertConflict()->assertExactJson([
            'message' => 'UMKM tidak dapat dihapus karena memiliki riwayat penilaian enam faktor.',
        ]);
        $this->assertModelExists($umkm);
        $this->assertSame($before, $this->snapshot());
    }

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_delete_without_six_factor_history_keeps_legacy_cascade(bool $methodOverride): void
    {
        $umkm = $this->ownedUmkm();
        $legacy = $umkm->evaluations()->create(['status' => 'draft']);
        $answer = $legacy->answers()->create(['question_id' => Question::query()->firstOrFail()->id, 'value' => 1]);
        $path = '/api/umkms/'.$umkm->id;
        $response = $methodOverride ? $this->postJson($path, ['_method' => 'DELETE']) : $this->deleteJson($path);
        $response->assertOk()->assertExactJson([
            'data' => ['message' => 'UMKM dan seluruh evaluasinya berhasil dihapus.'],
        ]);
        $this->assertModelMissing($umkm);
        $this->assertModelMissing($legacy);
        $this->assertModelMissing($answer);
    }

    public function test_write_failure_has_safe_500_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        $base = $this->endpoint($this->ownedUmkm());
        SixFactorEvaluation::created(static function (): never {
            throw new RuntimeException('SQL internal and stack trace sentinel');
        });

        $this->rawJson('PUT', $base.'/draft', '{}')->assertServerError()->assertExactJson([
            'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.',
        ]);
        $this->assertDatabaseEmpty('six_factor_evaluations');
    }

    public function test_delete_does_not_report_unrelated_mysql_restrictions_as_six_factor_history(): void
    {
        $umkm = $this->ownedUmkm();
        Umkm::deleting(static function (Umkm $model): void {
            $model->sector()->firstOrFail()->delete();
        });

        $this->deleteJson('/api/umkms/'.$umkm->id)->assertServerError()->assertExactJson([
            'message' => 'Terjadi kesalahan pada server. Silakan coba lagi.',
        ]);
        $this->assertModelExists($umkm);
    }

    private function ownedUmkm(): Umkm
    {
        $umkm = Umkm::factory()->create();
        $this->withToken($umkm->user->createToken('p1-test')->plainTextToken);

        return $umkm;
    }

    private function endpoint(Umkm $umkm): string
    {
        return '/api/umkms/'.$umkm->id.'/six-factor-evaluations';
    }

    private function rawJson(string $method, string $url, string $body): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->call($method, $url, [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            ...$this->transformHeadersToServerVars($this->defaultHeaders),
        ], $body);
    }

    private function assertInvalid(TestResponse $response, string $key): void
    {
        $response->assertUnprocessable()->assertJsonPath('message', 'Data yang diberikan tidak valid.')
            ->assertJsonValidationErrors($key);
        $this->assertSame(['message', 'errors'], array_keys($response->json()));
        $this->assertInstanceOf(stdClass::class, json_decode($response->getContent(), flags: JSON_THROW_ON_ERROR)->errors);
    }

    private function assertUtc(string $timestamp): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d{6}Z$/', $timestamp);
    }

    private function snapshot(): string
    {
        return DB::table('six_factor_evaluations')->orderBy('id')->get()->toJson(JSON_THROW_ON_ERROR);
    }

    private function saveAndFinalize(string $base, array $scores): array
    {
        $id = $this->putJson($base.'/draft', $scores)->assertCreated()->json('data.id');

        return $this->rawJson('POST', $base.'/'.$id.'/finalize', '{}')->assertOk()->json('data');
    }
}
