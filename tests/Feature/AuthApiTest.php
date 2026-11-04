<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_register_returns_201_normalizes_email_and_hashes_password_and_token(): void
    {
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
}
