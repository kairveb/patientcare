<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\ClinicalDocument;
use App\Models\Encounter;
use App\Models\Prescription;
use App\Models\TelehealthSession;
use App\Services\EncounterService;
use App\Services\MailFailureLogger;
use App\Services\PatientConsentService;
use App\Services\QueueDisplayService;
use App\Services\TelehealthService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class TelehealthApiController extends Controller
{
    public function __construct(
        protected TelehealthService $telehealthService,
        protected EncounterService $encounterService,
        protected QueueDisplayService $queueDisplay,
        protected PatientConsentService $consents,
        protected MailFailureLogger $mailFailureLogger,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('view-appointments'), 403, 'You are not authorized.');

        $query = TelehealthSession::with('appointment.patient', 'appointment.provider');

        if ($request->user()?->hasRole('patient')) {
            $patientId = $request->user()->patient?->id;
            $query->whereHas('appointment', fn ($appointmentQuery) => $appointmentQuery->where('patient_id', $patientId ?? 0));
        } elseif ($request->user()?->hasRole('doctor')) {
            $providerId = $request->user()->provider?->id;
            $query->whereHas('appointment', fn ($appointmentQuery) => $appointmentQuery->where('provider_id', $providerId ?? 0));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        $sessions = $query->orderByDesc('start_time')->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Telehealth sessions retrieved successfully.',
            'data' => collect($sessions->items())
                ->map(fn (TelehealthSession $session) => $this->patientSafeSessionData($session, $request))
                ->all(),
            'meta' => ['current_page' => $sessions->currentPage(), 'last_page' => $sessions->lastPage(), 'total' => $sessions->total()],
        ]);
    }

    /**
     * Create a telehealth session for an appointment.
     */
    public function store(Request $request): JsonResponse
    {
        abort_unless(Gate::allows('start-telehealth'), 403, 'You are not authorized.');

        $data = $request->validate([
            'appointment_id' => ['required', 'exists:appointments,id'],
        ]);

        $appointment = Appointment::with('provider')->findOrFail($data['appointment_id']);
        abort_unless(
            $request->user()->hasRole('super-admin')
                || ($request->user()->hasRole('doctor')
                    && $request->user()->provider?->id === $appointment->provider_id),
            403,
            'Only the assigned doctor or a Super Admin can create this telehealth session.'
        );

        try {
            $session = $this->telehealthService->createSession($appointment);
        } catch (RuntimeException $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'message' => 'The video consultation could not be prepared. Please try again or contact support.',
            ], 503);
        }

        $provider = $this->telehealthService->providerName();
        $configured = $this->telehealthService->isConfigured();

        return response()->json([
            'success' => true,
            'message' => $session->zoom_meeting_id
                ? 'Telehealth session created successfully.'
                : 'Demo telehealth session created. This mode is not for real patient consultations.',
            'data' => $this->patientSafeSessionData($session, $request),
            'integration' => [
                'provider' => $provider,
                'zoom_enabled' => $configured,
                'status' => $session->zoom_meeting_id
                    ? 'CONFIGURED'
                    : ($provider === 'jitsi_demo' ? 'DEMO' : 'CONFIGURED'),
            ],
        ], 201);
    }

    /**
     * Show a telehealth session.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $session = TelehealthSession::with('appointment.patient', 'appointment.provider', 'participants', 'referrals.department')->findOrFail($id);

        if ($request->user()?->hasRole('patient')) {
            $patientId = $request->user()->patient?->id;
            $sessionPatientId = $session->appointment?->patient_id;

            if ($patientId === null || $sessionPatientId !== $patientId) {
                abort(403, 'You can only access your own telehealth session.');
            }
        } elseif ($request->user()?->hasRole('doctor')) {
            $providerId = $request->user()->provider?->id;
            abort_unless(
                $providerId !== null && $session->appointment?->provider_id === $providerId,
                403,
                'You can only access telehealth sessions assigned to your provider.'
            );
        }

        return response()->json([
            'success' => true,
            'message' => 'Telehealth session retrieved successfully.',
            'data' => $this->patientSafeSessionData($session, $request),
        ]);
    }

    public function start(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('start-telehealth'), 403, 'You are not authorized.');

        $session = TelehealthSession::findOrFail($id);
        abort_unless(
            $request->user()->hasRole('doctor')
                && $request->user()->provider?->id === $session->appointment?->provider_id,
            403,
            'Only the doctor assigned to this telehealth session can start it.'
        );
        $this->telehealthService->start($session);

        return response()->json([
            'success' => true,
            'message' => 'Telehealth session started successfully.',
            'data' => $this->patientSafeSessionData($session->fresh(), $request),
        ]);
    }

    private function patientSafeSessionData(TelehealthSession $session, Request $request): array
    {
        $data = $session->toArray();
        unset($data['host_start_url'], $data['join_url'], $data['zoom_meeting_id'], $data['meeting_passcode']);

        $user = $request->user();
        $isAssignedDoctor = $user?->hasRole('doctor')
            && $user->provider?->id === $session->appointment?->provider_id;
        if ($user?->hasRole('super-admin') || $isAssignedDoctor) {
            $data['zoom_meeting_id'] = $session->zoom_meeting_id;
            $data['join_url'] = $session->join_url;
            $data['host_start_url'] = $session->host_start_url;
        } elseif (
            $user?->hasRole('patient')
            && $user->patient?->id === $session->appointment?->patient_id
            && $session->isLive()
            && $session->appointment?->patient
            && $this->consents->hasCurrentGrant($session->appointment->patient, 'TELEHEALTH')
        ) {
            $data['join_url'] = route('telehealth.join', ['session' => $session]);
        }

        return $data;
    }

    public function prescription(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('start-telehealth'), 403, 'You are not authorized.');

        $session = TelehealthSession::with('appointment.patient')->findOrFail($id);
        abort_unless(
            $request->user()?->hasRole('super-admin')
                || ($request->user()?->hasRole('doctor')
                    && $request->user()->provider?->id === $session->appointment?->provider_id),
            403,
            'Only the doctor assigned to this telehealth session or an authorized administrator can prescribe.'
        );

        $validator = Validator::make($request->all(), [
            'medication_name' => ['required_without_all:medications', 'nullable', 'string', 'max:255'],
            'dosage' => ['required_without_all:medications', 'nullable', 'string', 'max:255'],
            'instructions' => ['required_without_all:medications', 'nullable', 'string'],
            'medications' => ['sometimes', 'array'],
            'medications.*' => ['nullable', 'string', 'max:255'],
            'notes' => ['sometimes', 'nullable', 'string'],
        ]);

        $validator->sometimes('medication_name', 'required', fn () => $request->filled('medication_name') || ! $request->has('medications'));

        $data = $validator->validate();

        $patient = $session->appointment?->patient;
        if (! $patient) {
            return response()->json([
                'success' => false,
                'message' => 'No patient is associated with this telehealth session.',
            ], 422);
        }

        $legacyMedications = array_values(array_filter(array_map(static fn ($medication) => trim((string) $medication), $request->input('medications', []))));
        $medicationName = trim((string) ($data['medication_name'] ?? ''));
        if ($medicationName === '' && ! empty($legacyMedications)) {
            $medicationName = $legacyMedications[0];
        }

        $dosage = trim((string) ($data['dosage'] ?? ''));
        if ($dosage === '' && ! empty($legacyMedications)) {
            $dosage = 'As prescribed';
        }

        $instructions = trim((string) ($data['instructions'] ?? ''));
        if ($instructions === '') {
            $instructions = trim((string) ($data['notes'] ?? '')) ?: 'Follow clinician instructions.';
        }

        $medicationName = $medicationName !== '' ? $medicationName : 'Medication prescribed';

        $prescription = DB::transaction(function () use ($session, $patient, $medicationName, $dosage, $instructions, $request) {
            $prescription = Prescription::create([
                'telehealth_session_id' => $session->id,
                'patient_id' => $patient->id,
                'medication_name' => $medicationName,
                'dosage' => $dosage !== '' ? $dosage : 'As prescribed',
                'instructions' => $instructions,
                'prescribed_by' => $request->user()?->id,
                'prescribed_at' => now(),
            ]);

            $this->queueDisplay->addPrescription($prescription);

            return $prescription;
        });

        $document = ClinicalDocument::firstOrCreate([
            'patient_id' => $patient->id,
            'name' => 'Telehealth Prescription ' . $session->id,
        ], [
            'encounter_id' => null,
            'uploaded_by' => $request->user()?->id,
            'path' => 'telehealth/prescriptions/' . now()->format('Ymd_His') . '_' . $patient->id . '.txt',
            'mime_type' => 'text/plain',
        ]);

        $document->update([
            'name' => 'Telehealth Prescription ' . $session->id,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Prescription saved successfully.',
            'data' => $prescription->load(['patient', 'prescribedBy']),
            'document' => $document,
        ]);
    }

    public function reminder(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('start-telehealth'), 403, 'You are not authorized.');

        $session = TelehealthSession::with('appointment.patient')->findOrFail($id);

        $data = $request->validate([
            'channel' => ['nullable', 'in:email,sms'],
            'message' => ['nullable', 'string', 'max:1000'],
        ]);

        $patient = $session->appointment?->patient;
        if (! $patient || empty($patient->email)) {
            return response()->json([
                'success' => false,
                'message' => 'This patient does not have a contact email for reminders.',
            ], 422);
        }

        $channel = $data['channel'] ?? 'email';
        $message = $data['message'] ?? 'This is your telehealth reminder. Please join your session at the scheduled time.';

        if ($channel === 'email') {
            try {
                Mail::to($patient->email)->send(new class($message, $session) extends \Illuminate\Mail\Mailable {
                    public function __construct(public string $message, public TelehealthSession $session)
                    {
                    }

                    public function build(): self
                    {
                        return $this->subject('Telehealth reminder for ' . ($this->session->appointment?->appointment_number ?? 'consultation'))
                            ->html($this->message);
                    }
                });
            } catch (\Throwable $exception) {
                $this->mailFailureLogger->recordFailure('telehealth_reminder', $patient->email, $exception);

                return response()->json([
                    'success' => false,
                    'message' => 'The reminder could not be sent now. The telehealth session remains available; please try again later.',
                ], 503);
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Reminder email sent to ' . $patient->email . '.',
            'data' => [
                'channel' => $channel,
                'patient_email' => $patient->email,
                'session_id' => $session->id,
            ],
        ]);
    }

    public function closeout(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('start-telehealth'), 403, 'You are not authorized.');

        $session = TelehealthSession::with('appointment.patient', 'appointment.provider')->findOrFail($id);

        $data = $request->validate([
            'assessment' => ['nullable', 'string'],
            'plan' => ['nullable', 'string'],
            'discharge_instructions' => ['nullable', 'string'],
            'clinic_note' => ['nullable', 'string'],
        ]);

        $patient = $session->appointment?->patient;
        $provider = $session->appointment?->provider;

        if (! $patient || ! $provider) {
            return response()->json([
                'success' => false,
                'message' => 'This telehealth session is missing its patient or provider information.',
            ], 422);
        }

        $summary = trim(($data['assessment'] ?? '') . PHP_EOL . ($data['plan'] ?? '') . PHP_EOL . ($data['discharge_instructions'] ?? ''));
        $encounter = Encounter::firstOrCreate([
            'appointment_id' => $session->appointment_id,
            'patient_id' => $patient->id,
        ], [
            'encounter_number' => $this->encounterService->generateNumber(),
            'provider_id' => $provider->id,
            'type' => Encounter::TYPE_TELEHEALTH,
            'started_at' => $session->start_time ?? now(),
            'status' => 'COMPLETED',
            'assessment' => $data['assessment'] ?? null,
            'plan' => $data['plan'] ?? null,
            'discharge_instructions' => $data['discharge_instructions'] ?? null,
            'chief_complaint' => $session->appointment?->appointmentType?->name ?? 'Telehealth consultation',
        ]);

        if (! empty($data['clinic_note'])) {
            $this->encounterService->addNote($encounter, $data['clinic_note'], $request->user()?->id);
        }

        if (! empty($summary)) {
            $encounter->update([
                'assessment' => $data['assessment'] ?? $encounter->assessment,
                'plan' => $data['plan'] ?? $encounter->plan,
                'discharge_instructions' => $data['discharge_instructions'] ?? $encounter->discharge_instructions,
            ]);
        }

        $this->telehealthService->end($session);

        return response()->json([
            'success' => true,
            'message' => 'Telehealth consultation closed out successfully.',
            'data' => [
                'session' => $this->patientSafeSessionData($session->fresh(), $request),
                'encounter' => $encounter->fresh(),
            ],
        ]);
    }

    public function cancel(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('start-telehealth'), 403, 'You are not authorized.');

        $session = TelehealthSession::findOrFail($id);
        $this->telehealthService->cancel($session);

        return response()->json([
            'success' => true,
            'message' => 'Telehealth session cancelled successfully.',
            'data' => $this->patientSafeSessionData($session->fresh(), $request),
        ]);
    }

    public function end(Request $request, int $id): JsonResponse
    {
        abort_unless(Gate::allows('start-telehealth'), 403, 'You are not authorized.');

        $session = TelehealthSession::findOrFail($id);
        $this->telehealthService->end($session);

        return response()->json([
            'success' => true,
            'message' => 'Telehealth session ended successfully.',
            'data' => $this->patientSafeSessionData($session->fresh(), $request),
        ]);
    }
}
