<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\TriageAssessment;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogService
{
    /**
     * Record an audit log entry.
     */
    public function log(
        string $action,
        string $resourceType,
        ?int $resourceId = null,
        ?string $result = 'SUCCESS',
        ?array $metadata = null,
        ?int $userId = null
    ): AuditLog {
        $userId = $userId ?? Auth::id();

        return AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'result' => $result,
            'ip_address' => Request::ip(),
            'metadata' => $metadata,
        ]);
    }

    // Common actions
    public function createPatient(int $id, ?array $meta = null): AuditLog
    {
        return $this->log('CREATE_PATIENT', 'patient', $id, 'SUCCESS', $meta);
    }

    public function updatePatient(int $id, ?array $meta = null): AuditLog
    {
        return $this->log('UPDATE_PATIENT', 'patient', $id, 'SUCCESS', $meta);
    }

    public function viewPatient(int $id): AuditLog
    {
        return $this->log('VIEW_PATIENT', 'patient', $id);
    }

    public function viewPatientClinicalData(int $patientId, string $resourceType, int $resourceId): AuditLog
    {
        return $this->log('VIEW_PATIENT_CLINICAL_DATA', 'patient', $patientId, 'SUCCESS', [
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
        ]);
    }

    public function createAppointment(int $id, ?array $meta = null): AuditLog
    {
        return $this->log('CREATE_APPOINTMENT', 'appointment', $id, 'SUCCESS', $meta);
    }

    public function cancelAppointment(int $id, ?array $meta = null): AuditLog
    {
        return $this->log('CANCEL_APPOINTMENT', 'appointment', $id, 'SUCCESS', $meta);
    }

    public function rescheduleAppointment(int $id, ?array $meta = null): AuditLog
    {
        return $this->log('RESCHEDULE_APPOINTMENT', 'appointment', $id, 'SUCCESS', $meta);
    }

    public function checkInAppointment(int $id): AuditLog
    {
        return $this->log('CHECK_IN_APPOINTMENT', 'appointment', $id);
    }

    public function createEncounter(int $id): AuditLog
    {
        return $this->log('CREATE_ENCOUNTER', 'encounter', $id);
    }

    public function recordOpdVitals(int $vitalId, int $appointmentId, int $nurseId): AuditLog
    {
        return $this->log(
            'RECORD_OPD_VITALS',
            'vital',
            $vitalId,
            'SUCCESS',
            ['appointment_id' => $appointmentId],
            $nurseId
        );
    }

    public function completeEncounter(int $id): AuditLog
    {
        return $this->log('COMPLETE_ENCOUNTER', 'encounter', $id);
    }

    public function issuePrescription(int $id, int $encounterId): AuditLog
    {
        return $this->log('ISSUE_PRESCRIPTION', 'prescription', $id, 'SUCCESS', ['encounter_id' => $encounterId]);
    }

    public function requestAdmissionFromEncounter(int $admissionId, int $encounterId): AuditLog
    {
        return $this->log('REQUEST_ADMISSION', 'admission', $admissionId, 'SUCCESS', ['encounter_id' => $encounterId]);
    }

    public function createErVisit(int $id): AuditLog
    {
        return $this->log('CREATE_ER_VISIT', 'er_visit', $id);
    }

    public function createTriage(int $id): AuditLog
    {
        return $this->log('CREATE_TRIAGE', 'triage_assessment', $id);
    }

    public function recordTriageDecision(
        TriageAssessment $assessment,
        string $suggestedLevel,
        ?int $confidence,
        string $finalLevel,
        ?string $overrideReason,
        int $userId
    ): AuditLog {
        return $this->log('CONFIRM_TRIAGE_DECISION', 'triage_assessment', $assessment->id, 'SUCCESS', [
            'ai_suggested_level' => $suggestedLevel,
            'ai_confidence' => $confidence,
            'final_level' => $finalLevel,
            'override_reason' => $overrideReason,
        ], $userId);
    }

    public function assignBed(int $admissionId, int $bedId): AuditLog
    {
        return $this->log('ASSIGN_BED', 'bed_assignment', $bedId, 'SUCCESS', ['admission_id' => $admissionId]);
    }

    public function transferPatient(
        int $admissionId,
        int $fromBedId,
        int $toBedId,
        int $transferId,
        ?string $reason = null
    ): AuditLog {
        return $this->log('TRANSFER_PATIENT', 'patient_transfer', $transferId, 'SUCCESS', [
            'admission_id' => $admissionId,
            'from_bed_id' => $fromBedId,
            'to_bed_id' => $toBedId,
            'has_reason' => filled($reason),
        ]);
    }

    public function dischargePatient(int $admissionId): AuditLog
    {
        return $this->log('DISCHARGE_PATIENT', 'discharge', $admissionId);
    }

    public function createAdmission(int $id): AuditLog
    {
        return $this->log('CREATE_ADMISSION', 'admission', $id);
    }
}
