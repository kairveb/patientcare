<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationEvent extends Model
{
    protected $guarded = [];

    protected $casts = ['response_body' => 'array', 'processed_at' => 'datetime'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(IntegrationPartner::class, 'integration_partner_id');
    }
}
