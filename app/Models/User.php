<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /** Filter pemilik per-request (tidak disimpan); hanya diisi untuk Superadmin. */
    private ?int $ownerFilterId = null;

    public function umkms(): HasMany
    {
        return $this->hasMany(Umkm::class);
    }

    public function userRole(): UserRole
    {
        return UserRole::fromStored($this->getAttribute('role'));
    }

    public function isSuperadmin(): bool
    {
        return $this->userRole() === UserRole::Superadmin;
    }

    public function isAnalyst(): bool
    {
        return $this->userRole() === UserRole::Analyst;
    }

    public function isActive(): bool
    {
        // Atribut belum dimuat (model baru dibuat) berarti default kolom: aktif.
        return (bool) ($this->getAttribute('is_active') ?? true);
    }

    /**
     * Menyempitkan scope Superadmin ke portofolio satu Analyst selama request ini. Tidak berlaku untuk peran lain.
     */
    public function filterToOwner(?int $ownerId): static
    {
        $this->ownerFilterId = $this->isSuperadmin() ? $ownerId : null;

        return $this;
    }

    public function ownerFilterId(): ?int
    {
        return $this->ownerFilterId;
    }

    /**
     * Satu-satunya scope akses UMKM: global untuk Superadmin, milik sendiri untuk Analyst dan User.
     * Pembuatan UMKM tetap lewat umkms() (pemilik = pelaku).
     *
     * @return Builder<Umkm>
     */
    public function accessibleUmkms(): Builder
    {
        if (! $this->isSuperadmin()) {
            return $this->umkms()->getQuery();
        }

        return $this->ownerFilterId === null
            ? Umkm::query()
            : Umkm::query()->where('umkms.user_id', $this->ownerFilterId);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
        ];
    }
}
