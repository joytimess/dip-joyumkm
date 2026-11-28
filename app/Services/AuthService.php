<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Exceptions\AccountDeactivated;
use App\Jobs\DeliverHermesOnboardingWebhook;
use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthService
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly DatabaseManager $database,
        private readonly QueueFactory $queue,
    ) {}

    public function register(array $data): array
    {
        try {
            return $this->database->transaction(
                fn (): array => $this->issueToken($this->createAccount($data, UserRole::User)),
            );
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Email sudah digunakan.']]);
        }
    }

    /**
     * Pembuatan akun tepercaya: peran ditentukan server, tidak pernah dari input. Wajib dipanggil di dalam transaksi pemanggil.
     * Onboarding `user.registered` v1 memakai antrean yang sama dengan registrasi publik.
     *
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function createAccount(array $data, UserRole $role): User
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $this->hasher->make($data['password']),
        ]);
        if ($role !== UserRole::User) {
            $user->forceFill(['role' => $role->value])->save();
        }

        if (DeliverHermesOnboardingWebhook::enabled()) {
            $queue = $this->queue->connection(DeliverHermesOnboardingWebhook::QUEUE);
            if (! $queue instanceof DatabaseQueue || $queue->getDatabase() !== $user->getConnection()) {
                throw new RuntimeException('Antrean onboarding harus memakai koneksi database registrasi.');
            }

            // The jobs table is the outbox: the worker sees the row only after registration commits.
            $queue->push(DeliverHermesOnboardingWebhook::forRegistration($user), queue: DeliverHermesOnboardingWebhook::QUEUE);
        }

        return $user;
    }

    public function login(array $data): array
    {
        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! $this->hasher->check($data['password'], $user->password)) {
            throw new UnauthorizedHttpException('Bearer', 'Email atau kata sandi salah.');
        }
        if (! $user->isActive()) {
            throw new AccountDeactivated;
        }

        return $this->issueToken($user);
    }

    public function me(User $user): User
    {
        return $user;
    }

    public function logout(User $user): array
    {
        $user->currentAccessToken()?->delete();

        return ['message' => 'Anda berhasil keluar.'];
    }

    private function issueToken(User $user): array
    {
        $expiresAt = now()->addDays(max(1, (int) config('umkm.token_expiration_days')));
        $token = $user->createToken('JoyUMKM', ['*'], $expiresAt);

        return [
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toISOString(),
            'user' => $user,
        ];
    }
}
