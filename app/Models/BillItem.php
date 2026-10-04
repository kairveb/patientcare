<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
        'rate_snapshot' => 'array',
    ];

    public function bill(): BelongsTo
    {
        return $this->belongsTo(EncounterBill::class, 'encounter_bill_id');
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(ChargeRate::class, 'charge_rate_id');
    }

    public function encounter(): BelongsTo
    {
        return $this->belongsTo(Encounter::class);
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }
}
