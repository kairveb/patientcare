<?php

namespace App\Services;

use App\Models\Admission;
use App\Models\BillingClearanceRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingClearanceRequestService
{
    public function __construct(
        protected DischargeSummaryService $dischargeSummaries,
        protected AuditLogService $audit,
    ) {
    }

    public function request(Admission $admission, User $requester): BillingClearanceRequest
    {
        if (! $requester->hasRole('registration', 'nurse') || ! $requester->hasPermission('view-admissions')) {
            abort(403, 'Only Registration Staff or a Nurse can request billing clearance.');
        }

        return DB::transaction(function () use ($admission, $requester): BillingClearanceRequest {
            $lockedAdmission = Admission::query()
                ->with(['attendingProvider', 'dischargeSummary'])
                ->lockForUpdate()
                ->findOrFail($admission->id);

            if (! in_array($lockedAdmission->status, [Admission::STATUS_ADMITTED, Admission::STATUS_TRANSFERRED], true)) {
                throw ValidationException::withMessages([
                    'admission' => ['Billing clearance can be requested only for an admitted patient.'],
                ]);
            }

            $this->dischargeSummaries->assertAdmissionSummaryApproved($lockedAdmission);

            $existingRequest = $lockedAdmission->billingClearanceRequests()->first();
            if ($existingRequest) {
                if ($existingRequest->discharge_summary_id === $lockedAdmission->dischargeSummary->id) {
                    return $existingRequest;
                }

                throw ValidationException::withMessages([
                    'billing_clearance' => ['A billing clearance request already exists for this admission.'],
                ]);
            }

            $clearanceRequest = BillingClearanceRequest::create([
                'admission_id' => $lockedAdmission->id,
                'discharge_summary_id' => $lockedAdmission->dischargeSummary->id,
                'requested_by' => $requester->id,
                'status' => 'REQUESTED',
                'requested_at' => now(),
            ]);

            $this->audit->log(
                'REQUEST_BILLING_CLEARANCE',
                'billing_clearance_request',
                $clearanceRequest->id,
                'SUCCESS',
                ['admission_id' => $lockedAdmission->id, 'discharge_summary_id' => $lockedAdmission->dischargeSummary->id],
                $requester->id
            );

            return $clearanceRequest;
        });
    }
}
