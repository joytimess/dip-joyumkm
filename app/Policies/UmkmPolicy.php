<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Umkm;
use App\Models\User;

/**
 * Otorisasi UMKM terpusat. Superadmin global, Analyst dan User hanya milik sendiri.
 * Tidak melewati aturan domain (consent, skor, finalisasi); itu tetap di layanan masing-masing.
 */
class UmkmPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Umkm $umkm): bool
    {
        return $user->isSuperadmin() || $umkm->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Umkm $umkm): bool
    {
        return $this->view($user, $umkm);
    }

    public function delete(User $user, Umkm $umkm): bool
    {
        return $this->view($user, $umkm);
    }

    /** Transfer kepemilikan hanya untuk Superadmin aktif; aturan keamanan data ada di OwnershipTransferService. */
    public function transfer(User $user, Umkm $umkm): bool
    {
        return $user->isSuperadmin() && $user->isActive();
    }

    public function manageAnalysts(User $user): bool
    {
        return $user->isSuperadmin() && $user->isActive();
    }
}
