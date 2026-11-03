<?php

namespace App\Services;

use App\Models\DataSubjectRequest;
use App\Models\DataSubjectRequestStatusHistory;
use App\Models\User;
use App\Notifications\DataSubjectRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DataSubjectRequestService
{
    public function __construct(private readonly AuditLogService $audit)
    {
    }

    public function submit(int $patientId, string $type, string $note, User $user): DataSubjectRequest
    {
        return DB::transaction(function () use ($patientId, $type, $note, $user): DataSubjectRequest {
            $request = DataSubjectRequest::create([
                'patient_id' => $patientId,
                'type' => $type,
                'note' => $note,
                'status' => DataSubjectRequest::STATUS_SUBMITTED,
            ]);
            DataSubjectRequestStatusHistory::create([
                'data_subject_request_id' => $request->id,
                'from_status' => null,
                'to_status' => DataSubjectRequest::STATUS_SUBMITTED,
                'user_id' => $user->id,
            ]);
            $this->audit->log('SUBMIT_DATA_SUBJECT_REQUEST', 'data_subject_request', $request->id, 'SUCCESS', [
                'type' => $type,
            ], $user->id);

            return $request;
        });
    }

    public function review(DataSubjectRequest $request, string $status, string $responseNote, User $user): DataSubjectRequest
    {
        $updated = DB::transaction(function () use ($request, $status, $responseNote, $user): DataSubjectRequest {
            $locked = DataSubjectRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if (! in_array($locked->status, [DataSubjectRequest::STATUS_SUBMITTED, DataSubjectRequest::STATUS_IN_REVIEW], true)
                || ! in_array($status, [
                    DataSubjectRequest::STATUS_IN_REVIEW,
                    DataSubjectRequest::STATUS_COMPLETED,
                    DataSubjectRequest::STATUS_REJECTED,
                ], true)
                || ($locked->status === DataSubjectRequest::STATUS_IN_REVIEW && $status === DataSubjectRequest::STATUS_IN_REVIEW)) {
                throw ValidationException::withMessages(['status' => 'This privacy request cannot make that status transition.']);
            }
            if ($locked->type === DataSubjectRequest::TYPE_ERASURE
                && $status === DataSubjectRequest::STATUS_COMPLETED
                && ! preg_match('/restrict|anonymi[sz]/i', $responseNote)) {
                throw ValidationException::withMessages([
                    'response_note' => 'For erasure requests, explain that clinical records are retained and may be restricted or anonymized as allowed.',
                ]);
            }

            $from = $locked->status;
            $locked->update([
                'status' => $status,
                'handled_by' => $user->id,
                'response_note' => $responseNote,
            ]);
            DataSubjectRequestStatusHistory::create([
                'data_subject_request_id' => $locked->id,
                'from_status' => $from,
                'to_status' => $status,
                'user_id' => $user->id,
                'note' => $responseNote,
            ]);
            $this->audit->log('REVIEW_DATA_SUBJECT_REQUEST', 'data_subject_request', $locked->id, 'SUCCESS', [
                'from_status' => $from,
                'to_status' => $status,
                'type' => $locked->type,
                'has_response' => true,
            ], $user->id);

            return $locked->fresh(['patient.user']);
        });

        $updated->patient?->user?->notify(new DataSubjectRequestNotification($updated));

        return $updated;
    }
}
