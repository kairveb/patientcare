@extends('layouts.hims')

@section('title', 'Outpatient Request')
@section('page-title', 'Outpatient approval request')
@section('page-kicker', 'Care delivery')

@section('content')
<div class="mx-auto max-w-4xl space-y-5">
    @if (session('success'))
        <div role="status" class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div role="alert" class="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{{ $errors->first() }}</div>
    @endif
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-900">{{ ucfirst($careRequest->type) }} request</h2>
                <p class="mt-1 text-sm text-slate-600">{{ $careRequest->patient->full_name }} · {{ $careRequest->patient->reference_code }}</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $careRequest->status }}</span>
        </div>
        <dl class="mt-5 grid gap-4 text-sm md:grid-cols-2">
            <div><dt class="text-slate-500">Urgency</dt><dd class="font-medium">{{ ucfirst($careRequest->urgency) }}</dd></div>
            <div><dt class="text-slate-500">Request date</dt><dd class="font-medium">{{ $careRequest->created_at->format('M d, Y g:i A') }}</dd></div>
            <div><dt class="text-slate-500">Requested by</dt><dd class="font-medium">{{ $careRequest->requestedBy->name }}</dd></div>
            <div><dt class="text-slate-500">Approval reference</dt><dd class="font-medium">{{ $careRequest->approval_reference ?? 'Not issued' }}</dd></div>
            @if ($careRequest->external_partner_code)
                <div><dt class="text-slate-500">{{ strtoupper($careRequest->external_partner_code) }} status</dt><dd class="font-medium">{{ $careRequest->partner_status ?? 'REQUESTED' }} · {{ $careRequest->partner_status_updated_at?->format('M d, Y g:i A') ?? '—' }}</dd></div>
                @if ($careRequest->accession_reference)
                    <div><dt class="text-slate-500">Accession / order reference</dt><dd class="font-medium">{{ $careRequest->accession_reference }}</dd></div>
                @endif
            @endif
            @if ($careRequest->coverage_code)
                <div><dt class="text-slate-500">LOA status</dt><dd class="font-medium">{{ $careRequest->loa_status ?? 'PENDING' }}</dd></div>
                <div><dt class="text-slate-500">LOA approved amount</dt><dd class="font-medium">{{ $careRequest->loa_approved_amount ?? '—' }}</dd></div>
                <div><dt class="text-slate-500">Payer reference</dt><dd class="font-medium">{{ $careRequest->loa_payer_reference ?? '—' }}</dd></div>
            @endif
            <div class="md:col-span-2"><dt class="text-slate-500">Requested service / clinical reason</dt><dd class="mt-1 whitespace-pre-line font-medium">{{ $careRequest->description }}</dd></div>
            @if ($careRequest->decided_at)
                <div><dt class="text-slate-500">Decision date</dt><dd class="font-medium">{{ $careRequest->decided_at->format('M d, Y g:i A') }}</dd></div>
                <div><dt class="text-slate-500">Approver</dt><dd class="font-medium">{{ $careRequest->decidedBy->name ?? '—' }}</dd></div>
            @endif
            @if ($careRequest->remarks)
                <div class="md:col-span-2"><dt class="text-slate-500">Remarks</dt><dd class="mt-1 whitespace-pre-line">{{ $careRequest->remarks }}</dd></div>
            @endif
        </dl>
        @can('viewSlip', $careRequest)
            <a href="{{ route('care-requests.slip', $careRequest) }}" class="mt-5 inline-flex rounded-lg border border-teal-700 px-4 py-2 text-sm font-semibold text-teal-800">View / print approval slip</a>
        @endcan
    </section>

    @can('decide', $careRequest)
        @if ($careRequest->status === \App\Models\CareRequest::STATUS_REQUESTED)
            <section class="rounded-xl border border-slate-200 bg-white p-6">
                <h3 class="font-semibold text-slate-900">Record a decision</h3>
                <form method="POST" action="{{ route('care-requests.decide', $careRequest) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm font-medium text-slate-700">Decision
                        <select name="decision" required class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">
                            <option value="APPROVED">Approve</option>
                            <option value="REJECTED">Reject</option>
                        </select>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Remarks <span class="font-normal text-slate-500">(required for rejection)</span>
                        <textarea name="remarks" maxlength="2000" rows="3" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2"></textarea>
                    </label>
                    <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Save decision</button>
                </form>
            </section>
        @endif
    @endcan

    @can('cancel', $careRequest)
        @if ($careRequest->status === \App\Models\CareRequest::STATUS_REQUESTED)
            <form method="POST" action="{{ route('care-requests.cancel', $careRequest) }}" class="rounded-xl border border-slate-200 bg-white p-5">
                @csrf
                <label class="block text-sm text-slate-700">Cancellation note (optional)
                    <input name="remarks" maxlength="2000" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">
                </label>
                <button class="mt-3 rounded-lg border border-rose-300 px-4 py-2 text-sm font-semibold text-rose-700">Cancel request</button>
            </form>
        @endif
    @endcan

    @can('attachResult', $careRequest)
        @if ($careRequest->status === \App\Models\CareRequest::STATUS_APPROVED && in_array($careRequest->type, ['lab', 'imaging'], true))
            <section class="rounded-xl border border-slate-200 bg-white p-6">
                <h3 class="font-semibold text-slate-900">Attach result</h3>
                <form method="POST" enctype="multipart/form-data" action="{{ route('care-requests.result.store', $careRequest) }}" class="mt-4 space-y-3">
                    @csrf
                    <label class="block text-sm font-medium text-slate-700">Result text
                        <textarea name="result_text" required maxlength="20000" rows="4" class="mt-1 block w-full rounded-lg border border-slate-300 px-3 py-2">{{ old('result_text', $careRequest->result_text) }}</textarea>
                    </label>
                    <label class="block text-sm font-medium text-slate-700">Optional file (PDF, JPG, PNG; max 10 MB)
                        <input name="result_file" type="file" accept=".pdf,.jpg,.jpeg,.png" class="mt-1 block w-full text-sm">
                    </label>
                    <button class="rounded-lg bg-indigo-700 px-4 py-2 text-sm font-semibold text-white">Save result</button>
                </form>
            </section>
        @endif
    @endcan

    @can('viewResult', $careRequest)
        <section class="rounded-xl border border-slate-200 bg-white p-6">
            <h3 class="font-semibold text-slate-900">Result</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $careRequest->result_text }}</p>
            @if ($careRequest->result_reference)
                <p class="mt-2 text-sm text-slate-600">Partner result reference: {{ $careRequest->result_reference }}</p>
            @endif
            @if ($careRequest->clinicalDocument)
                <a href="{{ route('care-requests.result-file', $careRequest) }}" class="mt-3 inline-block text-sm font-semibold text-teal-700 hover:underline">Download attached result file</a>
            @endif
        </section>
    @endcan

    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h3 class="font-semibold text-slate-900">Status history</h3>
        <ol class="mt-3 space-y-3">
            @foreach ($careRequest->statusHistories as $history)
                <li class="border-l-2 border-teal-200 pl-3 text-sm">
                    <p class="font-medium">{{ $history->from_status ?? 'New' }} → {{ $history->to_status }}</p>
                    <p class="text-slate-500">{{ $history->user->name ?? 'System' }} · {{ $history->created_at->format('M d, Y g:i A') }}</p>
                </li>
            @endforeach
        </ol>
    </section>
</div>
@endsection
