@extends('layouts.hims')

@section('title', 'Book Appointment')

@section('content')
<div class="max-w-3xl space-y-6">
    @if ($selectedSpecialtyName)
        <div class="rounded-lg border border-teal-200 bg-teal-50 p-3 text-sm text-teal-900">
            Triage suggestion: <strong>{{ $selectedSpecialtyName }}</strong>. Review the pre-selected department and provider, then choose an available appointment slot.
        </div>
    @endif
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Book Appointment</h1>
            <p class="text-sm text-slate-500 mt-1">Select a provider and available time slot</p>
        </div>
        <a href="{{ route('appointments.index') }}" class="inline-flex items-center gap-1.5 px-4 py-2 text-sm border border-slate-300 rounded-lg hover:bg-slate-50">
            <i class="bi bi-arrow-left"></i> Back
        </a>
    </div>

    <form method="POST" action="{{ route('appointments.store') }}" class="bg-white rounded-xl border border-slate-200 p-6 space-y-4" data-appointment-slot-picker data-slots-url="{{ route('appointments.slots') }}">
        @csrf

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Patient *</label>
            @if (auth()->user()->hasRole('patient'))
                <input type="hidden" name="patient_id" value="{{ $patients->first()->id }}">
                <p class="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-700">{{ $patients->first()->full_name }} — {{ $patients->first()->mrn }}</p>
            @else
                <select name="patient_id" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    <option value="">Select patient</option>
                    @foreach ($patients as $patient)
                        <option value="{{ $patient->id }}" {{ request('patient_id') == $patient->id ? 'selected' : '' }}>{{ $patient->full_name }} — {{ $patient->mrn }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Department *</label>
                <select name="department_id" id="department" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    <option value="">Select department</option>
                    @foreach ($departments as $dept)
                        <option value="{{ $dept->id }}" @selected(old('department_id', $selectedDepartmentId) == $dept->id)>{{ $dept->name }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-slate-700 mb-1">Appointment Type *</label>
                <select name="appointment_type_id" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                    @foreach ($appointmentTypes as $type)
                        <option value="{{ $type->id }}" {{ old('appointment_type_id', $selectedAppointmentTypeId) == $type->id ? 'selected' : '' }}>{{ $type->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Provider *</label>
            <select name="provider_id" data-slot-provider required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                <option value="">Select provider</option>
                @foreach ($providers as $provider)
                    <option value="{{ $provider->id }}" @selected(old('provider_id', $selectedProviderId) == $provider->id)>{{ $provider->full_name }} — {{ $provider->department->name ?? 'General' }}</option>
                @endforeach
            </select>
        </div>

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Appointment Date *</label>
            <input type="date" name="appointment_date" data-slot-date required value="{{ $selectedStartsAt?->format('Y-m-d') }}" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
        </div>

        <div data-slot-results>
            {{-- Available slots loaded via AJAX --}}
        </div>
        <input type="hidden" name="starts_at" value="{{ $selectedStartsAt?->toDateTimeString() }}">
        @if ($selectedSlotId)
            <input type="hidden" name="appointment_slot_id" value="{{ $selectedSlotId }}" data-prefilled-slot>
            <p class="rounded-lg bg-teal-50 px-3 py-2 text-sm text-teal-800">Selected slot: {{ $selectedStartsAt->format('M j, Y g:i A') }}. Choose a different date to load other slots.</p>
        @endif
        @if ($parentAppointmentId)
            <input type="hidden" name="parent_appointment_id" value="{{ $parentAppointmentId }}">
        @endif

        @if (! auth()->user()->isPatient())
            <label class="block text-sm font-medium text-slate-700">Booking source
                <select name="booking_source" class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option value="online" @selected(old('booking_source', $selectedBookingSource) === 'online')>Online request</option>
                    <option value="walk_in" @selected(old('booking_source') === 'walk_in')>Walk-in</option>
                    <option value="phone" @selected(old('booking_source') === 'phone')>Phone</option>
                </select>
            </label>
        @endif

        <div>
            <label class="block text-sm font-medium text-slate-700 mb-1">Reason / Notes</label>
            <textarea name="reason" rows="2" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">{{ old('reason', $selectedReason) }}</textarea>
        </div>

        <div class="flex items-center gap-3 pt-2">
            <button type="submit" class="px-6 py-2.5 text-sm font-medium bg-teal-600 text-white rounded-lg hover:bg-teal-700">Book Appointment</button>
            <a href="{{ route('appointments.index') }}" class="px-6 py-2.5 text-sm border border-slate-300 rounded-lg hover:bg-slate-50">Cancel</a>
        </div>
    </form>
</div>
@endsection
