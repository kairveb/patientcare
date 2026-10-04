<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\User;
use App\Models\Vital;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OpdVitalsService
{
    public function recordForAppointment(Appointment $appointment, array $data, User $nurse): Vital
    {
        if (! $nurse->hasRole('nurse')) {
            abort(403, 'Only nursing staff may record OPD intake vitals.');
        }

        return DB::transaction(function () use ($appointment, $data, $nurse): Vital {
            $lockedAppointment = Appointment::query()
                ->with('provider')
                ->lockForUpdate()
                ->findOrFail($appointment->id);

            if (! in_array($lockedAppointment->status, [Appointment::STATUS_CHECKED_IN, Appointment::STATUS_IN_CONSULTATION], true)) {
                throw ValidationException::withMessages([
                    'appointment' => ['OPD vitals can only be recorded for a checked-in appointment.'],
                ]);
            }

            $departmentId = $lockedAppointment->department_id ?? $lockedAppointment->provider?->department_id;
            abort_unless(
                $departmentId && $nurse->departments()->whereKey($departmentId)->exists(),
                403,
                'Nursing staff may only record vitals for appointments in their assigned department.'
            );

            $heightCm = (float) ($data['height_cm'] ?? 0);
            $weightKg = (float) ($data['weight'] ?? 0);
            if ($heightCm < 55 || $heightCm > 250 || $weightKg < 1 || $weightKg > 300) {
                throw ValidationException::withMessages([
                    'height_cm' => ['Height must be between 55 and 250 cm and weight between 1 and 300 kg.'],
                ]);
            }

            $now = now();
            $vitalData = [
                'appointment_id' => $lockedAppointment->id,
                'encounter_id' => $lockedAppointment->encounter?->id,
                'patient_id' => $lockedAppointment->patient_id,
                'recorded_by' => $nurse->id,
                'blood_pressure' => $data['blood_pressure'] ?? null,
                'heart_rate' => $data['heart_rate'] ?? null,
                'respiratory_rate' => $data['respiratory_rate'] ?? null,
                'temperature' => $data['temperature'] ?? null,
                'spo2' => $data['spo2'] ?? null,
                'height_cm' => number_format($heightCm, 2, '.', ''),
                'weight' => number_format($weightKg, 2, '.', ''),
                'bmi' => number_format(round($weightKg / (($heightCm / 100) ** 2), 2), 2, '.', ''),
                'pain_score' => $data['pain_score'] ?? null,
                'recorded_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ];

            Vital::query()->upsert(
                [$vitalData],
                ['appointment_id'],
                array_values(array_diff(array_keys($vitalData), ['appointment_id', 'created_at']))
            );

            $vital = Vital::query()->where('appointment_id', $lockedAppointment->id)->firstOrFail();
            app(AuditLogService::class)->recordOpdVitals($vital->id, $lockedAppointment->id, $nurse->id);

            return $vital;
        });
    }
}
