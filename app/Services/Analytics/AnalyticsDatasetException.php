<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use RuntimeException;

/**
 * Kegagalan fail-closed. Pesan tidak boleh memuat kata sandi, token, atau data pribadi.
 */
final class AnalyticsDatasetException extends RuntimeException
{
    public static function because(string $message): self
    {
        return new self($message);
    }
}
