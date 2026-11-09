@extends('layouts.hims')

@section('title', 'Admission Detail')

@section('content')
<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">Admission {{ $admission->admission_number }}</h1>
            <p class="text-sm text-slate-500 mt-1">Patient: <span class="font-medium">{{ $admission->patient->full_name ?? '—' }}</span> · {{ $admission->patient->mrn ?? '' }}</p>
        </div>
        <div class="flex gap-2">
            @role('registration', 'super-admin')
            @can('assign-beds')
                @if ($admission->status === 'REQUESTED')
                    <form method="POST" action="{{ route('admissions.approve', $admission) }}">@csrf
                        <button class="px-4 py-2 text-sm bg-green-600 text-white rounded-lg hover:bg-green-700">Approve</button>
                    </form>
                @endif
            @endcan
            @endrole
            @role('nurse', 'super-admin')
            @can('execute-discharges')
                @if (in_array($admission->status, ['ADMITTED', 'TRANSFERRED']) && $canDischarge)
                    <button onclick="document.getElementById('discharge-form').classList.toggle('hidden')" class="px-4 py-2 text-sm bg-blue-600 text-white rounded-lg hover:bg-blue-700">Discharge</button>
                @endif
            @endcan
            @endrole
        </div>
    </div>

    @role('nurse', 'super-admin')
    @can('execute-discharges')
        {{-- Discharge form --}}
        @if ($canDischarge)
        <div id="discharge-form" class="hidden bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-3">Discharge Patient</h3>
            <form method="POST" action="{{ route('admissions.discharge', $admission) }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Reason</label><input type="text" name="reason" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Disposition</label><input type="text" name="disposition" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                </div>
                <div><label class="block text-sm font-medium text-slate-700 mb-1">Notes</label><textarea name="notes" rows="2" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></textarea></div>
                <button type="submit" class="px-6 py-2.5 text-sm bg-blue-600 text-white rounded-lg" onclick="return confirm('Discharge this patient and release the bed?')">Confirm Discharge</button>
            </form>
        </div>
        @else
        @if (in_array($admission->status, ['ADMITTED', 'TRANSFERRED']))
            <p class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">{{ $dischargeBlockReason ?? 'Discharge is unavailable until the clinical and billing requirements are met.' }}</p>
        @endif
        @endif
    @endcan
    @endrole

    @role('registration', 'super-admin')
    @can('assign-beds')
        @foreach ($admission->bedReservations->where('status', \App\Models\BedReservation::STATUS_ACTIVE) as $reservation)
            @if ($reservation->expires_at?->isFuture())
                <div class="flex items-center justify-between rounded-xl border border-amber-200 bg-amber-50 p-4">
                    <p class="text-sm text-amber-900">Bed {{ $reservation->bed?->label ?? '—' }} is reserved until {{ $reservation->expires_at->format('M d, Y g:i A') }}.</p>
                    <form method="POST" action="{{ route('admissions.reservations.cancel', [$admission, $reservation]) }}">
                        @csrf
                        <button type="submit" class="rounded-lg border border-amber-300 bg-white px-3 py-2 text-sm font-medium text-amber-900">Cancel reservation</button>
                    </form>
                </div>
            @endif
        @endforeach
    @endcan
    @endrole

    @role('registration', 'super-admin')
    @can('assign-beds')
        {{-- Bed assignment / reservation --}}
        @if ($admission->status === 'APPROVED')
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-3">Assign Bed</h3>
            <form method="GET" action="{{ route('admissions.show', $admission) }}" class="mb-4 flex flex-wrap items-end gap-3">
                <div>
                    <label for="assignment-ward" class="mb-1 block text-sm font-medium text-slate-700">Filter beds by ward</label>
                    <select id="assignment-ward" name="ward_id" class="min-w-64 rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        <option value="">All wards</option>
                        @foreach ($wards as $ward)
                            <option value="{{ $ward->id }}" {{ (string) $selectedWardId === (string) $ward->id ? 'selected' : '' }}>{{ $ward->name }}</option>
                        @endforeach
                    </select>
                </div>
                <button type="submit" class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-700">Apply filter</button>
            </form>
            <form method="POST" action="{{ route('admissions.admit', $admission) }}" class="space-y-3">
                @csrf
                <input type="hidden" name="reservation_id" value="">
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1">Select Bed <span class="hims-required" aria-hidden="true">*</span></label>
                    <select id="admission-bed-id" name="bed_id" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                        <option value="">Choose bed</option>
                        @foreach ($assignableBeds as $bed)
                            <option value="{{ $bed->id }}" data-reservation-id="{{ $bed->activeReservation?->id ?? '' }}">{{ $bed->label }} ({{ $bed->room?->ward?->name ?? '—' }})</option>
                        @endforeach
                    </select>
                    @error('bed_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                </div>
                <button type="submit" class="px-6 py-2.5 text-sm bg-teal-600 text-white rounded-lg">Admit &amp; Assign Bed</button>
            </form>
        </div>
        @endif
    @endcan
    @endrole

    @role('nurse', 'super-admin')
    @can('transfer-patients')
        {{-- Transfer form --}}
        @if (in_array($admission->status, ['ADMITTED', 'TRANSFERRED']))
        <div class="bg-white rounded-xl border border-slate-200 p-6">
            <h3 class="font-semibold text-slate-800 mb-3">Transfer Patient</h3>
            <form method="POST" action="{{ route('admissions.transfer', $admission) }}" class="space-y-3">
                @csrf
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1">Destination Bed <span class="hims-required" aria-hidden="true">*</span></label>
                        <select name="to_bed_id" required class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg">
                            <option value="">Choose bed</option>
                            @foreach ($availableBeds as $bed)
                                <option value="{{ $bed->id }}">{{ $bed->label }} ({{ $bed->room?->ward?->name ?? '—' }})</option>
                            @endforeach
                        </select>
                        @error('to_bed_id')<p class="text-xs text-red-600 mt-1">{{ $message }}</p>@enderror
                    </div>
                    <div><label class="block text-sm font-medium text-slate-700 mb-1">Reason</label><input type="text" name="reason" class="w-full px-3 py-2 text-sm border border-slate-300 rounded-lg"></div>
                </div>
                <button type="submit" class="px-6 py-2.5 text-sm bg-amber-600 text-white rounded-lg">Transfer</button>
            </form>
        </div>
        @endif
    @endcan
    @endrole

    {{-- Admission details --}}
    <div class="bg-white rounded-xl border border-slate-200 p-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4 text-sm">
            <div><span class="text-slate-500">Status:</span> <span class="px-2 py-1 text-xs rounded-full bg-slate-100 ml-1">{{ $admission->status }}</span></div>
            <div><span class="text-slate-500">Attending:</span> <span class="font-medium">{{ $admission->attendingProvider->full_name ?? '—' }}</span></div>
            <div><span class="text-slate-500">Reason:</span> {{ $admission->reason ?? '—' }}</div>
        </div>

        @role('registration', 'super-admin')
        @can('assign-beds')
            <section class="mt-5 rounded-xl border border-slate-200 p-5">
                <h3 class="font-semibold text-slate-800">Guarantor and LOA reference</h3>
                <form method="POST" action="{{ route('admissions.guarantee-details.update', $admission) }}" class="mt-3 grid grid-cols-1 gap-4 md:grid-cols-2">
                    @csrf
                    @method('PATCH')
                    <div>
                        <label for="guarantor_name" class="mb-1 block text-sm font-medium text-slate-700">Guarantor name</label>
                        <input id="guarantor_name" name="guarantor_name" value="{{ old('guarantor_name', $admission->guarantor_name) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('guarantor_name')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="guarantor_relationship" class="mb-1 block text-sm font-medium text-slate-700">Relationship</label>
                        <input id="guarantor_relationship" name="guarantor_relationship" value="{{ old('guarantor_relationship', $admission->guarantor_relationship) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('guarantor_relationship')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="guarantor_contact" class="mb-1 block text-sm font-medium text-slate-700">Guarantor contact</label>
                        <input id="guarantor_contact" name="guarantor_contact" value="{{ old('guarantor_contact', $admission->guarantor_contact) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('guarantor_contact')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="loa_reference" class="mb-1 block text-sm font-medium text-slate-700">LOA reference</label>
                        <input id="loa_reference" name="loa_reference" value="{{ old('loa_reference', $admission->loa_reference) }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                        @error('loa_reference')<p role="alert" class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                    </div>
                    <div class="md:col-span-2">
                        <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-medium text-white">Save guarantor details</button>
                    </div>
                </form>
            </section>
        @endcan
        @endrole

        @role('nurse', 'registration')
            <section class="mt-5 rounded-xl border border-slate-200 p-5">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-800">Billing clearance request</h3>
                        <p class="mt-1 text-sm text-slate-600">Request only; Billing manages clearance and payment status separately.</p>
                    </div>
                    @if (in_array($admission->status, ['ADMITTED', 'TRANSFERRED']) && $admission->dischargeSummary?->status === \App\Models\DischargeSummary::STATUS_APPROVED)
                        <form method="POST" action="{{ route('admissions.billing-clearance.request', $admission) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white">Request billing clearance</button>
                        </form>
                    @else
                        <p class="text-sm text-slate-500">Available after an attending physician discharge summary is approved.</p>
                    @endif
                </div>
                @if ($admission->billingClearanceRequests->isNotEmpty())
                    <ul class="mt-4 space-y-2 text-sm">
                        @foreach ($admission->billingClearanceRequests as $clearanceRequest)
                            <li class="flex flex-wrap justify-between gap-2 border-t border-slate-100 pt-2">
                                <span>Status: {{ $clearanceRequest->status }}</span>
                                <span>Requested {{ $clearanceRequest->requested_at?->format('M d, Y g:i A') ?? '—' }} by {{ $clearanceRequest->requestedBy?->name ?? 'staff' }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        @endrole

        @role('super-admin')
        @can('view-billing')
            <section class="rounded-xl border border-slate-200 bg-white p-6">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <h3 class="font-semibold text-slate-800">Itemized Billing</h3>
                        @if ($billingError)<p role="alert" class="mt-2 text-sm text-amber-800">{{ $billingError }}</p>@endif
                        @if ($billingSummary)
                            <p class="mt-1 text-sm text-slate-600">Statement {{ $billingSummary['statement_number'] ?? 'not generated' }} · {{ $billingSummary['status'] }}{{ ! $billingSummary['is_current'] && $billingSummary['statement_number'] ? ' · out of date' : '' }}</p>
                            <p class="mt-2 text-lg font-bold">{{ $billingSummary['currency'] }} {{ number_format((float) $billingSummary['total'], 2) }}</p>
                            <div class="mt-3 divide-y divide-slate-100">
                                @foreach ($billingSummary['items'] as $item)
                                    <div class="flex justify-between py-2 text-sm"><span>{{ $item['description'] }} × {{ $item['quantity'] }}</span><span>{{ $item['currency'] }} {{ number_format((float) $item['line_total'], 2) }}</span></div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                    @role('super-admin')
                        <form method="POST" action="{{ route('billing.admissions.generate', $admission) }}">
                            @csrf
                            <button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Generate / refresh statement</button>
                        </form>
                    @endrole
                </div>
            </section>
        @endcan
        @endrole

        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h3 class="font-semibold text-slate-800">Physician Discharge Summary</h3>
            @if ($admission->dischargeSummary?->status === \App\Models\DischargeSummary::STATUS_APPROVED)
                <p class="mt-2 text-sm text-emerald-700">Approved by {{ $admission->dischargeSummary->approver?->name ?? 'attending physician' }} on {{ $admission->dischargeSummary->approved_at?->format('M d, Y g:i A') }}.</p>
            @else
                <p class="mt-2 text-sm text-slate-600">Awaiting the attending physician's discharge summary and approval.</p>
            @endif
        </section>
        @can('view-payment-status')
            <section class="rounded-xl border border-slate-200 bg-white p-6">
                <h3 class="font-semibold text-slate-800">Payment status</h3>
                <p class="mt-2 text-sm text-slate-600">{{ $paymentStatus ?? 'No payment status available' }}</p>
            </section>
        @endcan
    </div>

    {{-- Bed assignment history --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200"><h3 class="font-semibold text-slate-800">Bed Assignment History</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-slate-500 bg-slate-50 border-b border-slate-200"><th class="py-3 px-4">Bed</th><th class="py-3 px-4">Assigned</th><th class="py-3 px-4">Released</th><th class="py-3 px-4">Status</th></tr></thead>
                <tbody>
                    @forelse ($admission->bedAssignments as $ba)
                        <tr class="border-b border-slate-100">
                            <td class="py-3 px-4 font-medium">{{ $ba->bed->label ?? '—' }}</td>
                            <td class="py-3 px-4">{{ $ba->assigned_at->format('M d, Y g:i A') }}</td>
                            <td class="py-3 px-4">{{ $ba->released_at?->format('M d, Y g:i A') ?? '—' }}</td>
                            <td class="py-3 px-4"><span class="px-2 py-1 text-xs rounded-full bg-slate-100">{{ $ba->status }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-slate-400">No bed assignment yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Transfer history --}}
    <div class="bg-white rounded-xl border border-slate-200 overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-200"><h3 class="font-semibold text-slate-800">Transfer History</h3></div>
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead><tr class="text-left text-xs uppercase text-slate-500 bg-slate-50 border-b border-slate-200"><th class="py-3 px-4">From</th><th class="py-3 px-4">To</th><th class="py-3 px-4">Time</th><th class="py-3 px-4">Reason</th></tr></thead>
                <tbody>
                    @forelse ($admission->transfers as $tr)
                        <tr class="border-b border-slate-100">
                            <td class="py-3 px-4">{{ $tr->fromBed->label ?? '—' }}</td>
                            <td class="py-3 px-4">{{ $tr->toBed->label ?? '—' }}</td>
                            <td class="py-3 px-4">{{ $tr->transferred_at->format('M d, Y g:i A') }}</td>
                            <td class="py-3 px-4">{{ $tr->reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-8 text-center text-slate-400">No transfers.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="px-5 py-4 border-t border-slate-200">
            @if ($admission->discharge)
                <p class="text-sm text-slate-600">Discharged: <span class="font-medium">{{ $admission->discharge->discharged_at->format('M d, Y g:i A') }}</span> — {{ $admission->discharge->reason ?? '—' }} ({{ $admission->discharge->disposition ?? '—' }})</p>
            @endif
        </div>
    </div>
</div>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        const bedSelector = document.getElementById('admission-bed-id');
        const reservationInput = bedSelector?.form?.querySelector('[name="reservation_id"]');
        bedSelector?.addEventListener('change', function () {
            reservationInput.value = bedSelector.selectedOptions[0]?.dataset.reservationId || '';
        });
    });
</script>
@endsection
