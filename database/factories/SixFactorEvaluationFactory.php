<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SixFactorEvaluation;
use App\Models\Umkm;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<SixFactorEvaluation> */
class SixFactorEvaluationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'umkm_id' => Umkm::factory(),
            'status' => SixFactorEvaluation::DRAFT,
        ];
    }

    public function draft(): static
    {
        return $this->state([
            'status' => SixFactorEvaluation::DRAFT,
            'total_score' => null,
            'category' => null,
            'finalized_at' => null,
            'cancelled_at' => null,
        ]);
    }

    public function final(): static
    {
        return $this->state(fn (): array => [
            ...array_fill_keys(SixFactorEvaluation::FACTORS, 3),
            'status' => SixFactorEvaluation::FINAL,
            'total_score' => 18,
            'category' => 'Cukup Sehat',
            'finalized_at' => now(),
            'cancelled_at' => null,
        ]);
    }

    public function cancelled(): static
    {
        return $this->draft()->state(fn (): array => [
            'status' => SixFactorEvaluation::CANCELLED,
            'sumber_daya' => 3,
            'cancelled_at' => now(),
        ]);
    }
}
