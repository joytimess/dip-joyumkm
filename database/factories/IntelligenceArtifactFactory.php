<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\IntelligenceArtifact;
use App\Models\IntelligenceReport;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<IntelligenceArtifact> */
class IntelligenceArtifactFactory extends Factory
{
    /**
     * Artefak PDF untuk laporan sungguhan; path acak tanpa nama UMKM.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => fn (): int => IntelligenceReport::factory()->create()->id,
            'event_id' => fn (array $attributes): string => IntelligenceReport::query()->findOrFail($attributes['report_id'])->event_id,
            'kind' => IntelligenceArtifact::PDF,
            'storage_disk' => 'local',
            'storage_path' => fn (): string => 'intelligence/artifacts/'.Str::uuid().'.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => fake()->numberBetween(1024, 1024 * 1024),
            'sha256' => fn (): string => hash('sha256', Str::random(32)),
            'created_at' => now()->startOfSecond(),
        ];
    }

    public function xlsx(): static
    {
        return $this->state(fn (): array => [
            'kind' => IntelligenceArtifact::XLSX,
            'mime' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'storage_path' => fn (): string => 'intelligence/artifacts/'.Str::uuid().'.xlsx',
        ]);
    }
}
