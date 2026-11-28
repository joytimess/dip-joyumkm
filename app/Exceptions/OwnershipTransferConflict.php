<?php

declare(strict_types=1);

namespace App\Exceptions;

use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Konflik transfer kepemilikan dengan kode alasan stabil agar UI dapat memberi panduan tepat tanpa membocorkan detail internal.
 */
class OwnershipTransferConflict extends ConflictHttpException
{
    public function __construct(public readonly string $reasonCode, string $message)
    {
        parent::__construct($message);
    }
}
