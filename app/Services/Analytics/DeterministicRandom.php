<?php

declare(strict_types=1);

namespace App\Services\Analytics;

/**
 * Sumber angka deterministik berbasis SHA-256. Tidak memakai random global, Faker, atau clock.
 */
final class DeterministicRandom
{
    public function __construct(private readonly string $seed) {}

    /**
     * @param  string  ...$scope  Namespace keputusan; setiap keputusan harus memakai scope unik.
     */
    public function int(int $min, int $max, string ...$scope): int
    {
        $bytes = hash('sha256', implode("\x1f", [$this->seed, ...$scope]), true);
        $value = unpack('J', substr($bytes, 0, 8))[1] & PHP_INT_MAX;

        return $min + ($value % ($max - $min + 1));
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return list<T>
     */
    public function shuffle(array $items, string ...$scope): array
    {
        for ($index = count($items) - 1; $index > 0; $index--) {
            $swap = $this->int(0, $index, ...array_merge(['shuffle'], $scope, [(string) $index]));
            [$items[$index], $items[$swap]] = [$items[$swap], $items[$index]];
        }

        return array_values($items);
    }
}
