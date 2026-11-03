<?php

namespace App\Http\Controllers;

use App\Http\Requests\JoinAppointmentWaitlistRequest;
use App\Http\Requests\UpdateProviderProfileRequest;
use App\Models\Appointment;
use App\Models\AppointmentSlot;
use App\Models\AppointmentType;
use App\Models\Department;
use App\Models\Provider;
use App\Models\Specialty;
use App\Models\Waitlist;
use App\Services\AppointmentReassignmentService;
use App\Services\AuditLogService;
use App\Services\SchedulingService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class DoctorDirectoryController extends Controller
{
    public function __construct(
        private readonly SchedulingService $scheduling,
        private readonly AuditLogService $audit,
        private readonly AppointmentReassignmentService $suggestions,
    ) {
    }

    public function index(Request $request)
    {
        abort_unless($request->user()->hasRole('patient'), 403);
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'specialty_id' => ['nullable', 'integer', 'exists:specialties,id'],
            'consultation_type' => ['nullable', 'in:in_person,virtual'],
            'available_within' => ['nullable', 'integer', 'min:1', 'max:60'],
        ]);

        $query = Provider::query()->with(['department', 'specialties'])
            ->where('active', true);
        if (filled($filters['q'] ?? null)) {
            $term = trim($filters['q']);
            $query->where(function ($builder) use ($term): void {
                $builder->where('display_name', 'like', "%{$term}%")
                    ->orWhereHas('user', fn ($user) => $user->where('name', 'like', "%{$term}%"));
            });
        }
        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }
        if (! empty($filters['specialty_id'])) {
            $query->whereHas('specialties', fn ($specialties) => $specialties->whereKey($filters['specialty_id']));
        }
        $consultationType = $filters['consultation_type'] ?? null;
        if ($consultationType === 'virtual') {
            $query->where('accepts_virtual', true);
        }

        $providers = $query->orderBy('display_name')->get()->map(function (Provider $provider) use ($filters, $consultationType): Provider {
            $provider->next_available_slot = $this->nextSlot(
                $provider,
                30,
                $consultationType
                    ? AppointmentType::query()->where('active', true)->where('telehealth', $consultationType === 'virtual')->value('id')
                    : null
            );
            $days = (int) ($filters['available_within'] ?? 0);
            if ($days && (! $provider->next_available_slot
                || $provider->next_available_slot->starts_at->gt(now()->addDays($days)->endOfDay()))) {
                return $provider->setAttribute('filtered_out', true);
            }

            return $provider;
        })->reject(fn (Provider $provider) => $provider->filtered_out ?? false)->values();

        return view('patient-doctors.index', [
            'providers' => $providers,
            'departments' => Department::query()->orderBy('name')->get(),
            'specialties' => Specialty::query()->orderBy('name')->get(),
            'filters' => $filters,
        ]);
    }

    public function show(Request $request, Provider $provider)
    {
        abort_unless($request->user()->hasRole('patient') && $provider->active, 404);
        $provider->load(['department', 'specialties']);
        $appointmentType = $this->appointmentType($request->query('consultation_type'));
        $windowDays = (int) config('appointments.fully_booked_window_days', 7);
        $fullyBooked = $this->nextSlot($provider, $windowDays, $appointmentType?->id) === null;
        $nextSlot = $this->nextSlot($provider, 60, $appointmentType?->id);
        $alternatives = collect();

        if ($fullyBooked) {
            $template = new Appointment([
                'provider_id' => $provider->id,
                'department_id' => $provider->department_id,
                'appointment_type_id' => $appointmentType?->id,
                'starts_at' => now()->addDay(),
                'ends_at' => now()->addDay()->addMinutes($appointmentType?->default_duration ?? 30),
            ]);
            $template->setRelation('provider', $provider);
            $template->setRelation('appointmentType', $appointmentType);
            $alternatives = $this->suggestions->suggestionsFor($template)
                ->filter(fn (array $candidate): bool => $candidate['specialty_match'])
                ->values();
        }

        return view('patient-doctors.show', compact('provider', 'appointmentType', 'fullyBooked', 'nextSlot', 'alternatives', 'windowDays'));
    }

    public function calendar(Request $request, Provider $provider)
    {
        abort_unless($request->user()->hasRole('patient') && $provider->active, 403);
        $data = $request->validate([
            'month' => ['required', 'date_format:Y-m'],
            'appointment_type_id' => ['nullable', 'integer', 'exists:appointment_types,id'],
        ]);
        $month = Carbon::createFromFormat('Y-m', $data['month'])->startOfMonth();
        $days = [];

        for ($date = $month->copy(); $date->month === $month->month; $date->addDay()) {
            $slots = $this->scheduling->availableSlots((int) $provider->id, $date->toDateString(), $data['appointment_type_id'] ?? null);
            $unavailable = $provider->unavailabilities()
                ->where('starts_at', '<', $date->copy()->endOfDay())
                ->where('ends_at', '>', $date->copy()->startOfDay())
                ->exists();
            $slots = $slots->filter(fn (AppointmentSlot $slot): bool => $slot->starts_at->isFuture())->values();
            $count = $slots->count();
            $state = match (true) {
                $count === 0 && $unavailable => 'unavailable',
                $count === 0 => 'fully_booked',
                $count <= 3 => 'few_slots',
                default => 'available',
            };
            $days[] = [
                'date' => $date->toDateString(),
                'state' => $state,
                'count' => $count,
                'slots' => $slots->map(fn (AppointmentSlot $slot): array => [
                    'id' => $slot->id,
                    'starts_at' => $slot->starts_at->toIso8601String(),
                    'label' => $slot->starts_at->format('g:i A'),
                ])->values(),
            ];
        }

        return response()->json(['month' => $month->format('Y-m'), 'days' => $days]);
    }

    public function editProfile(Provider $provider)
    {
        $this->authorize('updateProfile', $provider);

        return view('providers.profile-edit', compact('provider'));
    }

    public function profileIndex()
    {
        abort_unless(auth()->user()->hasAnyRole(['registration', 'nurse', 'admin', 'super-admin']), 403);
        $providers = Provider::query()->with(['department', 'specialties'])->where('active', true)->orderBy('display_name')->paginate(20);

        return view('providers.profile-index', compact('providers'));
    }

    public function updateProfile(UpdateProviderProfileRequest $request, Provider $provider)
    {
        $data = $request->validated();
        if ($request->hasFile('photo')) {
            if ($provider->photo_path) {
                Storage::disk('public')->delete($provider->photo_path);
            }
            $data['photo_path'] = $request->file('photo')->store('provider-photos', 'public');
            unset($data['photo']);
        }
        $data['accepts_virtual'] = $request->boolean('accepts_virtual');
        $provider->update($data);
        $this->audit->log('UPDATE_PROVIDER_PROFILE', 'provider', $provider->id, 'SUCCESS', [
            'updated_fields' => array_values(array_diff(array_keys($data), ['photo_path'])),
            'photo_updated' => isset($data['photo_path']),
        ], $request->user()->id);

        return back()->with('success', 'Doctor profile updated.');
    }

    public function joinWaitlist(JoinAppointmentWaitlistRequest $request)
    {
        $data = $request->validated();
        $patient = $request->user()->patient()->firstOrFail();
        $provider = Provider::query()->where('active', true)->findOrFail($data['provider_id']);
        abort_unless(
            ! Waitlist::query()->where('patient_id', $patient->id)
                ->where('provider_id', $provider->id)
                ->where('appointment_type_id', $data['appointment_type_id'])
                ->where('status', Waitlist::STATUS_WAITING)
                ->exists(),
            409,
            'You are already on this doctor’s waitlist.'
        );

        $waitlist = Waitlist::create([
            'patient_id' => $patient->id,
            'provider_id' => $provider->id,
            'department_id' => $provider->department_id,
            'appointment_type_id' => $data['appointment_type_id'],
            'preferred_date' => $data['preferred_date'] ?? null,
            'status' => Waitlist::STATUS_WAITING,
        ]);
        $this->audit->log('JOIN_APPOINTMENT_WAITLIST', 'waitlist', $waitlist->id, 'SUCCESS', [
            'provider_id' => $provider->id,
            'appointment_type_id' => $data['appointment_type_id'],
        ], $request->user()->id);

        return back()->with('success', 'You have joined the waitlist. We will notify you when a matching slot opens.');
    }

    private function nextSlot(Provider $provider, int $days, ?int $appointmentTypeId = null): ?AppointmentSlot
    {
        for ($offset = 0; $offset <= $days; $offset++) {
            $slots = $this->scheduling->availableSlots(
                (int) $provider->id,
                now()->addDays($offset)->toDateString(),
                $appointmentTypeId
            );
            $slot = $slots->first(fn (AppointmentSlot $candidate) => $candidate->starts_at->isFuture());
            if ($slot) {
                return $slot;
            }
        }

        return null;
    }

    private function appointmentType(?string $consultationType): ?AppointmentType
    {
        return AppointmentType::query()->where('active', true)
            ->when(in_array($consultationType, ['virtual', 'in_person'], true), fn ($query) => $query->where('telehealth', $consultationType === 'virtual'))
            ->orderBy('id')
            ->first();
    }
}
