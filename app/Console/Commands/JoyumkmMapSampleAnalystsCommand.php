<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('joyumkm:map-sample-analysts')]
#[Description('Petakan tiga akun sampel yang sudah ada menjadi Analyst (idempoten; tidak membuat akun, tidak menyentuh kata sandi)')]
class JoyumkmMapSampleAnalystsCommand extends Command
{
    /** @var list<string> */
    public const SAMPLE_EMAILS = ['sample1@joyumkm.test', 'sample2@joyumkm.test', 'sample3@joyumkm.test'];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('Perintah ini hanya untuk lingkungan lokal/dev.');

            return self::FAILURE;
        }

        foreach (self::SAMPLE_EMAILS as $email) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $this->warn("{$email}: tidak ada; dilewati (pembuatan akun ditunda ke P3).");

                continue;
            }
            if ($user->userRole() === UserRole::Superadmin) {
                $this->warn("{$email}: sudah Superadmin; tidak diubah.");

                continue;
            }

            $updated = User::query()->whereKey($user->id)->update(['role' => UserRole::Analyst->value]);
            $this->info("{$email}: role analyst ({$updated} diubah).");
        }

        return self::SUCCESS;
    }
}
