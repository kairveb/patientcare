<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationDisclosure extends Model
{
    protected $guarded = [];

    protected $casts = ['fields_shared' => 'array', 'disclosed_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(static function (): void {
            throw new \LogicException('Integration disclosures are append-only.');
        });
        static::deleting(static function (): void {
            throw new \LogicException('Integration disclosures are append-only.');
        });
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(IntegrationPartner::class, 'integration_partner_id');
    }
}
