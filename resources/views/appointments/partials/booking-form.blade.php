<form id="appointment-booking-form" method="POST" action="{{ route('appointments.store') }}" class="space-y-4" onsubmit="return false;" data-appointment-slot-picker data-slots-url="{{ route('appointments.slots') }}">
    @csrf

    <div id="booking-modal-alert" class="hidden rounded-xl border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700"></div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Patient <span class="hims-required" aria-hidden="true">*</span></label>
        <select name="patient_id" required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
            <option value="">Select patient</option>
            @foreach ($patients as $patient)
                <option value="{{ $patient->id }}" {{ request('patient_id') == $patient->id ? 'selected' : '' }}>{{ $patient->full_name }} — {{ $patient->mrn }}</option>
            @endforeach
        </select>
    </div>

    <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Department <span class="hims-required" aria-hidden="true">*</span></label>
            <select name="department_id" id="department" data-provider-department required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                <option value="">Select department</option>
                @foreach ($departments as $dept)
                    <option value="{{ $dept->id }}">{{ $dept->name }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Appointment Type <span class="hims-required" aria-hidden="true">*</span></label>
            <select name="appointment_type_id" data-provider-appointment-type required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
                @foreach ($appointmentTypes as $type)
                    <option value="{{ $type->id }}" data-telehealth="{{ $type->telehealth ? '1' : '0' }}">{{ $type->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Provider <span class="hims-required" aria-hidden="true">*</span></label>
        <select name="provider_id" data-slot-provider data-provider-select required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
            <option value="">Select provider</option>
            @foreach ($providers as $provider)
                <option value="{{ $provider->id }}" data-department-id="{{ $provider->department_id }}" data-accepts-virtual="{{ $provider->accepts_virtual ? '1' : '0' }}">{{ $provider->full_name }} — {{ $provider->department->name ?? 'General' }}</option>
            @endforeach
        </select>
        <p data-no-providers-message class="mt-2 hidden text-sm text-amber-700" role="status">No providers available for this department.</p>
    </div>

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Appointment Date <span class="hims-required" aria-hidden="true">*</span></label>
        <input type="date" name="appointment_date" data-slot-date required class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100">
    </div>

    <div data-slot-results>
        {{-- Available slots loaded via AJAX --}}
    </div>
    <input type="hidden" name="starts_at" value="">

    <div>
        <label class="mb-1 block text-sm font-medium text-slate-700">Reason / Notes</label>
        <textarea name="reason" rows="2" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-teal-500 focus:ring-2 focus:ring-teal-100">{{ old('reason') }}</textarea>
    </div>

    <div class="flex items-center justify-end gap-3 pt-2">
        <button type="button" class="rounded-xl border border-slate-300 px-6 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="rounded-xl bg-teal-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-teal-700">Book Appointment</button>
    </div>
</form>
