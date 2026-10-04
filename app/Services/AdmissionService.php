<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\Discharge;
use App\Models\Encounter;
use App\Models\ErVisit;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdmissionService
{
    protected ?DischargeSummaryService $summaries;

    protected ?BillingAggregationService $billing;

    public function __construct(
        protected BedManagementService $bedManagement,
        protected AuditLogService $audit,
        ?DischargeSummaryService $summaries = null,
        ?BillingAggregationService $billing = null,
    ) {
        $this->summaries = $summaries;
        $this->billing = $billing;
    }

    /**
     * Generate the next admission number.
     */
    public function generateNumber(): string
    {
        $year = now()->format('Y');
        $candidates = Admission::where('admission_number', 'like', "ADM-{$year}-%")
            ->lockForUpdate()
            ->pluck('admission_number');

        $maxSequence = 0;
        foreach ($candidates as $number) {
            if (preg_match('/^ADM-'.preg_quote($year, '/').'-(\d{6})$/', $number, $matches)) {
                $maxSequence = max($maxSequence, (int) $matches[1]);
            }
        }

        return sprintf('ADM-%s-%06d', $year, $maxSequence + 1);
    }

    /**
     * Create an admission request.
     */
    public function create(array $data): Admission
    {
        $this->assertAttendingProvider($data['attending_provider_id'] ?? null);

        return DB::transaction(function () use ($data) {
            $admission = $this->createAdmissionRecord($data);
            $this->audit->createAdmission($admission->id);

            return $admission;
        });
    }

    public function createFromErVisit(ErVisit $visit, array $data = []): Admission
    {
        $this->assertAttendingProvider($data['attending_provider_id'] ?? null);

        return DB::transaction(function () use ($visit, $data) {
            $admission = $this->create([
                'patient_id' => $visit->patient_id,
                'er_visit_id' => $visit->id,
                'attending_provider_id' => $data['attending_provider_id'] ?? null,
                'reason' => $data['reason'] ?? $visit->chief_complaint,
                'guarantor_name' => $data['guarantor_name'] ?? null,
                'guarantor_relationship' => $data['guarantor_relationship'] ?? null,
                'guarantor_contact' => $data['guarantor_contact'] ?? null,
                'loa_reference' => $data['loa_reference'] ?? null,
            ]);

            $visit->update(['status' => ErVisit::STATUS_ADMITTED]);

            return $admission;
        });
    }

    public function requestFromEncounter(Encounter $encounter, User $requester, string $reason): Admission
    {
        return DB::transaction(function () use ($encounter, $requester, $reason): Admission {
            $lockedEncounter = Encounter::query()->lockForUpdate()->findOrFail($encounter->id);

            if (
                ! $requester->hasRole('doctor')
                || ! $requester->hasPermission('create-admissions')
                || (int) $requester->provider?->id !== (int) $lockedEncounter->provider_id
            ) {
                abort(403, 'Only the attending doctor may request admission from this encounter.');
            }

            if ($lockedEncounter->status !== Encounter::STATUS_OPEN) {
                throw ValidationException::withMessages([
                    'encounter' => ['Admission can only be requested from an active encounter.'],
                ]);
            }

            if ($lockedEncounter->admission()->exists()) {
                throw ValidationException::withMessages([
                    'encounter' => ['An admission request already exists for this encounter.'],
                ]);
            }

            $admission = Admission::create([
                'admission_number' => $this->generateNumber(),
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->id,
                'attending_provider_id' => $lockedEncounter->provider_id,
                'status' => Admission::STATUS_REQUESTED,
                'reason' => trim($reason),
                'created_by' => $requester->id,
            ]);

            $this->audit->requestAdmissionFromEncounter($admission->id, $lockedEncounter->id);

            return $admission;
        });
    }

    protected function createAdmissionRecord(array $data): Admission
    {
        return Admission::create([
            'admission_number' => $this->generateNumber(),
            'patient_id' => $data['patient_id'],
            'er_visit_id' => $data['er_visit_id'] ?? null,
            'encounter_id' => $data['encounter_id'] ?? null,
            'attending_provider_id' => $data['attending_provider_id'] ?? null,
            'status' => Admission::STATUS_REQUESTED,
            'reason' => $data['reason'] ?? null,
            'guarantor_name' => $data['guarantor_name'] ?? null,
            'guarantor_relationship' => $data['guarantor_relationship'] ?? null,
            'guarantor_contact' => $data['guarantor_contact'] ?? null,
            'loa_reference' => $data['loa_reference'] ?? null,
            'created_by' => auth()->id(),
        ]);
    }

    public function updateGuaranteeDetails(Admission $admission, array $data, User $actor): Admission
    {
        return DB::transaction(function () use ($admission, $data, $actor): Admission {
            $lockedAdmission = Admission::query()->lockForUpdate()->findOrFail($admission->id);
            $lockedAdmission->update($data);
            $this->audit->log(
                'UPDATE_ADMISSION_GUARANTEE_DETAILS',
                'admission',
                $lockedAdmission->id,
                'SUCCESS',
                ['updated_fields' => array_keys($data)],
                $actor->id
            );

            return $lockedAdmission->fresh();
        });
    }

    public function approve(Admission $admission): Admission
    {
        return DB::transaction(function () use ($admission): Admission {
            $lockedAdmission = Admission::query()->lockForUpdate()->find($admission->id);
            if (! $lockedAdmission) {
                throw ValidationException::withMessages(['admission_id' => ['Admission not found.']]);
            }

            if ($lockedAdmission->status !== Admission::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'status' => ['Only a requested admission can be approved.'],
                ]);
            }

            $lockedAdmission->update(['status' => Admission::STATUS_APPROVED]);

            return $lockedAdmission->fresh();
        });
    }

    /**
     * Admit a patient and assign a bed transactionally.
     */
    public function admit(Admission $admission, int $bedId, ?int $reservationId = null): Admission
    {
        $this->bedManagement->assertReservationNotExpired($admission, $bedId, $reservationId);

        return DB::transaction(function () use ($admission, $bedId, $reservationId) {
            $lockedAdmission = Admission::query()->lockForUpdate()->find($admission->id);
            if (! $lockedAdmission) {
                throw ValidationException::withMessages(['admission_id' => ['Admission not found.']]);
            }

            if ($lockedAdmission->status !== Admission::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'status' => ['Only an approved admission can be admitted and assigned a bed.'],
                ]);
            }

            $lockedAdmission->update([
                'status' => Admission::STATUS_ADMITTED,
                'admitted_at' => now(),
            ]);
            $this->bedManagement->assignBed($lockedAdmission, $bedId, $reservationId);

            return $lockedAdmission->fresh();
        });
    }

    private function assertAttendingProvider(mixed $providerId): void
    {
        if (! $providerId || ! Provider::query()->whereKey($providerId)->exists()) {
            throw ValidationException::withMessages([
                'attending_provider_id' => ['A valid attending provider is required for inpatient admission.'],
            ]);
        }
    }

    /**
     * Discharge a patient and release the bed transactionally.
     */
    public function discharge(Admission $admission, array $data): Discharge
    {
        abort_unless(auth()->user()?->can('execute-discharges'), 403, 'You are not authorized to execute a discharge.');

        return DB::transaction(function () use ($admission, $data) {
            $lockedAdmission = Admission::query()->lockForUpdate()->find($admission->id);
            if (! $lockedAdmission) {
                throw ValidationException::withMessages(['admission_id' => ['Admission not found.']]);
            }

            if (! in_array($lockedAdmission->status, [Admission::STATUS_ADMITTED, Admission::STATUS_TRANSFERRED], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only admitted or transferred patients can be discharged.'],
                ]);
            }

            $existingDischarge = Discharge::query()
                ->where('admission_id', $lockedAdmission->id)
                ->lockForUpdate()
                ->first();
            if ($existingDischarge) {
                throw ValidationException::withMessages([
                    'discharge' => ['This admission has already been discharged.'],
                ]);
            }

            ($this->summaries ?? app(DischargeSummaryService::class))->assertAdmissionSummaryApproved($lockedAdmission);
            ($this->billing ?? app(BillingAggregationService::class))->assertAdmissionCanDischarge($lockedAdmission);
            $discharge = $this->createDischargeRecord($lockedAdmission, $data);
            $this->markDischarged($lockedAdmission);
            $this->releaseActiveBedIfPresent($lockedAdmission);
            $this->audit->dischargePatient($lockedAdmission->id);

            return $discharge;
        });
    }

    protected function createDischargeRecord(Admission $admission, array $data): Discharge
    {
        return Discharge::create([
            'admission_id' => $admission->id,
            'authorized_by' => auth()->id(),
            'discharged_at' => now(),
            'reason' => $data['reason'] ?? null,
            'disposition' => $data['disposition'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    protected function markDischarged(Admission $admission): void
    {
        $admission->update(['status' => Admission::STATUS_DISCHARGED]);
    }

    protected function releaseActiveBedIfPresent(Admission $admission): void
    {
        $activeAssignment = $admission->activeBedAssignment;
        if ($activeAssignment) {
            $this->bedManagement->releaseBed($admission, $activeAssignment->bed_id);
        }
    }
}
