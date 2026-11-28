<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Jobs\DeliverHermesOnboardingWebhook;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Manajemen akun Analyst oleh Superadmin. Semua operasi dibatasi pada baris ber-peran Analyst.
 */
class AnalystService
{
    public function __construct(
        private readonly AuthService $authService,
        private readonly DatabaseManager $database,
    ) {}

    /** @param array{search?: ?string, status?: ?string, per_page?: ?int, page?: ?int} $filters */
    public function list(array $filters): LengthAwarePaginator
    {
        $query = $this->withCounts(User::query()->where('role', UserRole::Analyst->value));
        if (($filters['search'] ?? '') !== '') {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $filters['search']);
            $query->where(fn ($inner) => $inner->whereRaw("users.name LIKE ? ESCAPE '!'", ['%'.$search.'%'])
                ->orWhereRaw("users.email LIKE ? ESCAPE '!'", ['%'.$search.'%']));
        }
        if (($filters['status'] ?? null) === 'active') {
            $query->where('is_active', true);
        } elseif (($filters['status'] ?? null) === 'inactive') {
            $query->where('is_active', false);
        }

        return $query->orderBy('users.name')->orderBy('users.id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1))
            ->withQueryString();
    }

    public function find(int $id): User
    {
        return $this->withCounts(User::query()->where('role', UserRole::Analyst->value))->whereKey($id)->firstOrFail();
    }

    /** @param array{name: string, email: string, password: string} $data */
    public function create(array $data): User
    {
        try {
            $user = $this->database->transaction(fn (): User => $this->authService->createAccount($data, UserRole::Analyst));
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Email sudah digunakan.']]);
        }

        return $this->find($user->id);
    }

    /** @param array{name?: string, email?: string} $data */
    public function update(int $id, array $data): User
    {
        try {
            $this->database->transaction(function () use ($id, $data): void {
                $analyst = User::query()->where('role', UserRole::Analyst->value)->whereKey($id)->lockForUpdate()->firstOrFail();
                if (isset($data['email']) && $data['email'] !== $analyst->email) {
                    // Tidak ada mekanisme verifikasi ganti email; hindari mengalihkan onboarding yang masih mengantre.
                    if (DB::table('jobs')->where('queue', DeliverHermesOnboardingWebhook::QUEUE)->exists()) {
                        throw new ConflictHttpException('Email belum dapat diubah selama masih ada onboarding yang mengantre.');
                    }
                    $analyst->email = $data['email'];
                }
                if (isset($data['name'])) {
                    $analyst->name = $data['name'];
                }
                $analyst->save();
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => ['Email sudah digunakan.']]);
        }

        return $this->find($id);
    }

    /**
     * Idempoten. Penonaktifan mencabut seluruh token dalam transaksi yang sama; reaktivasi tidak menghidupkan kredensial lama.
     */
    public function setActive(int $id, bool $active): User
    {
        $this->database->transaction(function () use ($id, $active): void {
            $analyst = User::query()->where('role', UserRole::Analyst->value)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($analyst->isActive() !== $active) {
                $analyst->forceFill(['is_active' => $active])->save();
            }
            if (! $active) {
                $analyst->tokens()->delete();
            }
        });

        return $this->find($id);
    }

    private function withCounts(Builder $query): Builder
    {
        $members = DB::table('analytics_dataset_members')->selectRaw('1')->whereColumn('analytics_dataset_members.umkm_id', 'umkms.id');

        return $query->withCount([
            'umkms as umkms_count',
            'umkms as cohort_umkms_count' => fn ($umkms) => $umkms->whereExists($members),
        ]);
    }
}
