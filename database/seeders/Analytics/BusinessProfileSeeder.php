<?php

declare(strict_types=1);

namespace Database\Seeders\Analytics;

use App\Models\Umkm;
use App\Models\User;
use App\Services\Analytics\AnalyticsDatasetPlanner;
use App\Services\Analytics\AnalyticsDatasetRun;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Hashing\Hasher;

/**
 * Akun sintetis dan profil UMKM (428 dasar + 30 ekspansi). Hanya dipanggil AnalyticsDatasetSeeder; tidak memanggil AuthService, UmkmService, atau pemeriksaan WhatsApp.
 *
 * @phpstan-import-type UmkmPlan from AnalyticsDatasetPlanner
 */
final class BusinessProfileSeeder
{
    public function __construct(
        private readonly AnalyticsDatasetRun $run,
        private readonly Hasher $hasher,
    ) {}

    /**
     * Membuat akun sintetis lalu seluruh profil di bawah akun itu (jalur kompatibel v1).
     *
     * @param  array{months: list<string>, account_created_at: CarbonImmutable, umkms: list<UmkmPlan>}  $plan
     * @param  array{name: string, email: string}  $account
     * @param  array<string, int>  $sectorIds
     * @return array{user_id: int, umkm_ids: array<int, int>}
     */
    public function seed(array $plan, array $account, string $secret, array $sectorIds): array
    {
        $user = $this->createAccount($plan['account_created_at'], $account, $secret);
        $owners = array_fill_keys(array_column($plan['umkms'], 'index'), (int) $user->id);

        return ['user_id' => (int) $user->id, 'umkm_ids' => $this->seedProfiles($plan['umkms'], $owners, $sectorIds)];
    }

    /**
     * @param  array{name: string, email: string}  $account
     */
    public function createAccount(CarbonImmutable $createdAt, array $account, string $secret): User
    {
        $this->run->assertWritable();

        $user = new User;
        $user->forceFill([
            'name' => $account['name'],
            'email' => $account['email'],
            'password' => $this->hasher->make($secret),
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $user;
    }

    /**
     * @param  list<UmkmPlan>  $profiles
     * @param  array<int, int>  $ownerByIndex  Indeks plan => ID pemilik awal.
     * @param  array<string, int>  $sectorIds
     * @return array<int, int> Indeks plan => ID UMKM.
     */
    public function seedProfiles(array $profiles, array $ownerByIndex, array $sectorIds): array
    {
        $this->run->assertWritable();

        $umkmIds = [];
        foreach ($profiles as $profile) {
            $umkm = new Umkm;
            $umkm->forceFill([
                'user_id' => $ownerByIndex[$profile['index']],
                'name' => $profile['name'],
                'province' => $profile['province'],
                'city' => $profile['city'],
                'sector_id' => $sectorIds[$profile['sector_slug']],
                'omzet' => $profile['omzet'],
                'omzet_period' => $profile['omzet_period'],
                'business_capital' => $profile['business_capital'],
                'year_founded' => $profile['year_founded'],
                'employee_count' => $profile['employee_count'],
                'description' => $profile['description'],
                'owner_name' => $profile['owner_name'],
                'wa_registration_status' => 'unchecked',
                'wa_checked_at' => null,
                'created_at' => $profile['created_at'],
                'updated_at' => $profile['created_at'],
            ])->save();

            $umkmIds[$profile['index']] = $umkm->id;
        }

        return $umkmIds;
    }
}
