@extends('layouts.hims')

@section('title', 'Provider Coverage & Reassignments')

@section('content')
<div class="space-y-6">
    <header>
        <h1 class="text-2xl font-bold text-slate-900">Provider Coverage & Reassignments</h1>
        <p class="mt-1 text-sm text-slate-600">Record unavailable time, review affected visits, and confirm a safe replacement slot.</p>
    </header>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
            @foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach
        </div>
    @endif

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-lg font-semibold text-slate-900">Mark provider unavailable</h2>
        <form method="POST" action="{{ route('provider-unavailabilities.store') }}" class="mt-4 grid gap-3 md:grid-cols-3">
            @csrf
            <label class="text-sm font-medium text-slate-700">Doctor
                <select name="provider_id" required @disabled($providers->isEmpty()) class="mt-1 w-full rounded border-slate-300">
                    <option value="">Select doctor</option>
                    @foreach ($providers as $provider)
                        <option value="{{ $provider->id }}" @selected((string) old('provider_id', auth()->user()->provider?->id) === (string) $provider->id)>{{ $provider->full_name }} — {{ $provider->department?->name }}</option>
                    @endforeach
                </select>
                @if ($providers->isEmpty())
                    <span class="mt-1 block text-xs text-amber-700">No active doctors are available.</span>
                @endif
            </label>
            <label class="text-sm font-medium text-slate-700">From
                <input name="starts_at" type="datetime-local" value="{{ old('starts_at') }}" required class="mt-1 w-full rounded border-slate-300">
            </label>
            <label class="text-sm font-medium text-slate-700">Until
                <input name="ends_at" type="datetime-local" value="{{ old('ends_at') }}" required class="mt-1 w-full rounded border-slate-300">
            </label>
            <label class="text-sm font-medium text-slate-700">Reason
                <select name="reason" required class="mt-1 w-full rounded border-slate-300">
                    <option value="emergency">Emergency</option>
                    <option value="sick_leave">Sick leave</option>
                    <option value="other">Other</option>
                </select>
            </label>
            <label class="text-sm font-medium text-slate-700 md:col-span-2">Details (optional)
                <input name="details" maxlength="1000" class="mt-1 w-full rounded border-slate-300" placeholder="Brief operational note">
            </label>
            <div class="md:col-span-3">
                <button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">Save unavailability and find affected appointments</button>
            </div>
        </form>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b p-4">
            <div><h2 class="text-lg font-semibold text-slate-900">Reassignment cases</h2><p class="text-sm text-slate-500">{{ $cases->total() }} cases</p></div>
            <form method="GET" class="hims-filter-bar hims-filter-bar--compact">
                <label class="sr-only" for="status">Filter by status</label>
                <select id="status" name="status" onchange="this.form.submit()" class="rounded border-slate-300 text-sm">
                    <option value="">All statuses</option>
                    @foreach ($statuses as $status)<option value="{{ $status }}" @selected(request('status') === $status)>{{ str_replace('_', ' ', $status) }}</option>@endforeach
                </select>
            </form>
        </div>
        <div class="divide-y divide-slate-200">
            @forelse ($cases as $case)
                @php($candidates = $candidateMap[$case->id] ?? collect())
                <article class="space-y-4 p-4">
                    <div class="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-slate-900">{{ $case->appointment->patient->full_name ?? trim($case->appointment->patient->first_name.' '.$case->appointment->patient->last_name) }}</h3>
                            <p class="text-sm text-slate-600">Original: {{ $case->originalProvider->full_name }} · {{ $case->appointment->starts_at->format('M j, Y g:i A') }} · {{ $case->appointment->appointmentType?->telehealth ? 'Virtual' : 'In-person' }}</p>
                            <p class="mt-1 text-xs text-slate-500">Reason: {{ str_replace('_', ' ', $case->reason) }}</p>
                        </div>
                        <span class="rounded-full bg-amber-100 px-3 py-1 text-xs font-semibold text-amber-900">{{ str_replace('_', ' ', $case->status) }}</span>
                    </div>
                    @if ($case->status === \App\Models\AppointmentReassignment::STATUS_WAITLISTED)
                        <p class="text-sm text-amber-800">No matching open slot was found. Patient is on the waitlist. Next available dates: {{ $case->next_available_dates ? implode(', ', array_map(fn ($date) => \Illuminate\Support\Carbon::parse($date)->format('M j, Y'), $case->next_available_dates)) : 'Not found in generated slots; check the schedule.' }}.</p>
                    @elseif ($candidates->isNotEmpty() && in_array($case->status, ['PENDING_REVIEW', 'DECLINED_BY_PATIENT'], true))
                        <div class="grid gap-3 lg:grid-cols-2">
                            @foreach ($candidates as $candidate)
                                <div class="rounded-lg border border-slate-200 p-3">
                                    <p class="font-medium text-slate-800">{{ $candidate['provider']->full_name }}</p>
                                    <p class="text-sm text-slate-600">{{ $candidate['slot']->starts_at->format('M j, Y g:i A') }} · {{ $candidate['explanation'] }}</p>
                                    <div class="mt-3 flex flex-wrap gap-2">
                                        <form method="POST" action="{{ route('appointment-reassignments.decide', $case) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="{{ $candidate['provider']->id === $case->suggested_provider_id && $candidate['slot']->id === $case->suggested_slot_id ? 'approve' : 'pick' }}">
                                            <input type="hidden" name="provider_id" value="{{ $candidate['provider']->id }}">
                                            <input type="hidden" name="appointment_slot_id" value="{{ $candidate['slot']->id }}">
                                            <button class="rounded bg-teal-700 px-3 py-1.5 text-sm font-semibold text-white">{{ $candidate['provider']->id === $case->suggested_provider_id && $candidate['slot']->id === $case->suggested_slot_id ? 'Approve suggestion' : 'Pick this doctor' }}</button>
                                        </form>
                                        <form method="POST" action="{{ route('appointment-reassignments.decide', $case) }}">
                                            @csrf
                                            <input type="hidden" name="action" value="reschedule">
                                            <input type="hidden" name="provider_id" value="{{ $candidate['provider']->id }}">
                                            <input type="hidden" name="appointment_slot_id" value="{{ $candidate['slot']->id }}">
                                            <button class="rounded border border-slate-300 px-3 py-1.5 text-sm font-semibold text-slate-700">Reschedule</button>
                                        </form>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                        <form method="POST" action="{{ route('appointment-reassignments.decide', $case) }}">
                            @csrf
                            <input type="hidden" name="action" value="waitlist">
                            <button class="text-sm font-semibold text-amber-800 underline">Move to waitlist instead</button>
                        </form>
                    @else
                        <p class="text-sm text-slate-600">No matching open slot is currently available. Review generated schedules or place the patient on the waitlist.</p>
                        @if (in_array($case->status, ['PENDING_REVIEW', 'DECLINED_BY_PATIENT'], true))
                            <form method="POST" action="{{ route('appointment-reassignments.decide', $case) }}">@csrf<input type="hidden" name="action" value="waitlist"><button class="rounded border border-amber-300 px-3 py-1.5 text-sm font-semibold text-amber-900">Waitlist patient</button></form>
                        @endif
                    @endif
                </article>
            @empty
                <p class="p-8 text-center text-sm text-slate-500">No reassignment cases found.</p>
            @endforelse
        </div>
        <div class="p-4">{{ $cases->links() }}</div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Recently recorded unavailable periods</h2>
        <ul class="mt-3 divide-y">
            @forelse ($unavailabilities as $record)
                <li class="py-2 text-sm text-slate-600">Dr. {{ $record->provider->full_name }} · {{ $record->starts_at->format('M j, Y g:i A') }}–{{ $record->ends_at->format('M j, Y g:i A') }} · {{ str_replace('_', ' ', $record->reason) }}</li>
            @empty
                <li class="py-2 text-sm text-slate-500">No unavailability has been recorded.</li>
            @endforelse
        </ul>
    </section>
</div>
@endsection
