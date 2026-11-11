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
    public const CATEGORY_CONSULTATION = 'CONSULTATION';
    public const CATEGORY_EMERGENCY = 'EMERGENCY';
    public const CATEGORY_LABORATORY = 'LABORATORY';
    public const CATEGORY_IMAGING = 'IMAGING';
    public const CATEGORY_PROCEDURE = 'PROCEDURE';
    public const CATEGORY_PRESCRIPTION = 'PRESCRIPTION';
    public const CATEGORY_SUPPLY = 'SUPPLY';
    public const CATEGORY_BED_DAILY = 'BED_DAILY';

    public const CATEGORIES = [
        self::CATEGORY_ENCOUNTER,
        self::CATEGORY_CONSULTATION,
        self::CATEGORY_EMERGENCY,
        self::CATEGORY_LABORATORY,
        self::CATEGORY_IMAGING,
        self::CATEGORY_PROCEDURE,
        self::CATEGORY_PRESCRIPTION,
        self::CATEGORY_SUPPLY,
        self::CATEGORY_BED_DAILY,
    ];

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
