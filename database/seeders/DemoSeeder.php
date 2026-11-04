<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Sector;
use App\Models\User;
use App\Services\EvaluationService;
use App\Services\QuestionnaireService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Seeder;
use LogicException;

class DemoSeeder extends Seeder
{
    public function __construct(
        private readonly EvaluationService $evaluationService,
        private readonly QuestionnaireService $questionnaireService,
        private readonly DatabaseManager $database,
    ) {}

    public function run(): void
    {
        if (! app()->environment('local')) {
            return;
        }

        $password = config('umkm.demo_password');
        if (! is_string($password) || trim($password) === '' || mb_strlen($password) < 8 || strlen($password) > 72 || str_contains($password, "\0")) {
            $this->command?->warn('Demo JoyUMKM dilewati. Isi DEMO_PASSWORD lokal sepanjang 8 karakter atau lebih, maksimal 72 byte, tanpa karakter NUL.');

            return;
        }

        $this->database->transaction(function () use ($password): void {
            // shortcut: email menandai grup demo; gunakan penanda tetap bila fitur perubahan email akun ditambahkan.
            if (User::query()->where('email', 'demo@joyumkm.test')->exists()) {
                return;
            }

            $factors = $this->questionnaireService->activeFactors()->keyBy('code');
            foreach (['INS' => 6, 'ECT' => 4, 'OPS' => 6] as $code => $count) {
                if ($factors->get($code)?->questions->count() !== $count) {
                    throw new LogicException('Demo JoyUMKM V1 membutuhkan 6 soal INS, 4 soal ECT, dan 6 soal OPS aktif.');
                }
            }

            $owner = User::query()->create(['name' => 'Warjon', 'email' => 'demo@joyumkm.test', 'password' => $password]);
            $definitions = [
                ['name' => 'Kawalogy', 'province' => 'DKI Jakarta', 'sector' => 'makanan-minuman', 'omzet' => 15000000, 'status' => 'final', 'values' => ['INS' => [3, 3, 3, 3, 3, 3], 'ECT' => [4, 5, 5, 5], 'OPS' => [4, 5, 5, 5, 5, 5]]],
                ['name' => 'Warjon', 'province' => 'DKI Jakarta', 'sector' => 'makanan-minuman', 'omzet' => 50000000, 'status' => 'final', 'values' => ['INS' => [3, 3, 3, 3, 3, 3], 'ECT' => [4, 5, 5, 5], 'OPS' => [3, 5, 5, 5, 5, 5]]],
                ['name' => 'Upside Down', 'province' => 'Sumatera Utara', 'sector' => 'grosir-retail', 'omzet' => 5000000, 'status' => 'final', 'values' => ['INS' => [3, 3, 3, 3, 3, 3], 'ECT' => [4, 4, 5, 5], 'OPS' => [2, 5, 5, 5, 5, 5]]],
                ['name' => 'ayam geprek', 'province' => 'Nusa Tenggara Barat', 'sector' => 'makanan-minuman', 'omzet' => 2000000, 'status' => 'draft', 'values' => ['INS' => [1, 3, 3, 3, 3, 3], 'ECT' => [4, 4, 5, 5], 'OPS' => [3, 5, 5, 5, 5, 5]]],
            ];

            foreach ($definitions as $definition) {
                $umkm = $owner->umkms()->create([
                    'name' => $definition['name'],
                    'province' => $definition['province'],
                    'sector_id' => Sector::query()->where('slug', $definition['sector'])->firstOrFail()->id,
                    'omzet' => $definition['omzet'],
                ]);
                $answers = [];
                foreach ($definition['values'] as $code => $values) {
                    foreach ($factors[$code]->questions->values() as $index => $question) {
                        $answers[] = ['question_id' => $question->id, 'value' => $values[$index]];
                    }
                }
                $this->evaluationService->store($owner, $umkm->id, ['status' => $definition['status'], 'answers' => $answers]);
            }
        });
    }
}
