@extends('layouts.hims')

@section('title', 'Approval Request Detail')
@section('page-title', 'Approval request')
@section('page-kicker', 'My Approvals')

@section('content')
<div class="mx-auto max-w-3xl rounded-xl border border-slate-200 bg-white p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ ucfirst($careRequest->type) }} service request</h2>
            <p class="mt-1 text-sm text-slate-600">Reference: {{ $careRequest->approval_reference ?? 'Pending' }}</p>
        </div>
        <span class="rounded-full bg-slate-100 px-3 py-1 text-sm font-semibold">{{ $careRequest->status }}</span>
    </div>
    <dl class="mt-6 space-y-4 text-sm">
        <div><dt class="text-slate-500">Requested service</dt><dd class="mt-1 whitespace-pre-line font-medium text-slate-900">{{ $careRequest->description }}</dd></div>
        <div><dt class="text-slate-500">Urgency</dt><dd class="font-medium">{{ ucfirst($careRequest->urgency) }}</dd></div>
        <div><dt class="text-slate-500">Requested by</dt><dd class="font-medium">{{ $careRequest->requestedBy->name }}</dd></div>
        <div><dt class="text-slate-500">Request date</dt><dd class="font-medium">{{ $careRequest->created_at->format('M d, Y g:i A') }}</dd></div>
        @if ($careRequest->decided_at)
            <div><dt class="text-slate-500">Decision date</dt><dd class="font-medium">{{ $careRequest->decided_at->format('M d, Y g:i A') }}</dd></div>
            <div><dt class="text-slate-500">Approver</dt><dd class="font-medium">{{ $careRequest->decidedBy->name ?? '—' }}</dd></div>
            <div><dt class="text-slate-500">Remarks</dt><dd class="whitespace-pre-line font-medium">{{ $careRequest->remarks ?: 'No remarks' }}</dd></div>
        @endif
    </dl>
    @can('viewSlip', $careRequest)
        <a href="{{ route('care-requests.slip', $careRequest) }}" class="mt-6 inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">View / print approval slip</a>
    @endcan
    @can('viewResult', $careRequest)
        <div class="mt-6 border-t border-slate-100 pt-5">
            <h3 class="font-semibold text-slate-900">Result</h3>
            <p class="mt-2 whitespace-pre-line text-sm text-slate-700">{{ $careRequest->result_text }}</p>
            @if ($careRequest->clinicalDocument)
                <a href="{{ route('care-requests.result-file', $careRequest) }}" class="mt-2 inline-block text-sm font-semibold text-teal-700 hover:underline">Download result file</a>
            @endif
        </div>
    @endcan
</div>
@endsection
