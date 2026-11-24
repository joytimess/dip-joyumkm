<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\EmailSuppression;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<EmailSuppression> */
class EmailSuppressionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email_fingerprint' => fn (): string => hash('sha256', Str::random(32)),
            'reason' => 'unsubscribe',
            'source' => 'factory',
            'created_at' => now()->startOfSecond(),
        ];
    }
}
