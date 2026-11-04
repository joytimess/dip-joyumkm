<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\ScoringService;
use InvalidArgumentException;
use Tests\TestCase;

class ScoringServiceTest extends TestCase
{
    public function test_shared_scoring_fixtures_include_null_zero_draft_and_demo_results(): void
    {
        $fixtures = $this->fixtures();
        $scoring = $this->app->make(ScoringService::class);
        foreach ($fixtures['cases'] as $case) {
            $actual = $scoring->calculate($fixtures['factors'], $case['values']);
            $expected = $case['expected'];
            $expected['factor_scores'] = array_map(fn ($value): ?float => $value === null ? null : (float) $value, $expected['factor_scores']);
            $expected['average_score'] = $expected['average_score'] === null ? null : (float) $expected['average_score'];
            $this->assertSame($expected, $actual, $case['name']);
        }
    }

    public function test_half_up_rounding_and_categories_agree_at_every_threshold(): void
    {
        $scoring = $this->app->make(ScoringService::class);
        foreach ($this->fixtures()['rounding'] as $case) {
            $value = $case['value'] === null ? null : (float) $case['value'];
            $expected = $case['expected_score'] === null ? null : (float) $case['expected_score'];
            $this->assertSame($expected, $scoring->roundScore($value));
            $this->assertSame($case['expected_category'], $scoring->category($value));
        }
    }

    public function test_invalid_scale_is_rejected_even_when_no_answers_exist(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(ScoringService::class)->calculate([
            ['code' => 'INS', 'scale_min' => 1, 'scale_max' => 1],
        ], []);
    }

    private function fixtures(): array
    {
        return json_decode(file_get_contents(base_path('tests/Fixtures/scoring.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
