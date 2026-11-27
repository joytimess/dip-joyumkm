<?php

declare(strict_types=1);

namespace App\Enums;

enum UserRole: string
{
    case Superadmin = 'superadmin';
    case Analyst = 'analyst';
    case User = 'user';

    /**
     * Nilai tak dikenal gagal tertutup menjadi User (akses hanya milik sendiri), tidak pernah ke peran istimewa.
     */
    public static function fromStored(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom($value) ?? self::User) : self::User;
    }
}
