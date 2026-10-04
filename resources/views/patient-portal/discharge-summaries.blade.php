@extends('layouts.hims')

@section('title', 'Discharge Summaries')
@section('page-title', 'Discharge Summaries')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Read your physician-approved discharge summaries and care instructions.')

@section('content')
<div class="space-y-4">
    @forelse ($summaries as $summary)
        <article class="card rounded-2xl border bg-white p-6 shadow-sm">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold text-slate-900">{{ $summary->final_diagnosis }}</h2>
                    <p class="mt-1 text-sm text-slate-500">
                        Approved {{ $summary->approved_at?->format('M d, Y g:i A') }}
                        @if ($summary->attendingProvider?->display_name)
                            · {{ $summary->attendingProvider->display_name }}
                        @endif
                    </p>
                </div>
                <span class="rounded-full bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-800">Physician approved</span>
            </div>

            <section class="mt-5">
                <h3 class="text-sm font-semibold text-slate-700">Clinical course</h3>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $summary->clinical_course }}</p>
            </section>

            <section class="mt-4 rounded-xl border border-teal-200 bg-teal-50 p-4">
                <h3 class="text-sm font-semibold text-teal-900">Discharge instructions</h3>
                <p class="mt-1 whitespace-pre-line text-sm text-slate-800">{{ $summary->discharge_instructions }}</p>
            </section>

            @if ($summary->follow_up_plan)
                <section class="mt-4">
                    <h3 class="text-sm font-semibold text-slate-700">Follow-up plan</h3>
                    <p class="mt-1 whitespace-pre-line text-sm text-slate-700">{{ $summary->follow_up_plan }}</p>
                </section>
            @endif
        </article>
    @empty
        <div class="card rounded-2xl border bg-white p-6 text-sm text-slate-500 shadow-sm">
            No physician-approved discharge summaries are available.
        </div>
    @endforelse

    @if ($summaries->hasPages())
        <div>{{ $summaries->links() }}</div>
    @endif
</div>
@endsection
