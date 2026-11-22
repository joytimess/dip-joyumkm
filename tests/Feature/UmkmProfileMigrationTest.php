<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class UmkmProfileMigrationTest extends TestCase
{
    use DatabaseMigrations;

    public function test_upgrade_and_rollback_preserve_legacy_business_and_evaluations(): void
    {
        $migrationPath = 'database/migrations/2026_11_22_064805_add_business_profile_to_umkms_table.php';
        $this->artisan('migrate:rollback', ['--path' => $migrationPath, '--step' => 1])->assertSuccessful();

        $legacy = Umkm::factory()->create(['omzet' => 12_000_000]);
        $evaluation = $legacy->evaluations()->create(['status' => 'final', 'average_score' => 80, 'category' => 'BAIK']);
        $sixFactor = SixFactorEvaluation::factory()->for($legacy)->final()->create();
        try {
            $original = $legacy->fresh()->getRawOriginal();

            $this->artisan('migrate', ['--path' => $migrationPath])->assertSuccessful();
            $upgraded = $legacy->fresh();
            $this->assertSame($original, array_intersect_key($upgraded->getRawOriginal(), $original));
            $this->assertNull($upgraded->omzet_period);
            $this->assertNull($upgraded->whatsapp_number);
            $this->assertSame('unchecked', $upgraded->wa_registration_status);
            $this->assertTrue($upgraded->latestEvaluation->is($evaluation));
            $this->assertSame($evaluation->average_score, $upgraded->latestEvaluation->average_score);
            $this->assertTrue($upgraded->latestSixFactorFinal->is($sixFactor));
            $this->assertSame($sixFactor->total_score, $upgraded->latestSixFactorFinal->total_score);

            $this->artisan('migrate:rollback', ['--path' => $migrationPath, '--step' => 1])->assertSuccessful();
            $this->assertFalse(Schema::hasColumn('umkms', 'omzet_period'));
            $this->assertSame($original, $legacy->fresh()->getRawOriginal());
            $this->assertModelExists($evaluation);
            $this->assertModelExists($sixFactor);
        } finally {
            DB::table('six_factor_evaluations')->where('id', $sixFactor->id)->delete();
            $legacy->delete();
        }
    }
}
