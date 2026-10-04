<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOpdVitalsRequest;
use App\Models\Admission;
use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\Patient;
use App\Rules\Icd10Code;
use App\Services\AdmissionService;
use App\Services\BillingAggregationService;
use App\Services\EncounterService;
use App\Services\PrescriptionService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class EncounterController extends Controller
{
    public function __construct(protected EncounterService $encounters) {}

    public function index(Request $request)
    {
        $query = Encounter::with(['patient', 'provider'])->orderBy('started_at', 'desc');

        $user = auth()->user();
        if ($user && $user->hasRole('doctor')) {
            $providerId = $user->provider?->id;
            if ($providerId) {
                $query->where('provider_id', $providerId);
            } else {
                $query->whereRaw('0 = 1');
            }
        } elseif ($user && $user->hasRole('nurse')) {
            $query->where('type', Encounter::TYPE_EMERGENCY);
        }

        if ($request->get('type')) {
            $query->where('type', $request->get('type'));
        }

        if ($request->get('status')) {
            $query->where('status', $request->get('status'));
        }

        if ($request->get('q')) {
            $term = $request->get('q');
            $query->whereHas('patient', fn ($p) => $p->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%"));
        }

        $encounters = $query->paginate(15);

        $patientOptions = Patient::query();
        if ($user && $user->hasRole('doctor')) {
            $providerId = $user->provider?->id;
            if ($providerId) {
                $patientOptions->where(function ($query) use ($providerId) {
                    $query->whereIn('id', Encounter::where('provider_id', $providerId)->select('patient_id'))
                        ->orWhereIn('id', Appointment::where('provider_id', $providerId)
                            ->where('status', Appointment::STATUS_CHECKED_IN)
                            ->select('patient_id'));
                });
            } else {
                $patientOptions->whereRaw('0 = 1');
            }
        }

        return view('encounters.index', [
            'encounters' => $encounters,
            'patientOptions' => $patientOptions->orderBy('last_name')->get(),
            'providers' => $user?->provider ? collect([$user->provider]) : collect(),
            'appointments' => $user?->provider
                ? Appointment::with('patient')
                    ->where('provider_id', $user->provider->id)
                    ->where('status', Appointment::STATUS_CHECKED_IN)
                    ->get()
                : collect(),
        ]);
    }

    public function create(Request $request)
    {
        abort_unless(
            $request->user()->hasRole('doctor')
                && $request->user()->hasPermission('create-encounters')
                && $request->user()->provider,
            403,
            'Only an authorized doctor with a provider profile can create encounters.'
        );

        $provider = $request->user()->provider;
        $providers = collect([$provider]);
        $appointments = Appointment::with('patient')
            ->where('provider_id', $provider->id)
            ->where('status', Appointment::STATUS_CHECKED_IN)
            ->get();
        $patientIds = Encounter::where('provider_id', $provider->id)
            ->pluck('patient_id')
            ->merge($appointments->pluck('patient_id'))
            ->unique();
        $patients = Patient::whereIn('id', $patientIds)->orderBy('last_name')->get();
        $selectedPatient = $request->get('patient_id') ? Patient::find($request->get('patient_id')) : null;

        return view('encounters.create', compact('patients', 'providers', 'appointments', 'selectedPatient'));
    }

    public function store(Request $request)
    {
        abort_unless(
            $request->user()->hasRole('doctor') && $request->user()->hasPermission('create-encounters'),
            403,
            'Only an authorized doctor can create encounters.'
        );

        $vitalFields = array_keys(StoreOpdVitalsRequest::sharedRules());
        $hasVitals = collect($vitalFields)->contains(fn (string $field): bool => $request->filled("vitals.{$field}"));
        $vitalRules = [];
        if ($hasVitals) {
            foreach (StoreOpdVitalsRequest::sharedRules() as $field => $rules) {
                $vitalRules["vitals.{$field}"] = $rules;
            }
        }

        $data = $request->validate(array_merge([
            'patient_id' => ['required', 'exists:patients,id'],
            'provider_id' => ['required', 'exists:providers,id'],
            'appointment_id' => ['nullable', 'exists:appointments,id'],
            'type' => ['required', 'in:OUTPATIENT,TELEHEALTH,EMERGENCY'],
            'chief_complaint' => ['nullable', 'string'],
            'history_of_present_illness' => ['nullable', 'string', 'max:30000'],
            'physical_examination' => ['nullable', 'string', 'max:30000'],
            'primary_diagnosis_code' => ['nullable', 'string', 'max:20', app(Icd10Code::class)],
            'primary_diagnosis_description' => ['nullable', 'string', 'max:255'],
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'follow_up_date' => ['nullable', 'date'],
            'vitals' => ['nullable', 'array:blood_pressure,heart_rate,respiratory_rate,temperature,spo2,height_cm,weight,pain_score'],
            'notes' => ['nullable', 'string'],
        ], $vitalRules));
        if (! $hasVitals) {
            $data['vitals'] = null;
        }

        abort_unless(
            $request->user()->provider?->id === (int) $data['provider_id'],
            403,
            'Doctors may only create encounters for their own provider profile.'
        );

        $encounter = $this->encounters->create($data);

        return redirect()->route('encounters.show', $encounter)->with('success', 'Encounter created.');
    }

    public function show(Encounter $encounter, BillingAggregationService $billing)
    {
        $encounter->load(['patient', 'provider', 'appointment.vitals', 'vitals', 'admission', 'notes.author', 'prescriptions.prescribedBy', 'dischargeSummary']);
        $inpatientAdmission = Admission::query()
            ->with(['attendingProvider', 'dischargeSummary'])
            ->where('patient_id', $encounter->patient_id)
            ->whereIn('status', [Admission::STATUS_ADMITTED, Admission::STATUS_TRANSFERRED])
            ->latest('admitted_at')
            ->first();
        $billingSummary = null;
        $billingError = null;
        if (auth()->user()->hasRole('super-admin') && auth()->user()->can('view-billing')) {
            try {
                $billingSummary = $billing->previewForEncounter($encounter);
            } catch (ValidationException $exception) {
                $billingError = $exception->errors()['billing'][0] ?? 'Billing details are incomplete.';
            }
        }

        return view('encounters.show', compact('encounter', 'inpatientAdmission', 'billingSummary', 'billingError'));
    }

    public function prescribe(Request $request, Encounter $encounter, PrescriptionService $prescriptions)
    {
        $this->authorize('update', $encounter);

        $data = $request->validate([
            'medication_name' => ['required', 'string', 'max:255'],
            'dosage' => ['required', 'string', 'max:255'],
            'instructions' => ['required', 'string', 'max:5000'],
        ]);

        $prescriptions->createForEncounter($encounter, $data, $request->user());

        return redirect()->route('encounters.show', $encounter)->with('success', 'Prescription saved.');
    }

    public function complete(Request $request, Encounter $encounter)
    {
        $this->authorize('complete', $encounter);

        $data = $request->validate([
            'history_of_present_illness' => ['required', 'string', 'max:30000'],
            'physical_examination' => ['required', 'string', 'max:30000'],
            'primary_diagnosis_code' => ['required', 'string', 'max:20', app(Icd10Code::class)],
            'primary_diagnosis_description' => ['required', 'string', 'max:255'],
            'assessment' => ['required', 'string', 'max:30000'],
            'plan' => ['required', 'string', 'max:30000'],
            'follow_up_date' => ['nullable', 'date'],
        ]);

        $this->encounters->complete($encounter, $data, $request->user());

        return back()->with('success', 'Encounter completed.');
    }

    public function requestAdmission(Request $request, Encounter $encounter, AdmissionService $admissions)
    {
        $this->authorize('update', $encounter);
        abort_unless($request->user()->hasRole('doctor'), 403, 'Only doctors may request admission.');

        $data = $request->validate(['reason' => ['required', 'string', 'max:5000']]);
        $admissions->requestFromEncounter($encounter, $request->user(), $data['reason']);

        return back()->with('success', 'Admission request sent to Bed Management.');
    }
}
