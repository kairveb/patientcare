<?php

namespace App\Services;

use App\Models\CareRequest;
use App\Models\CareRequestStatusHistory;
use App\Models\ClinicalDocument;
use App\Models\Encounter;
use App\Models\User;
use App\Notifications\CareRequestDecisionNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CareRequestService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function create(Encounter $encounter, User $doctor, array $data): CareRequest
    {
        return DB::transaction(function () use ($encounter, $doctor, $data): CareRequest {
            $lockedEncounter = Encounter::query()->lockForUpdate()->findOrFail($encounter->id);
            abort_unless(
                $doctor->hasRole('doctor')
                    && $doctor->hasPermission('create-care-requests')
                    && $doctor->provider?->id === $lockedEncounter->provider_id
                    && $lockedEncounter->type === Encounter::TYPE_OUTPATIENT,
                403
            );

            $careRequest = CareRequest::create([
                'patient_id' => $lockedEncounter->patient_id,
                'encounter_id' => $lockedEncounter->id,
                'appointment_id' => $lockedEncounter->appointment_id,
                'requested_by' => $doctor->id,
                'type' => $data['type'],
                'description' => trim($data['description']),
                'urgency' => $data['urgency'],
                'status' => CareRequest::STATUS_REQUESTED,
            ]);

            $this->recordHistory($careRequest, null, CareRequest::STATUS_REQUESTED, $doctor, 'Request created.');
            $this->audit->log('CREATE_CARE_REQUEST', 'care_request', $careRequest->id, 'SUCCESS', [
                'encounter_id' => $lockedEncounter->id,
                'type' => $careRequest->type,
                'urgency' => $careRequest->urgency,
            ], $doctor->id);

            return $careRequest;
        });
    }

    public function decide(CareRequest $careRequest, User $approver, string $decision, ?string $remarks): CareRequest
    {
        if (! in_array($decision, [CareRequest::STATUS_APPROVED, CareRequest::STATUS_REJECTED], true)) {
            throw ValidationException::withMessages(['decision' => 'Choose approve or reject.']);
        }
        if ($decision === CareRequest::STATUS_REJECTED && blank($remarks)) {
            throw ValidationException::withMessages(['remarks' => 'Remarks are required when rejecting a request.']);
        }

        return DB::transaction(function () use ($careRequest, $approver, $decision, $remarks): CareRequest {
            $locked = CareRequest::query()->lockForUpdate()->findOrFail($careRequest->id);
            if ($locked->status !== CareRequest::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'status' => ['Only REQUESTED care requests can be approved or rejected.'],
                ]);
            }
            abort_unless(
                $approver->hasRole('registration', 'super-admin')
                    && $approver->hasPermission('approve-care-requests'),
                403
            );

            $fromStatus = $locked->status;
            $locked->update([
                'status' => $decision,
                'approval_reference' => $decision === CareRequest::STATUS_APPROVED
                    ? $this->nextApprovalReference()
                    : null,
                'decided_by' => $approver->id,
                'decided_at' => now(),
                'remarks' => filled($remarks) ? trim($remarks) : null,
            ]);
            $locked->refresh();
            $this->recordHistory($locked, $fromStatus, $decision, $approver, $remarks);
            $this->notifyDecision($locked);
            $this->audit->log('DECIDE_CARE_REQUEST', 'care_request', $locked->id, 'SUCCESS', [
                'from_status' => $fromStatus,
                'to_status' => $decision,
                'has_remarks' => filled($remarks),
            ], $approver->id);

            return $locked;
        });
    }

    public function cancel(CareRequest $careRequest, User $doctor, ?string $remarks = null): CareRequest
    {
        return DB::transaction(function () use ($careRequest, $doctor, $remarks): CareRequest {
            $locked = CareRequest::query()->lockForUpdate()->findOrFail($careRequest->id);
            if ($locked->status !== CareRequest::STATUS_REQUESTED) {
                throw ValidationException::withMessages([
                    'status' => ['Only REQUESTED care requests can be cancelled.'],
                ]);
            }
            abort_unless(
                $doctor->hasRole('doctor')
                    && $doctor->hasPermission('create-care-requests')
                    && $locked->requested_by === $doctor->id
                    && $doctor->provider?->id === $locked->encounter?->provider_id,
                403
            );

            $fromStatus = $locked->status;
            $locked->update([
                'status' => CareRequest::STATUS_CANCELLED,
                'decided_by' => $doctor->id,
                'decided_at' => now(),
                'remarks' => filled($remarks) ? trim($remarks) : null,
            ]);
            $this->recordHistory($locked, $fromStatus, CareRequest::STATUS_CANCELLED, $doctor, $remarks);
            $this->audit->log('CANCEL_CARE_REQUEST', 'care_request', $locked->id, 'SUCCESS', [
                'from_status' => $fromStatus,
            ], $doctor->id);

            return $locked->fresh();
        });
    }

    public function attachResult(CareRequest $careRequest, User $doctor, string $resultText, ?UploadedFile $file): CareRequest
    {
        return DB::transaction(function () use ($careRequest, $doctor, $resultText, $file): CareRequest {
            $locked = CareRequest::query()->with(['encounter', 'patient'])->lockForUpdate()->findOrFail($careRequest->id);
            if (! in_array($locked->type, [CareRequest::TYPE_LAB, CareRequest::TYPE_IMAGING], true)
                || $locked->status !== CareRequest::STATUS_APPROVED) {
                throw ValidationException::withMessages([
                    'care_request' => ['Results can be attached only to approved lab or imaging requests.'],
                ]);
            }
            abort_unless(
                $doctor->hasRole('doctor')
                    && $locked->requested_by === $doctor->id
                    && $doctor->provider?->id === $locked->encounter?->provider_id,
                403
            );

            $document = null;
            if ($file) {
                $path = $file->store('clinical_documents');
                if (! is_string($path) || $path === '') {
                    throw ValidationException::withMessages([
                        'result_file' => ['The result file could not be saved. Please try again.'],
                    ]);
                }

                $document = ClinicalDocument::create([
                    'patient_id' => $locked->patient_id,
                    'encounter_id' => $locked->encounter_id,
                    'uploaded_by' => $doctor->id,
                    'name' => $file->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $file->getMimeType() ?: 'application/octet-stream',
                ]);
            }

            $locked->update([
                'result_text' => trim($resultText),
                'clinical_document_id' => $document?->id,
            ]);
            $this->audit->log('ATTACH_CARE_REQUEST_RESULT', 'care_request', $locked->id, 'SUCCESS', [
                'has_file' => $document !== null,
            ], $doctor->id);

            return $locked->fresh(['clinicalDocument']);
        });
    }

    private function nextApprovalReference(): string
    {
        $date = now()->toDateString();
        DB::table('care_request_reference_sequences')->insertOrIgnore([
            'reference_date' => $date,
            'next_number' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sequence = DB::table('care_request_reference_sequences')
            ->where('reference_date', $date)
            ->lockForUpdate()
            ->first();
        $nextNumber = (int) $sequence->next_number + 1;

        DB::table('care_request_reference_sequences')
            ->where('reference_date', $date)
            ->update(['next_number' => $nextNumber, 'updated_at' => now()]);

        return sprintf('LOA-%s-%04d', now()->format('Ymd'), $nextNumber);
    }

    private function recordHistory(
        CareRequest $careRequest,
        ?string $fromStatus,
        string $toStatus,
        ?User $user,
        ?string $remarks
    ): void {
        CareRequestStatusHistory::create([
            'care_request_id' => $careRequest->id,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'user_id' => $user?->id,
            'remarks' => filled($remarks) ? trim($remarks) : null,
        ]);
    }

    private function notifyDecision(CareRequest $careRequest): void
    {
        $recipients = collect([
            $careRequest->patient?->user,
            $careRequest->requestedBy,
        ])->filter()->unique('id');

        foreach ($recipients as $recipient) {
            $recipient->notify(new CareRequestDecisionNotification($careRequest));
        }
    }
}
