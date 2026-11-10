@extends('layouts.hims')

@section('title', 'Outpatient Approval Queue')
@section('page-title', 'Outpatient approval queue')
@section('page-kicker', 'Care delivery')
@section('page-description', 'Review pending laboratory, imaging, and procedure requests.')

@section('content')
<div class="space-y-5">
    <form method="GET" action="{{ route('care-requests.index') }}" class="hims-filter-bar rounded-xl border border-slate-200 bg-white p-4">
        <label class="text-sm font-medium text-slate-700">Type
            <select name="type" class="rounded-lg border border-slate-300 px-3 py-2">
                <option value="">All types</option>
                @foreach (['lab' => 'Laboratory', 'imaging' => 'Imaging', 'procedure' => 'Procedure'] as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['type'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </label>
        <label class="text-sm font-medium text-slate-700">Urgency
            <select name="urgency" class="rounded-lg border border-slate-300 px-3 py-2">
                <option value="">All urgencies</option>
                <option value="routine" @selected(($filters['urgency'] ?? '') === 'routine')>Routine</option>
                <option value="urgent" @selected(($filters['urgency'] ?? '') === 'urgent')>Urgent</option>
            </select>
        </label>
        <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Filter</button>
    </form>

    <div class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        @forelse ($requests as $careRequest)
            <a href="{{ route('care-requests.show', $careRequest) }}" class="block border-b border-slate-100 p-5 last:border-0 hover:bg-slate-50">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <p class="font-semibold text-slate-900">{{ $careRequest->patient->full_name }} · {{ ucfirst($careRequest->type) }}</p>
                        <p class="mt-1 text-sm text-slate-600">{{ $careRequest->description }}</p>
                        <p class="mt-2 text-xs text-slate-500">Requested by {{ $careRequest->requestedBy->name }} · {{ $careRequest->created_at->format('M d, Y g:i A') }}</p>
                    </div>
                    <div class="flex gap-2 text-xs">
                        <span class="rounded-full bg-amber-100 px-2.5 py-1 font-semibold text-amber-800">{{ $careRequest->status }}</span>
                        <span class="rounded-full bg-slate-100 px-2.5 py-1 text-slate-700">{{ ucfirst($careRequest->urgency) }}</span>
                    </div>
                </div>
            </a>
        @empty
            <p class="p-8 text-center text-sm text-slate-500">No pending outpatient requests match these filters.</p>
        @endforelse
    </div>
    {{ $requests->links() }}
</div>
@endsection
