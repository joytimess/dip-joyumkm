<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_returns_201_normalizes_email_and_hashes_password_and_token(): void
    {
        config(['services.hermes_onboarding.enabled' => false]);
        $this->freezeTime();
        $response = $this->postJson('/api/auth/register', [
            'name' => ' Pendamping Baru ', 'email' => ' BARU@EXAMPLE.COM ',
            'password' => 'JoyUMKM-Testing-2026', 'password_confirmation' => 'JoyUMKM-Testing-2026',
        ]);

        $response->assertCreated()->assertJsonPath('data.user.email', 'baru@example.com')
            ->assertJsonPath('data.token_type', 'Bearer')->assertJsonMissingPath('data.user.password');
        $user = User::query()->sole();
        $this->assertTrue(Hash::check('JoyUMKM-Testing-2026', $user->password));
        $token = PersonalAccessToken::findToken($response->json('data.token'));
        $this->assertNotNull($token);
        $this->assertSame(now()->addDays(7)->toDateTimeString(), $token->expires_at->toDateTimeString());
        $this->assertSame(64, strlen($token->token));
        $this->assertDatabaseCount('jobs', 0);
    }

    public function test_registration_queues_an_encrypted_event_and_retries_the_same_signed_payload(): void
    {
        $this->enableOnboarding();
        $this->freezeTime();
        $secret = config('services.hermes_onboarding.secret');
        $requests = [];
        Http::preventStrayRequests();
        Http::fake([
            config('services.hermes_onboarding.url') => function (Request $request) use (&$requests) {
                $requests[] = $request;

                return count($requests) === 1
                    ? Http::failedConnection('private receiver detail')($request)
                    : Http::response([
                        'status' => 'duplicate',
                        'event_id' => $request['event_id'],
                        'run_id' => (string) Str::uuid(),
                    ]);
            },
        ]);

        $credentials = [
            'name' => 'Pendamping Baru', 'email' => ' BARU@EXAMPLE.COM ',
            'password' => 'JoyUMKM-Testing-2026', 'password_confirmation' => 'JoyUMKM-Testing-2026',
        ];
        $registration = $this->postJson('/api/auth/register', $credentials)->assertCreated();
        $this->postJson('/api/auth/register', $credentials)->assertUnprocessable();

        Http::assertNothingSent();
        $eventJob = DB::table('jobs')->sole();
        $this->assertSame('hermes-onboarding', $eventJob->queue);
        $this->assertStringNotContainsString('baru@example.com', $eventJob->payload);
        $this->assertDatabaseCount('webhook_outbox', 0);
        $user = User::query()->sole();
        $timestamp = $user->created_at->copy()->utc()->format('Y-m-d\TH:i:s\Z');

        $this->runOnboardingWorker();
        $this->assertDatabaseHas('jobs', ['queue' => 'hermes-onboarding', 'attempts' => 1]);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        $this->travel(16)->seconds();
        $this->runOnboardingWorker();
        $this->assertCount(2, $requests);
        [$first, $second] = $requests;
        $this->assertSame($first->body(), $second->body());
        $this->assertTrue($second->hasHeader('X-JoyUMKM-Signature', 'sha256='.hash_hmac('sha256', $second->body(), $secret)));
        $this->assertTrue(Str::isUuid($second['event_id']));
        $this->assertSame([
            'event_id' => $first['event_id'],
            'event_type' => 'user.registered',
            'event_version' => 1,
            'source' => 'joyumkm',
            'occurred_at' => $timestamp,
            'data' => [
                'user_id' => $registration->json('data.user.id'),
                'name' => 'Pendamping Baru',
                'email' => 'baru@example.com',
                'registered_at' => $timestamp,
            ],
        ], $second->data());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
    }

    public function test_rolling_back_registration_also_rolls_back_the_onboarding_event(): void
    {
        $this->enableOnboarding();
        Http::preventStrayRequests();

        DB::beginTransaction();
        try {
            $this->postJson('/api/auth/register', [
                'name' => 'Pendamping Baru', 'email' => 'rollback@example.com',
                'password' => 'JoyUMKM-Testing-2026', 'password_confirmation' => 'JoyUMKM-Testing-2026',
            ])->assertCreated();
            $this->assertDatabaseHas('jobs', ['queue' => 'hermes-onboarding']);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseCount('jobs', 0);
        Http::assertNothingSent();
    }

    public function test_onboarding_mismatched_ack_fails_without_retry_or_logging_the_response(): void
    {
        $this->enableOnboarding();
        Http::preventStrayRequests();
        Http::fake([
            config('services.hermes_onboarding.url') => Http::response([
                'status' => 'accepted',
                'event_id' => (string) Str::uuid(),
                'run_id' => (string) Str::uuid(),
                'message' => 'private receiver detail',
            ], 202),
        ]);

        $this->postJson('/api/auth/register', [
            'name' => 'Pendamping Baru', 'email' => 'ack-check@example.com',
            'password' => 'JoyUMKM-Testing-2026', 'password_confirmation' => 'JoyUMKM-Testing-2026',
        ])->assertCreated();

        $this->runOnboardingWorker();

        Http::assertSentCount(1);
        $this->assertDatabaseCount('jobs', 0);
        $failedJob = DB::table('failed_jobs')->sole();
        $this->assertSame('hermes-onboarding', $failedJob->queue);
        $this->assertStringContainsString('invalid acknowledgement', $failedJob->exception);
        $this->assertStringNotContainsString('private receiver detail', $failedJob->exception);
        $this->assertStringNotContainsString('ack-check@example.com', $failedJob->payload);
    }

    public function test_login_rejects_wrong_credentials_with_401_without_issuing_token(): void
    {
        $user = User::factory()->create();

        $wrongPassword = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrong-password']);
        $unknownEmail = $this->postJson('/api/auth/login', ['email' => 'unknown@example.com', 'password' => 'wrong-password']);

        $wrongPassword->assertUnauthorized();
        $unknownEmail->assertUnauthorized();
        $this->assertSame($wrongPassword->json('message'), $unknownEmail->json('message'));
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_revokes_only_the_current_token_and_me_returns_the_right_user(): void
    {
        $user = User::factory()->create(['password' => 'JoyUMKM-Testing-2026']);
        $first = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'JoyUMKM-Testing-2026'])
            ->assertOk()->json('data.token');
        $second = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'JoyUMKM-Testing-2026'])
            ->assertOk()->json('data.token');

        $this->app['auth']->forgetGuards();
        $this->withToken($first)->getJson('/api/auth/me')->assertOk()->assertJsonPath('data.id', $user->id);
        $this->app['auth']->forgetGuards();
        $this->withToken($first)->postJson('/api/auth/logout')->assertOk()->assertJsonStructure(['data' => ['message']]);

        $this->assertNull(PersonalAccessToken::findToken($first));
        $this->assertNotNull(PersonalAccessToken::findToken($second));
        $this->app['auth']->forgetGuards();
        $this->withToken($first)->getJson('/api/auth/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($second)->getJson('/api/auth/me')->assertOk();
    }

    public function test_expired_token_returns_401(): void
    {
        $this->freezeTime();
        $user = User::factory()->create();
        $token = $user->createToken('test', ['*'], now()->addDays(7))->plainTextToken;
        $this->travel(8)->days();

        $this->withToken($token)->getJson('/api/auth/me')->assertUnauthorized();
    }

    public function test_api_returns_json_without_accept_header_for_401_and_422(): void
    {
        $this->get('/api/auth/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
        $this->post('/api/auth/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);
        $this->postJson('/api/auth/login', ['email' => ['invalid'], 'password' => 'password'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');
    }

    public function test_login_rate_limit_returns_429_with_retry_header(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/login', ['email' => 'LIMIT@EXAMPLE.COM', 'password' => 'wrong-password'])
                ->assertUnauthorized();
        }

        $this->postJson('/api/auth/login', ['email' => ' limit@example.com ', 'password' => 'wrong-password'])
            ->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_register_rate_limit_returns_429(): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/register', [])->assertUnprocessable();
        }

        $this->postJson('/api/auth/register', [])->assertTooManyRequests();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_api_server_error_hides_exception_details_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        Route::get('/api/test-server-error', function (): never {
            throw new RuntimeException('Internal secret detail');
        });

        $this->getJson('/api/test-server-error')->assertInternalServerError()
            ->assertExactJson(['message' => 'Terjadi kesalahan pada server. Silakan coba lagi.']);
    }

    #[DataProvider('invalidBcryptPasswords')]
    public function test_invalid_bcrypt_password_returns_422_without_creating_user_or_token(string $endpoint, string $password): void
    {
        $this->postJson($endpoint, [
            'name' => 'Pendamping', 'email' => 'password-check@example.com',
            'password' => $password, 'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public static function invalidBcryptPasswords(): array
    {
        return [
            'register null byte' => ['/api/auth/register', "valid\0password2026"],
            'login null byte' => ['/api/auth/login', "valid\0password2026"],
            'register 73 bytes' => ['/api/auth/register', str_repeat('a', 73)],
            'login 73 bytes' => ['/api/auth/login', str_repeat('a', 73)],
            'register 37 unicode characters 74 bytes' => ['/api/auth/register', str_repeat('é', 37)],
            'login 37 unicode characters 74 bytes' => ['/api/auth/login', str_repeat('é', 37)],
        ];
    }

    private function enableOnboarding(): void
    {
        config([
            'services.hermes_onboarding.enabled' => true,
            'services.hermes_onboarding.url' => 'http://127.0.0.1:8650/joyumkm-onboarding',
            'services.hermes_onboarding.secret' => str_repeat('a', 32),
        ]);
    }

    private function runOnboardingWorker(): void
    {
        $this->artisan('queue:work', [
            'connection' => 'hermes-onboarding',
            '--queue' => 'hermes-onboarding',
            '--once' => true,
        ])->assertSuccessful();
    }
}
