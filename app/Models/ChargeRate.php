<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChargeRate extends Model
{
    protected $guarded = [];

    protected $casts = [
        'unit_amount' => 'decimal:2',
        'active' => 'boolean',
    ];

    public const CATEGORY_ENCOUNTER = 'ENCOUNTER';
    public const CATEGORY_PRESCRIPTION = 'PRESCRIPTION';
    public const CATEGORY_BED_DAILY = 'BED_DAILY';

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
