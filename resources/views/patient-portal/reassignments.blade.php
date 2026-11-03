@extends('layouts.hims')
@section('title', 'Appointment updates')
@section('content')
<div class="mx-auto max-w-4xl space-y-5">
    <header><h1 class="text-2xl font-bold text-slate-900">Appointment updates</h1><p class="text-sm text-slate-600">If your doctor becomes unavailable, review the proposed coverage here.</p></header>
    @forelse ($cases as $case)
        <a href="{{ route('patient.reassignments.show', $case) }}" class="block rounded-xl border border-amber-200 bg-white p-5 hover:border-amber-400">
            <div class="flex items-center justify-between gap-4">
                <div><p class="font-semibold text-slate-900">{{ $case->suggestedProvider ? 'Suggested doctor: Dr. '.$case->suggestedProvider->full_name : 'Your appointment needs a new plan' }}</p>
                    <p class="mt-1 text-sm text-slate-600">{{ $case->suggested_starts_at?->format('M j, Y g:i A') ?? 'Time to be confirmed' }} · {{ str_replace('_', ' ', $case->status) }}</p>
                </div><span class="text-sm font-semibold text-teal-700">Review options →</span>
            </div>
        </a>
    @empty
        <p class="rounded-xl border bg-white p-6 text-sm text-slate-600">There are no appointment coverage updates right now.</p>
    @endforelse
    {{ $cases->links() }}
</div>
@endsection
