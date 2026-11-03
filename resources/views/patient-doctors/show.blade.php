@extends('layouts.hims')
@section('title', $provider->full_name)
@section('page-title', $provider->full_name)
@section('page-description', 'Review the doctor profile, choose a consultation type, and book an available slot.')
@section('content')
<div class="space-y-6" x-data="doctorCalendar('{{ route('doctors.calendar', $provider) }}', {{ $appointmentType?->id ?? 'null' }}, @js(request('reason', '')), @js(request('follow_up_date', '')), @js(request('parent_appointment_id', '')))">
    <section class="flex flex-col gap-5 rounded-2xl border bg-white p-5 shadow-sm sm:flex-row sm:items-center">
        @if ($provider->photo_path)
            <img src="{{ Storage::disk('public')->url($provider->photo_path) }}" class="h-24 w-24 rounded-full object-cover" alt="">
        @else
            <div class="grid h-24 w-24 shrink-0 place-items-center rounded-full bg-teal-50 text-3xl font-bold text-teal-800" aria-hidden="true">{{ mb_substr($provider->full_name, 0, 1) }}</div>
        @endif
        <div class="min-w-0 flex-1">
            <p class="font-semibold text-slate-900">{{ $provider->specialties->pluck('name')->join(', ') ?: 'General consultation' }}</p>
            <p class="text-sm text-slate-600">{{ $provider->department?->name ?? 'General department' }}</p>
            @if ($provider->years_of_practice)<p class="mt-1 text-sm text-slate-600">{{ $provider->years_of_practice }} years of practice</p>@endif
            @if ($provider->languages)<p class="text-sm text-slate-600">Languages: {{ $provider->languages }}</p>@endif
            <p class="mt-3 whitespace-pre-line text-sm text-slate-700">{{ $provider->bio ?: 'Profile description not provided.' }}</p>
        </div>
        <label class="block min-w-48 text-sm font-medium text-slate-700">Consultation
            <select class="mt-1 w-full rounded-lg border-slate-300 text-sm" x-model="appointmentTypeId" @change="loadMonth()">
                @foreach (\App\Models\AppointmentType::where('active', true)->orderBy('name')->get() as $type)
                    @if (! $type->telehealth || $provider->accepts_virtual)
                        <option value="{{ $type->id }}" @selected($appointmentType?->id === $type->id)>{{ $type->name }} ({{ $type->telehealth ? 'Virtual' : 'In-person' }})</option>
                    @endif
                @endforeach
            </select>
            @can('updateProfile', $provider)
                <a href="{{ route('providers.profile.edit', $provider) }}" class="mt-3 inline-block text-sm font-medium text-teal-800 underline">Edit profile</a>
            @endcan
        </label>
    </section>

    @if (request('follow_up_date'))
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">Your doctor suggested {{ \Illuminate\Support\Carbon::parse(request('follow_up_date'))->format('M j, Y') }} for follow-up.</div>
    @endif
    @if ($fullyBooked)
        <section class="rounded-xl border border-amber-200 bg-amber-50 p-5">
            <h2 class="font-semibold text-amber-950">Fully booked this week</h2>
            @if ($nextSlot)<p class="mt-1 text-sm text-amber-900">Next available date: {{ $nextSlot->starts_at->format('l, M j, Y') }}.</p>
            @else<p class="mt-1 text-sm text-amber-900">No later slot is currently listed. Join the waitlist and staff can follow up.</p>@endif
            <form method="POST" action="{{ route('doctors.waitlist') }}" class="mt-4 flex flex-col gap-3 sm:flex-row sm:items-end">
                @csrf
                <input type="hidden" name="provider_id" value="{{ $provider->id }}">
                <input type="hidden" name="appointment_type_id" value="{{ $appointmentType?->id }}">
                <label class="text-sm">Preferred date (optional)<input type="date" name="preferred_date" min="{{ today()->toDateString() }}" class="mt-1 block rounded-lg border-slate-300"></label>
                <button class="rounded-lg border border-amber-300 bg-white px-4 py-2 text-sm font-semibold text-amber-950">Join waitlist</button>
            </form>
            @if ($alternatives->isNotEmpty())
                <div class="mt-5 border-t border-amber-200 pt-4">
                    <h3 class="font-semibold text-amber-950">Other doctors with matching availability</h3>
                    <div class="mt-2 grid gap-2 sm:grid-cols-2">
                        @foreach ($alternatives as $candidate)
                            <a class="rounded-lg border border-amber-200 bg-white p-3 text-sm hover:border-teal-400" href="{{ route('appointments.create', ['provider_id' => $candidate['provider']->id, 'appointment_slot_id' => $candidate['slot']->id, 'appointment_type_id' => $appointmentType?->id, 'starts_at' => $candidate['slot']->starts_at->toDateTimeString(), 'reason' => request('reason'), 'parent_appointment_id' => request('parent_appointment_id')]) }}">
                                <span class="font-semibold">{{ $candidate['provider']->full_name }}</span><span class="block">{{ $candidate['slot']->starts_at->format('D, M j · g:i A') }}</span><span class="block text-slate-600">{{ $candidate['explanation'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            @endif
        </section>
    @endif

    <section class="rounded-2xl border bg-white p-4 shadow-sm sm:p-6">
        <div class="mb-4 flex items-center justify-between gap-3">
            <button type="button" @click="moveMonth(-1)" class="rounded-lg border px-3 py-2 text-sm">Previous</button>
            <h2 class="text-lg font-semibold text-slate-900" x-text="monthLabel"></h2>
            <button type="button" @click="moveMonth(1)" class="rounded-lg border px-3 py-2 text-sm">Next</button>
        </div>
        <div class="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-500">
            <template x-for="name in weekdayNames" :key="name"><div class="py-2" x-text="name"></div></template>
        </div>
        <div class="grid grid-cols-7 gap-1" aria-label="Doctor availability calendar">
            <template x-for="blank in firstWeekday" :key="'blank-'+blank"><div class="min-h-16 sm:min-h-20"></div></template>
            <template x-for="day in days" :key="day.date">
                <button type="button" @click="selectDay(day)" :disabled="day.state === 'unavailable' || day.state === 'fully_booked'" class="min-h-16 rounded-lg border p-1 text-left text-xs disabled:cursor-not-allowed disabled:opacity-70 sm:min-h-20 sm:p-2" :class="stateClasses[day.state]" :aria-label="`${day.date}: ${stateLabels[day.state]}`">
                    <span class="block font-semibold" x-text="Number(day.date.slice(-2))"></span><span class="mt-1 hidden sm:block" x-text="stateLabels[day.state]"></span><span class="mt-1 block" x-show="day.count" x-text="`${day.count} slots`"></span>
                </button>
            </template>
        </div>
        <div class="mt-4 flex flex-wrap gap-3 text-xs text-slate-700"><span>● Available</span><span class="text-amber-700">● Few slots left</span><span class="text-slate-500">● Fully booked</span><span class="text-red-700">● Doctor unavailable</span></div>
        <div class="mt-6" x-show="selectedDay">
            <h3 class="font-semibold text-slate-900" x-text="selectedDay ? `Available times for ${selectedDay.date}` : ''"></h3>
            <div class="mt-3 flex flex-wrap gap-2">
                <template x-for="slot in (selectedDay?.slots ?? [])" :key="slot.id"><a class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800" :href="bookingUrl(slot)" x-text="slot.label"></a></template>
                <p x-show="selectedDay && selectedDay.slots.length === 0" class="text-sm text-slate-600">No times are available on this date.</p>
            </div>
        </div>
        <p x-show="loading" class="mt-4 text-sm text-slate-500" role="status">Loading availability…</p>
        <p x-show="error" x-text="error" class="mt-4 text-sm text-red-700" role="alert"></p>
    </section>
</div>
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('doctorCalendar', (endpoint, typeId, reason, followUpDate, parentId) => ({
        endpoint, appointmentTypeId: typeId ? String(typeId) : '', reason, followUpDate, parentId,
        monthDate: new Date(new Date().getFullYear(), new Date().getMonth(), 1), days: [], selectedDay: null, loading: false, error: '',
        weekdayNames: ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'],
        stateClasses: { available:'border-emerald-200 bg-emerald-50 text-emerald-950', few_slots:'border-amber-300 bg-amber-50 text-amber-950', fully_booked:'border-slate-200 bg-slate-100 text-slate-500', unavailable:'border-red-200 bg-red-50 text-red-800' },
        stateLabels: { available:'Available', few_slots:'Few slots', fully_booked:'Fully booked', unavailable:'Unavailable' },
        get monthKey() { return `${this.monthDate.getFullYear()}-${String(this.monthDate.getMonth()+1).padStart(2,'0')}`; },
        get monthLabel() { return this.monthDate.toLocaleDateString(undefined, {month:'long', year:'numeric'}); },
        get firstWeekday() { return new Date(this.monthDate.getFullYear(), this.monthDate.getMonth(), 1).getDay(); },
        async init() {
            if (this.followUpDate) {
                const suggested = new Date(`${this.followUpDate}T12:00:00`);
                if (!Number.isNaN(suggested.getTime())) {
                    this.monthDate = new Date(suggested.getFullYear(), suggested.getMonth(), 1);
                }
            }
            await this.loadMonth();
            if (this.followUpDate) {
                this.selectedDay = this.days.find(day => day.date === this.followUpDate) ?? null;
            }
        },
        moveMonth(delta) { this.monthDate = new Date(this.monthDate.getFullYear(), this.monthDate.getMonth()+delta, 1); this.selectedDay = null; this.loadMonth(); },
        async loadMonth() {
            this.loading = true; this.error = '';
            try {
                const url = new URL(this.endpoint, window.location.origin); url.searchParams.set('month', this.monthKey);
                if (this.appointmentTypeId) url.searchParams.set('appointment_type_id', this.appointmentTypeId);
                const response = await fetch(url, {headers:{Accept:'application/json'}, credentials:'same-origin'});
                if (!response.ok) throw new Error('Availability could not be loaded. Please refresh and try again.');
                this.days = (await response.json()).days;
            } catch (error) { this.error = error.message; } finally { this.loading = false; }
        },
        selectDay(day) { this.selectedDay = day; },
        bookingUrl(slot) {
            const url = new URL(@js(route('appointments.create')), window.location.origin);
            url.searchParams.set('provider_id', @js($provider->id)); url.searchParams.set('appointment_slot_id', slot.id); url.searchParams.set('starts_at', slot.starts_at);
            if (this.appointmentTypeId) url.searchParams.set('appointment_type_id', this.appointmentTypeId);
            if (this.reason) url.searchParams.set('reason', this.reason);
            if (this.parentId) url.searchParams.set('parent_appointment_id', this.parentId);
            if (this.followUpDate) url.searchParams.set('follow_up_date', this.followUpDate);
            return url.toString();
        },
    }));
});
</script>
@endsection
