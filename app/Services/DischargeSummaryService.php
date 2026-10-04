<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\DischargeSummary;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DischargeSummaryService
{
    public function __construct(private AuditLogService $auditLogs)
    {
    }

    public function approvedForPatient(Patient $patient): Builder
    {
        return DischargeSummary::query()
            ->where('status', DischargeSummary::STATUS_APPROVED)
            ->whereNotNull('approved_at')
            ->whereNotNull('approved_by')
            ->where(function (Builder $query) use ($patient): void {
                $query->whereHas('admission', fn (Builder $admissions) => $admissions->where('patient_id', $patient->id))
                    ->orWhereHas('encounter', fn (Builder $encounters) => $encounters->where('patient_id', $patient->id))
                    ->orWhereHas('erVisit', fn (Builder $visits) => $visits->where('patient_id', $patient->id));
            });
    }

    public function findApprovedForPatient(Patient $patient, int $summaryId): DischargeSummary
    {
        return $this->approvedForPatient($patient)->findOrFail($summaryId);
    }

    public function recordPatientView(DischargeSummary $summary, User $user): void
    {
        $this->auditLogs->log(
            'VIEW_OWN_DISCHARGE_SUMMARY',
            'discharge_summary',
            $summary->id,
            'SUCCESS',
            null,
            $user->id
        );
    }

    public function patientPayload(DischargeSummary $summary): array
    {
        return [
            'id' => $summary->id,
            'final_diagnosis' => $summary->final_diagnosis,
            'clinical_course' => $summary->clinical_course,
            'discharge_instructions' => $summary->discharge_instructions,
            'follow_up_plan' => $summary->follow_up_plan,
            'approved_at' => $summary->approved_at?->toIso8601String(),
            'attending_provider' => $summary->attendingProvider?->display_name,
        ];
    }

    public function saveAdmission(Admission $admission, User $user, array $data): DischargeSummary
    {
        $provider = $admission->attendingProvider;
        $this->assertProvider($provider, $user);

        return $this->save([
            'admission_id' => $admission->id,
            'encounter_id' => null,
            'er_visit_id' => null,
        ], $provider, $user, $data);
    }

    public function saveErVisit(ErVisit $visit, User $user, array $data): DischargeSummary
    {
        $provider = $visit->queue?->provider;
        $this->assertProvider($provider, $user);

        return $this->save([
            'admission_id' => null,
            'encounter_id' => null,
            'er_visit_id' => $visit->id,
        ], $provider, $user, $data);
    }

    public function approve(DischargeSummary $summary, User $user): DischargeSummary
    {
        abort_unless($user->can('order-discharges'), 403, 'You are not authorized to order discharge.');
        $this->assertProvider($summary->attendingProvider, $user);

        if ($summary->status === DischargeSummary::STATUS_APPROVED) {
            return $summary;
        }

        $summary->update([
            'status' => DischargeSummary::STATUS_APPROVED,
            'approved_by' => $user->id,
            'approved_at' => now(),
        ]);

        return $summary->fresh();
    }

    public function assertAdmissionSummaryApproved(Admission $admission): void
    {
        $summary = $admission->dischargeSummary;
        $expectedProviderUserId = $admission->attendingProvider?->user_id;

        if (
            ! $summary
            || $summary->status !== DischargeSummary::STATUS_APPROVED
            || ! $summary->approved_at
            || ! $expectedProviderUserId
            || $summary->attending_provider_id !== $admission->attending_provider_id
            || $summary->approved_by !== $expectedProviderUserId
        ) {
            throw ValidationException::withMessages([
                'discharge_summary' => ['Discharge is blocked until the attending physician authors and approves the discharge summary.'],
            ]);
        }
    }

    public function assertErSummaryApproved(ErVisit $visit): void
    {
        $summary = $visit->dischargeSummary;
        $expectedProviderId = $visit->queue?->provider_id;
        $expectedUserId = $visit->queue?->provider?->user_id;

        if (
            ! $summary
            || $summary->status !== DischargeSummary::STATUS_APPROVED
            || ! $summary->approved_at
            || ! $expectedProviderId
            || ! $expectedUserId
            || $summary->attending_provider_id !== $expectedProviderId
            || $summary->approved_by !== $expectedUserId
        ) {
            throw ValidationException::withMessages([
                'discharge_summary' => ['ER completion is blocked until the assigned physician authors and approves the discharge summary.'],
            ]);
        }
    }

    private function save(array $links, ?Provider $provider, User $user, array $data): DischargeSummary
    {
        return DB::transaction(function () use ($links, $provider, $user, $data): DischargeSummary {
            $query = DischargeSummary::query();
            foreach ($links as $column => $value) {
                $value === null ? $query->whereNull($column) : $query->where($column, $value);
            }
            $summary = $query->lockForUpdate()->first();

            if ($summary?->status === DischargeSummary::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'discharge_summary' => ['An approved discharge summary is immutable. Contact an administrator to correct it.'],
                ]);
            }

            $attributes = array_merge($links, [
                'attending_provider_id' => $provider->id,
                'authored_by' => $user->id,
                'status' => DischargeSummary::STATUS_DRAFT,
                'final_diagnosis' => $data['final_diagnosis'],
                'clinical_course' => $data['clinical_course'],
                'discharge_instructions' => $data['discharge_instructions'],
                'follow_up_plan' => $data['follow_up_plan'] ?? null,
            ]);

            if ($summary) {
                $summary->update($attributes);

                return $summary->fresh();
            }

            return DischargeSummary::create($attributes);
        });
    }

    private function assertProvider(?Provider $provider, User $user): void
    {
        if (! $user->hasRole('doctor') || ! $provider || $provider->user_id !== $user->id) {
            abort(403, 'Only the assigned attending physician may author or approve this discharge summary.');
        }
    }
}
