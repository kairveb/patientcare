@extends('layouts.hims')

@section('title', 'ER Intake')
@section('page-kicker', 'Emergency')
@section('page-title', 'ER Intake')
@section('page-badge', 'ER intake')

@section('content')
<div class="mx-auto max-w-5xl space-y-6">
    @include('emergency._workflow-steps', ['currentStep' => 2])

    <div class="panel-card p-6">
        <div class="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
            <div>
                <h2 class="text-2xl font-semibold text-slate-900">ER intake</h2>
                <p class="mt-1 text-sm text-slate-600">Review the patient arrival details and record the essential information needed to move the case into the ER queue.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button type="button" class="inline-flex items-center justify-center rounded-xl bg-rose-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-rose-700" data-bs-toggle="modal" data-bs-target="#erIntakeModal">Open ER intake form</button>
                <a href="{{ route('emergency.index') }}" class="inline-flex items-center justify-center rounded-xl border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 transition hover:bg-slate-100">Back to ER queue</a>
            </div>
        </div>
    </div>

    @if (empty($prefill['triage_assessment_id']))
        @role('registration', 'nurse')
            <section class="panel-card border border-amber-200 p-6">
                <h2 class="text-lg font-semibold text-slate-900">Unidentified patient</h2>
                <p class="mt-1 text-sm text-slate-600">Create a temporary ER record when identity is not known. DOB and sex are optional; Registration Staff can complete the record later.</p>
                <form method="POST" action="{{ route('emergency.unknown-arrivals.store') }}" class="mt-4 space-y-4">
                    @csrf
                    @if ($errors->any())
                        <ul class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
                            @foreach ($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    @endif
                    <div class="grid gap-4 md:grid-cols-2">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Known first name, if available</label>
                            <input type="text" name="first_name" value="{{ old('first_name') }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Known last name, if available</label>
                            <input type="text" name="last_name" value="{{ old('last_name') }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Date of birth, if known</label>
                            <input type="date" name="date_of_birth" value="{{ old('date_of_birth') }}" max="{{ now()->toDateString() }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Sex, if known</label>
                            <select name="sex" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                <option value="">Unknown</option>
                                <option value="Male" @selected(old('sex') === 'Male')>Male</option>
                                <option value="Female" @selected(old('sex') === 'Female')>Female</option>
                                <option value="Other" @selected(old('sex') === 'Other')>Other</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Contact number, if available</label>
                            <input type="tel" name="phone" value="{{ old('phone') }}" data-phone-input class="w-full rounded-lg border border-slate-300 px-3 py-2" inputmode="numeric" pattern="^(09\d{9}|\+639\d{9})$" placeholder="09XXXXXXXXX or +639XXXXXXXXX">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Email, if available</label>
                            <input type="email" name="email" value="{{ old('email') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium text-slate-700">Arrival method</label>
                            <select name="arrival_method" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                                <option value="">Select</option>
                                @foreach (['Walk-in', 'Ambulance', 'Referral', 'Police', 'Other'] as $method)
                                    <option value="{{ $method }}" @selected(old('arrival_method') === $method)>{{ $method }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="md:col-span-2">
                            <label class="mb-1 block text-sm font-medium text-slate-700">Chief complaint *</label>
                            <textarea name="chief_complaint" required rows="3" maxlength="2000" class="w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('chief_complaint') }}</textarea>
                        </div>
                    </div>
                    <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">Register temporary ER identity</button>
                </form>
            </section>
        @endrole
    @endif

    @if (!empty($prefill['checkin_summary']))
        <div class="panel-card p-6">
            <div class="mb-4 flex items-center justify-between gap-3">
                <h3 class="text-lg font-semibold text-slate-900">Pre-arrival check-in summary</h3>
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-700">Checked in via token</span>
            </div>

            <dl class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Patient</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $prefill['checkin_summary']['patient_name'] }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Age</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $prefill['checkin_summary']['age'] }} years</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Contact</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $prefill['checkin_summary']['phone'] }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Address</dt>
                    <dd class="mt-1 font-semibold text-slate-900">{{ $prefill['checkin_summary']['address'] }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Medical history</dt>
                    <dd class="mt-1 text-sm text-slate-700">{{ $prefill['checkin_summary']['medical_history'] ?: 'Not provided' }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Allergies</dt>
                    <dd class="mt-1 text-sm text-slate-700">{{ $prefill['checkin_summary']['allergies'] ?: 'Not provided' }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Current medications</dt>
                    <dd class="mt-1 text-sm text-slate-700">{{ $prefill['checkin_summary']['current_medications'] ?: 'Not provided' }}</dd>
                </div>
                <div class="rounded-xl border border-slate-200 bg-slate-50 p-3 md:col-span-2">
                    <dt class="text-xs uppercase tracking-[0.2em] text-slate-500">Emergency contact</dt>
                    <dd class="mt-1 text-sm text-slate-700">{{ $prefill['checkin_summary']['emergency_contact'] ?: 'Not provided' }}</dd>
                </div>
            </dl>
        </div>
    @endif

</div>

<div class="modal fade" id="erIntakeModal" tabindex="-1" aria-labelledby="erIntakeModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content border-0 shadow-2xl">
            <div class="modal-header border-b border-slate-200 px-5 py-4">
                <div>
                    <h5 class="modal-title text-lg font-semibold text-slate-900" id="erIntakeModalLabel">ER intake</h5>
                    <p class="mt-1 text-sm text-slate-500">Step 2 of 3 · Visit details</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body px-5 py-5">
                @include('emergency.partials.intake-form')
            </div>
        </div>
    </div>
</div>

@push('scripts')
<script>
    (function () {
        const modalEl = document.getElementById('erIntakeModal');
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }

        const modal = bootstrap.Modal.getOrCreateInstance(modalEl);

        const showAutoOpenModal = () => {
            if (modalEl.dataset.autoOpen === 'true') {
                requestAnimationFrame(() => {
                    modal.show();
                });
            }
        };

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', showAutoOpenModal, { once: true });
        } else {
            showAutoOpenModal();
        }
    })();
</script>
@endpush
@endsection
