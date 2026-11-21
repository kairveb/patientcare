<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProviderSchedule extends Model
{
    protected $guarded = [];

    protected $hidden = ['integration_partner_id'];

    protected $casts = ['day_of_week' => 'integer', 'slot_duration' => 'integer', 'unavailable_date' => 'date:Y-m-d'];

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }
}
