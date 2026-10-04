<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateAdmissionFromErVisitRequest;
use App\Http\Requests\CreateAdmissionRequest;
use App\Http\Requests\RequestBillingClearanceFormRequest;
use App\Http\Requests\UpdateAdmissionGuaranteeRequest;
use App\Models\Admission;
use App\Models\Bed;
use App\Models\BedReservation;
use App\Models\DischargeSummary;
use App\Models\EncounterBill;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\Provider;
use App\Models\Ward;
use App\Services\AdmissionService;
use App\Services\BedManagementService;
use App\Services\BillingAggregationService;
use App\Services\BillingClearanceRequestService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class InpatientController extends Controller
{
    public function __construct(
        protected AdmissionService $admissions,
        protected BedManagementService $beds
    ) {}

    public function overview()
    {
        $wards = Ward::with(['rooms.beds.activeAssignment.admission.patient'])->get();
        $bedStats = Bed::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $admissions = Admission::with(['patient', 'bedAssignments.bed.room.ward'])->orderBy('created_at', 'desc')->limit(5)->get();

        return view('inpatient.overview', compact('wards', 'bedStats', 'admissions'));
    }

    public function beds(Request $request)
    {
        $selectedWardId = $request->integer('ward_id') ?: null;
        $wards = Ward::with(['rooms.beds.activeAssignment.admission.patient'])
            ->when($selectedWardId, fn ($query) => $query->whereKey($selectedWardId))
            ->get();
        $wardOptions = Ward::orderBy('name')->get();
        $bedStats = Bed::query()
            ->when($selectedWardId, fn ($query) => $query->whereHas('room', fn ($rooms) => $rooms->where('ward_id', $selectedWardId)))
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        return view('inpatient.beds', compact('wards', 'wardOptions', 'selectedWardId', 'bedStats'));
    }

    public function setBedStatus(Request $request, Bed $bed)
    {
        $request->validate(['status' => ['required', 'string']]);
        try {
            $this->beds->setStatus($bed, $request->status);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return back()->with('success', 'Bed status updated.');
    }

    public function admissions(Request $request)
    {
        $query = Admission::with([
            'patient',
            'bedAssignments.bed.room.ward',
            'discharge',
            'attendingProvider',
            'dischargeSummary',
        ])->orderBy('created_at', 'desc');

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        $admissions = $query->paginate(15);
        foreach ($admissions as $admission) {
            if (in_array($admission->status, [Admission::STATUS_ADMITTED, Admission::STATUS_TRANSFERRED], true)) {
                $reason = null;
                $admission->setAttribute('can_discharge', $this->isDischargeReady($admission, app(BillingAggregationService::class), $reason));
                $admission->setAttribute('discharge_block_reason', $reason);
            } else {
                $admission->setAttribute('can_discharge', false);
                $admission->setAttribute('discharge_block_reason', null);
            }
        }
        $statuses = [Admission::STATUS_REQUESTED, Admission::STATUS_APPROVED, Admission::STATUS_ADMITTED, Admission::STATUS_TRANSFERRED, Admission::STATUS_DISCHARGED];

        return view('inpatient.admissions', compact('admissions', 'statuses'));
    }

    public function createAdmission()
    {
        $patients = Patient::orderBy('last_name')->get();
        $providers = Provider::where('active', true)->get();

        return view('inpatient.create-admission', compact('patients', 'providers'));
    }

    public function storeAdmission(CreateAdmissionRequest $request)
    {
        $admission = $this->admissions->create($request->validated());

        return redirect()->route('admissions.show', $admission)->with('success', 'Admission request created.');
    }

    public function createFromErVisit(ErVisit $visit)
    {
        $visit->load(['patient', 'queue', 'triageAssessments.triageVital']);
        $providers = Provider::where('active', true)->get();

        return view('inpatient.create-admission-from-er', compact('visit', 'providers'));
    }

    public function storeFromErVisit(CreateAdmissionFromErVisitRequest $request, ErVisit $visit)
    {
        $admission = $this->admissions->createFromErVisit($visit, $request->validated());

        return redirect()->route('admissions.show', $admission)->with('success', 'ER patient converted to inpatient admission.');
    }

    public function showAdmission(Admission $admission, BillingAggregationService $billing)
    {
        $admission->load(['patient', 'bedAssignments.bed.room.ward', 'bedReservations.bed', 'transfers.fromBed.room.ward', 'transfers.toBed.room.ward', 'discharge', 'attendingProvider', 'dischargeSummary', 'billingClearanceRequests.requestedBy:id,name']);
        $selectedWardId = request()->integer('ward_id') ?: null;
        $availableBeds = $this->beds->availableBeds($selectedWardId);
        $assignableBeds = $this->beds->assignableBeds($admission, $selectedWardId);
        $wards = Ward::with(['rooms.beds'])->get();
        $billingSummary = null;
        $billingError = null;
        $paymentStatus = null;
        if (auth()->user()->hasRole('super-admin') && auth()->user()->can('view-billing')) {
            try {
                $billingSummary = $billing->previewForAdmission($admission);
            } catch (ValidationException $exception) {
                $billingError = $exception->errors()['billing'][0] ?? 'Billing details are incomplete.';
            }
        }
        if (auth()->user()->can('view-payment-status')) {
            $paymentStatus = $admission->bills()->latest('generated_at')->value('status');
        }
        $dischargeBlockReason = null;
        $canDischarge = $this->isDischargeReady($admission, $billing, $dischargeBlockReason);

        return view('inpatient.show-admission', compact('admission', 'availableBeds', 'assignableBeds', 'wards', 'selectedWardId', 'billingSummary', 'billingError', 'paymentStatus', 'canDischarge', 'dischargeBlockReason'));
    }

    public function updateGuaranteeDetails(UpdateAdmissionGuaranteeRequest $request, Admission $admission)
    {
        $this->admissions->updateGuaranteeDetails($admission, $request->validated(), $request->user());

        return back()->with('success', 'Guarantor and LOA reference details saved.');
    }

    public function requestBillingClearance(
        RequestBillingClearanceFormRequest $request,
        Admission $admission,
        BillingClearanceRequestService $clearances
    ) {
        $clearances->request($admission, $request->user());

        return back()->with('success', 'Billing clearance request recorded.');
    }

    public function approveAdmission(Admission $admission)
    {
        $this->admissions->approve($admission);

        return back()->with('success', 'Admission approved.');
    }

    public function admit(Request $request, Admission $admission)
    {
        $data = $request->validate([
            'bed_id' => ['required', 'exists:beds,id'],
            'reservation_id' => ['nullable', 'exists:bed_reservations,id'],
        ]);

        try {
            $this->admissions->admit($admission, $data['bed_id'], $data['reservation_id'] ?? null);

            return back()->with('success', 'Patient admitted and bed assigned.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function reserveBed(Request $request, Admission $admission)
    {
        $data = $request->validate(['bed_id' => ['required', 'exists:beds,id']]);
        try {
            $this->beds->reserveBed($admission, $data['bed_id']);

            return back()->with('success', 'Bed reserved.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function cancelReservation(Admission $admission, BedReservation $reservation)
    {
        try {
            $this->beds->cancelReservation($reservation->id, null, (int) $admission->id);

            return back()->with('success', 'Bed reservation cancelled.');
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }
    }

    public function transfer(Request $request, Admission $admission)
    {
        $data = $request->validate([
            'to_bed_id' => ['required', 'integer', Rule::exists('beds', 'id')->where('status', Bed::STATUS_AVAILABLE)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            $this->beds->transfer($admission, $data['to_bed_id'], $data['reason'] ?? null);

            return back()->with('success', 'Patient transferred.');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            if (isset($errors['destination_bed_id'])) {
                $errors['to_bed_id'] = array_merge($errors['to_bed_id'] ?? [], $errors['destination_bed_id']);
                unset($errors['destination_bed_id']);
            }

            return back()->withErrors($errors);
        }
    }

    public function discharge(Request $request, Admission $admission)
    {
        abort_unless($request->user()->can('execute-discharges'), 403);
        $data = $request->validate([
            'reason' => ['nullable', 'string'],
            'disposition' => ['nullable', 'string'],
            'notes' => ['nullable', 'string'],
        ]);

        $this->admissions->discharge($admission, $data);

        return back()->with('success', 'Patient discharged and bed released for cleaning.');
    }

    private function isDischargeReady(Admission $admission, BillingAggregationService $billing, ?string &$reason): bool
    {
        $summary = $admission->dischargeSummary;
        if (
            ! $summary
            || $summary->status !== DischargeSummary::STATUS_APPROVED
            || ! $summary->approved_at
            || ! $admission->attendingProvider?->user_id
            || $summary->attending_provider_id !== $admission->attending_provider_id
            || $summary->approved_by !== $admission->attendingProvider->user_id
        ) {
            $reason = 'Discharge is unavailable until the attending physician authors and approves the summary.';

            return false;
        }

        if (! auth()->user()->can('view-billing') && ! auth()->user()->can('view-payment-status')) {
            $reason = 'Discharge is unavailable because the current billing statement cannot be verified.';

            return false;
        }

        try {
            $billingSummary = $billing->previewForAdmission($admission);
        } catch (ValidationException $exception) {
            $reason = $exception->errors()['billing'][0] ?? 'Billing details are incomplete.';

            return false;
        }

        if (($billingSummary['status'] ?? null) !== EncounterBill::STATUS_PAID) {
            $reason = 'Discharge is unavailable until the current billing statement is paid.';

            return false;
        }

        if (! ($billingSummary['is_current'] ?? false)) {
            $reason = 'Discharge is unavailable because the billing statement is out of date.';

            return false;
        }

        $reason = null;

        return true;
    }
}
