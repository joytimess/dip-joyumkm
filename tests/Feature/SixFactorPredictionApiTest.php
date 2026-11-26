<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Psr7\Request as PsrRequest;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class SixFactorPredictionApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    private const URL = 'http://127.0.0.1:8651/webhook/predict';

    private const SECRET = 'jobsheet4-test-signature-key-32-characters';

    private const RESULT = [
        'total_score' => 28,
        'category' => 'Sangat Sehat',
        'recommendation' => 'Pertahankan kinerja dan pantau keenam faktor.',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.hermes_prediction' => [
            'enabled' => true,
            'url' => self::URL,
            'secret' => self::SECRET,
        ]]);
    }

    public function test_owner_previews_with_one_signed_request_and_no_evaluation_write(): void
    {
        $base = $this->ownedEndpoint();
        $this->freezeTime();
        Http::preventStrayRequests()->fake([self::URL => Http::response(['success' => true, ...self::RESULT])]);

        $this->postJson($base.'/predict', ['scores' => $this->scores()])
            ->assertOk()->assertExactJson(['data' => self::RESULT]);
        Http::assertSentCount(1);
        Http::assertSent(function (Request $request): bool {
            $timestamp = (string) now()->getTimestamp();
            $signature = hash_hmac('sha256', $timestamp."\n/webhook/predict\n".$request->body(), self::SECRET);

            return $request->method() === 'POST'
                && $request->url() === self::URL
                && $request->data() === $this->scores()
                && $request->header('X-JoyUMKM-Prediction-Timestamp') === [$timestamp]
                && $request->header('X-JoyUMKM-Prediction-Signature') === [$signature];
        });
        $this->assertDatabaseEmpty('six_factor_evaluations');
        $this->assertDatabaseEmpty('webhook_outbox');
        $this->assertDatabaseEmpty('intelligence_reports');
    }

    public function test_preview_leaves_draft_unchanged_and_existing_finalize_still_works(): void
    {
        $base = $this->ownedEndpoint();
        Http::preventStrayRequests()->fake([self::URL => Http::response(['success' => true, ...self::RESULT])]);
        $draft = $this->putJson($base.'/draft', ['scores' => $this->scores()])->assertCreated()->json('data');
        $stored = SixFactorEvaluation::query()->findOrFail($draft['id'])->getRawOriginal();

        $this->postJson($base.'/predict', ['scores' => $this->scores()])->assertOk();
        $this->assertSame($stored, SixFactorEvaluation::query()->findOrFail($draft['id'])->getRawOriginal());
        $this->assertDatabaseCount('six_factor_evaluations', 1);
        $this->assertDatabaseEmpty('webhook_outbox');
        $this->assertDatabaseEmpty('intelligence_reports');

        $this->postRaw($base.'/'.$draft['id'].'/finalize', '{}')->assertOk()
            ->assertJsonPath('data.status', 'final')
            ->assertJsonPath('data.total_score', 28)
            ->assertJsonPath('data.category', 'Sangat Sehat');
        $this->assertDatabaseCount('webhook_outbox', 1);
        Http::assertSentCount(1);
    }

    public function test_no_auth_returns_401_and_cross_owner_returns_404_before_any_request_to_hermes(): void
    {
        $other = Umkm::factory()->create();
        $url = '/api/umkms/'.$other->id.'/six-factor-evaluations/predict';
        Http::preventStrayRequests();

        $this->postJson($url, ['scores' => $this->scores()])->assertUnauthorized();
        $this->ownedEndpoint();
        $this->app['auth']->forgetGuards();
        $this->postJson($url, ['scores' => $this->scores()])->assertNotFound();
        Http::assertNothingSent();
    }

    #[TestWith(['null'])]
    #[TestWith(['"5"'])]
    #[TestWith(['4.0'])]
    #[TestWith(['5e0'])]
    #[TestWith(['true'])]
    #[TestWith(['6'])]
    public function test_invalid_factor_is_rejected_with_422(string $value): void
    {
        $base = $this->ownedEndpoint();
        Http::preventStrayRequests();
        $body = json_encode(['scores' => $this->scores()], JSON_THROW_ON_ERROR);

        $this->postRaw($base.'/predict', str_replace('"kinerja":5', '"kinerja":'.$value, $body))
            ->assertUnprocessable()->assertJsonValidationErrors('scores.kinerja');
        Http::assertNothingSent();
    }

    public function test_missing_unknown_and_malformed_fields_return_422_without_calling_hermes(): void
    {
        $url = $this->ownedEndpoint().'/predict';
        Http::preventStrayRequests();
        $scores = $this->scores();

        $this->postJson($url, ['scores' => array_diff_key($scores, ['kinerja' => true])])
            ->assertUnprocessable()->assertJsonValidationErrors('scores.kinerja');
        $this->postJson($url, ['scores' => [...$scores, 'unknown' => 4]])
            ->assertUnprocessable()->assertJsonValidationErrors('scores');
        $this->postJson($url, ['scores' => $scores, 'total_score' => 28])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postRaw($url, '{"scores":[')->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($url, ['scores' => array_values($scores)])
            ->assertUnprocessable()->assertJsonValidationErrors('scores');
        Http::assertNothingSent();
    }

    public function test_hermes_validation_error_returns_safe_422(): void
    {
        $base = $this->ownedEndpoint();
        Http::preventStrayRequests()->fake([self::URL => Http::response([
            'success' => false, 'message' => 'upstream implementation detail',
        ], 400)]);

        $this->postJson($base.'/predict', ['scores' => $this->scores()])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('scores')
            ->assertDontSee('upstream implementation detail');
        Http::assertSentCount(1);
    }

    public function test_disabled_prediction_has_no_outbound_request(): void
    {
        $base = $this->ownedEndpoint();
        config(['services.hermes_prediction.enabled' => false]);
        Http::preventStrayRequests();

        $this->postJson($base.'/predict', ['scores' => $this->scores()])->assertServiceUnavailable();
        Http::assertNothingSent();
    }

    public function test_unavailable_receiver_returns_503(): void
    {
        $base = $this->ownedEndpoint();
        Http::preventStrayRequests()->fake([self::URL => Http::failedConnection()]);

        $this->postJson($base.'/predict', ['scores' => $this->scores()])->assertServiceUnavailable();
    }

    public function test_receiver_timeout_returns_504(): void
    {
        $base = $this->ownedEndpoint();
        Http::preventStrayRequests()->fake([self::URL => fn () => throw new NetworkTimeoutException(
            'Timed out',
            new PsrRequest('POST', self::URL),
        )]);

        $this->postJson($base.'/predict', ['scores' => $this->scores()])->assertStatus(504);
        Http::assertSentCount(1);
    }

    public function test_inconsistent_prediction_returns_502(): void
    {
        $base = $this->ownedEndpoint();
        Http::preventStrayRequests()->fake([self::URL => Http::response([
            'success' => true, ...self::RESULT, 'total_score' => 29,
        ])]);

        $this->postJson($base.'/predict', ['scores' => $this->scores()])->assertStatus(502);
        Http::assertSentCount(1);
    }

    private function ownedEndpoint(): string
    {
        $umkm = Umkm::factory()->create();
        $this->withToken($umkm->user->createToken('prediction-test')->plainTextToken);

        return '/api/umkms/'.$umkm->id.'/six-factor-evaluations';
    }

    private function scores(): array
    {
        return array_combine(SixFactorEvaluation::FACTORS, [5, 4, 5, 5, 4, 5]);
    }

    private function postRaw(string $url, string $body): TestResponse
    {
        return $this->call('POST', $url, [], [], [], [
            'HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json',
            ...$this->transformHeadersToServerVars($this->defaultHeaders),
        ], $body);
    }
}
