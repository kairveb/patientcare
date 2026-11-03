@extends('layouts.hims')

@section('title', 'My Approvals')
@section('page-title', 'My Approvals')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Track outpatient lab, imaging, and procedure approval requests.')

@section('content')
<div class="rounded-xl border border-slate-200 bg-white">
    @forelse ($requests as $careRequest)
        <a href="{{ route('patients.portal.approval', $careRequest) }}" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 p-5 last:border-0 hover:bg-slate-50">
            <div>
                <p class="font-semibold text-slate-900">{{ ucfirst($careRequest->type) }} · {{ $careRequest->status }}</p>
                <p class="mt-1 text-sm text-slate-600">{{ $careRequest->description }}</p>
                <p class="mt-1 text-xs text-slate-500">Requested {{ $careRequest->created_at->format('M d, Y') }}</p>
            </div>
            <span class="text-sm font-medium text-teal-700">{{ $careRequest->approval_reference ?? 'Pending reference' }}</span>
        </a>
    @empty
        <p class="p-8 text-center text-sm text-slate-500">You do not have any approval requests yet.</p>
    @endforelse
</div>
<div class="mt-5">{{ $requests->links() }}</div>
@endsection
