<?php

namespace App\Services;

use App\Models\TelehealthReferral;
use App\Models\TelehealthSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TelehealthReferralService
{
    public function __construct(protected AuditLogService $audit) {}

    public function create(TelehealthSession $session, User $doctor, string $reason): TelehealthReferral
    {
        abort_unless(
            $doctor->hasRole('doctor')
                && $doctor->hasPermission('start-telehealth')
                && $doctor->provider?->id === $session->appointment?->provider_id,
            403
        );

        $appointment = $session->appointment()->with('provider')->firstOrFail();
        $departmentId = $appointment->department_id ?? $appointment->provider?->department_id;
        abort_unless($departmentId, 422, 'The session has no clinic department for an OPD referral.');

        return DB::transaction(function () use ($session, $doctor, $appointment, $departmentId, $reason): TelehealthReferral {
            $referral = TelehealthReferral::create([
                'telehealth_session_id' => $session->id,
                'patient_id' => $appointment->patient_id,
                'department_id' => $departmentId,
                'created_by' => $doctor->id,
                'reason' => $reason,
                'status' => 'REQUESTED',
            ]);

            $this->audit->log(
                'CREATE_TELEHEALTH_FACE_TO_FACE_REFERRAL',
                'telehealth_referral',
                $referral->id,
                'SUCCESS',
                [
                    'telehealth_session_id' => $session->id,
                    'patient_id' => $appointment->patient_id,
                    'department_id' => $departmentId,
                ],
                $doctor->id
            );

            return $referral->load('department');
        });
    }
}
