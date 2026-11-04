<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class AuthService
{
    public function __construct(
        private readonly Hasher $hasher,
        private readonly DatabaseManager $database,
    ) {}

    public function register(array $data): array
    {
        try {
            return $this->database->transaction(function () use ($data): array {
                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $this->hasher->make($data['password']),
                ]);

                return $this->issueToken($user);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Email sudah digunakan.']]);
        }
    }

    public function login(array $data): array
    {
        $user = User::query()->where('email', $data['email'])->first();
        if ($user === null || ! $this->hasher->check($data['password'], $user->password)) {
            throw new UnauthorizedHttpException('Bearer', 'Email atau kata sandi salah.');
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
