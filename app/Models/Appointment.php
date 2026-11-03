<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Appointment extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminder_24h_sent_at' => 'datetime',
        'reminder_1h_sent_at' => 'datetime',
    ];

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_CONFIRMED = 'CONFIRMED';

    public const STATUS_CHECKED_IN = 'CHECKED-IN';

    public const STATUS_IN_CONSULTATION = 'IN-CONSULTATION';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_CANCELLED = 'CANCELLED';

    public const STATUS_REJECTED = 'REJECTED';

    public const STATUS_NO_SHOW = 'NO-SHOW';

    protected static function booted(): void
    {
        static::saving(function (Appointment $appointment): void {
            if (in_array($appointment->status, [self::STATUS_CANCELLED, self::STATUS_REJECTED, self::STATUS_NO_SHOW], true)) {
                $appointment->active_slot_key = null;

                return;
            }

            if ($appointment->provider_id && $appointment->starts_at) {
                $startsAt = $appointment->starts_at instanceof Carbon
                    ? $appointment->starts_at
                    : Carbon::parse($appointment->starts_at);

                $appointment->active_slot_key = self::makeActiveSlotKey(
                    (int) $appointment->provider_id,
                    $startsAt
                );
            }
        });
    }

    public static function makeActiveSlotKey(int $providerId, Carbon|string $startsAt): string
    {
        $startsAt = $startsAt instanceof Carbon ? $startsAt : Carbon::parse($startsAt);

        return $providerId.':'.$startsAt->format('Y-m-d H:i:s');
    }

    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    public function provider(): BelongsTo
    {
        return $this->belongsTo(Provider::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function appointmentType(): BelongsTo
    {
        return $this->belongsTo(AppointmentType::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function parentAppointment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_appointment_id');
    }

    public function followUpAppointments(): HasMany
    {
        return $this->hasMany(self::class, 'parent_appointment_id');
    }

    public function encounter(): HasOne
    {
        return $this->hasOne(Encounter::class);
    }

    public function telehealthSession(): HasOne
    {
        return $this->hasOne(TelehealthSession::class);
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(AppointmentStatusHistory::class);
    }

    public function reassignments(): HasMany
    {
        return $this->hasMany(AppointmentReassignment::class);
    }

    public function vitals(): HasOne
    {
        return $this->hasOne(Vital::class);
    }
}
