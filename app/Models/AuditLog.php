<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class AuditLog extends Model
{
    protected $guarded = [];

    protected $casts = ['metadata' => 'array'];

    protected static function booted(): void
    {
        static::updating(static function (): never {
            throw new LogicException('Audit logs are append-only.');
        });

        static::deleting(static function (): never {
            throw new LogicException('Audit logs are append-only.');
        });
    }

    public function newEloquentBuilder($query): AuditLogBuilder
    {
        return new AuditLogBuilder($query);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
