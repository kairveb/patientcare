<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IntegrationReferenceMap extends Model
{
    protected $guarded = [];

    protected $casts = ['emr_reference' => 'string'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(IntegrationPartner::class, 'integration_partner_id');
    }
}
