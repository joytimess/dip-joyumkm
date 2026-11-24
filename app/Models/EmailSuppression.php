<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\EmailSuppressionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['email_fingerprint', 'reason', 'source', 'created_at'])]
class EmailSuppression extends Model
{
    /** @use HasFactory<EmailSuppressionFactory> */
    use HasFactory;

    public const REASONS = ['bounce', 'complaint', 'unsubscribe', 'manual'];

    /** Hanya created_at; identitas alamat disimpan sebagai HMAC, bukan email mentah. */
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }
}
