<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

#[Signature('joyumkm:provision-superadmin {email=superadmin@joyumkm.test}')]
#[Description('Buat akun Superadmin lokal bila belum ada. Kata sandi dari env JOYUMKM_SUPERADMIN_PASSWORD; tidak pernah menimpa akun yang ada')]
class JoyumkmProvisionSuperadminCommand extends Command
{
    public function handle(): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('Provisioning Superadmin hanya untuk lingkungan lokal.');

            return self::FAILURE;
        }

        $email = mb_strtolower(trim((string) $this->argument('email')));
        if (User::query()->where('email', $email)->exists()) {
            $this->warn("{$email}: sudah ada; tidak diubah (peran dan kata sandi dipertahankan).");

            return self::SUCCESS;
        }

        $password = getenv('JOYUMKM_SUPERADMIN_PASSWORD');
        if (! is_string($password) || strlen($password) < 12 || strlen($password) > 72) {
            $this->warn('Kredensial aman tidak tersedia (JOYUMKM_SUPERADMIN_PASSWORD, 12-72 karakter). Provisioning TERTUNDA.');

            return self::SUCCESS;
        }

        // Dibuat langsung tanpa alur registrasi, sehingga tidak memicu antrean onboarding.
        $user = new User(['name' => 'Superadmin JoyUMKM', 'email' => $email, 'password' => Hash::make($password)]);
        $user->forceFill(['role' => UserRole::Superadmin->value, 'email_verified_at' => now()])->save();
        $this->info("{$email}: Superadmin dibuat.");

        return self::SUCCESS;
    }
}
