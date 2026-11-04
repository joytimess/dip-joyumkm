<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

class ScoringService
{
    public function calculate(array $factors, array $values): array
    {
        $factorScores = [];
        $normalizedScores = [];
        foreach ($factors as $factor) {
            $minimum = (int) $factor['scale_min'];
            $maximum = (int) $factor['scale_max'];
            if ($maximum <= $minimum) {
                throw new InvalidArgumentException('Skala faktor tidak valid.');
            }
            $answers = $values[$factor['code']] ?? [];
            $count = count($answers);
            if ($count === 0) {
                $factorScores[$factor['code']] = null;

                continue;
            }
            $normalized = (array_sum($answers) - $count * $minimum) * 100 / ($count * ($maximum - $minimum));
            $normalizedScores[] = $normalized;
            $factorScores[$factor['code']] = round($normalized, (int) config('umkm.scoring.factor_precision'), PHP_ROUND_HALF_UP);
        }
        $averageScore = $normalizedScores === []
            ? null
            : $this->roundScore(array_sum($normalizedScores) / count($normalizedScores));

        return [
            'factor_scores' => $factorScores,
            'average_score' => $averageScore,
            'category' => $this->category($averageScore),
        ];
    }

    public function roundScore(?float $value): ?float
    {
        return $value === null ? null : round($value, (int) config('umkm.scoring.score_precision'), PHP_ROUND_HALF_UP);
    }

    public function category(?float $score): string
    {
        if ($score === null) {
            return config('umkm.scoring.unrated.label');
        }
        $rounded = $this->roundScore($score);
        foreach (config('umkm.scoring.categories') as $category) {
            if ($rounded >= $category['minimum']) {
                return $category['label'];
            }
        }

        throw new InvalidArgumentException('Skor berada di luar rentang kategori.');
    }
}
