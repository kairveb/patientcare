@extends('layouts.hims')

@section('title', 'AI evaluation and monitoring')
@section('page-kicker', 'Clinical quality')
@section('page-title', 'AI evaluation and monitoring')
@section('page-description', 'Track how the triage support performs against clinician review. This is an evaluation dataset for future model improvement, not model training or fine-tuning.')

@section('content')
<div class="space-y-6">
    <div class="rounded-xl border border-sky-200 bg-sky-50 p-4 text-sm text-sky-950">
        These metrics support monitoring and future model improvement. They do not fine-tune a model and do not replace clinical judgment.
    </div>
    <form method="GET" class="hims-filter-bar rounded-xl border border-slate-200 bg-white p-4">
        <label class="text-xs text-slate-600">Start date<input type="date" name="start" value="{{ $start }}" class="mt-1 block rounded-lg border-slate-300 text-sm"></label>
        <label class="text-xs text-slate-600">End date<input type="date" name="end" value="{{ $end }}" class="mt-1 block rounded-lg border-slate-300 text-sm"></label>
        <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Apply filters</button>
        @foreach (['csv', 'pdf', 'txt', 'xls'] as $format)
            <a href="{{ route('reports.export', ['report' => 'ai-evaluation-dataset', 'format' => $format, 'start' => $start, 'end' => $end]) }}" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Export evaluation dataset {{ strtoupper($format) }}</a>
        @endforeach
    </form>
    @if (session('success'))<p role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">{{ session('success') }}</p>@endif

    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        @foreach ([
            ['Total triages', $metrics['triages']],
            ['Rules / AI share', $metrics['rules_share'] === null ? '—' : $metrics['rules_count'].' ('.$metrics['rules_share'].'%) / '.$metrics['ai_count'].' ('.$metrics['ai_share'].'%)'],
            ['Rule engine overrides', $metrics['override_count']],
            ['Clinician agreement', $metrics['agreement_rate'] === null ? 'Not reviewed' : $metrics['agreement_rate'].'%'],
            ['Under-triage', $metrics['under_triage_count']],
            ['Over-triage', $metrics['over_triage_count']],
        ] as [$label, $value])
            <div class="rounded-xl border border-slate-200 bg-white p-4"><div class="text-2xl font-bold text-slate-900">{{ $value }}</div><div class="mt-1 text-sm text-slate-600">{{ $label }}</div></div>
        @endforeach
    </div>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Triage outcomes by urgency</h2>
        <div class="mt-4 space-y-2">
            @foreach ($metrics['urgency_counts'] as $level => $count)
                <div class="grid grid-cols-[7rem_1fr_2rem] items-center gap-3 text-sm">
                    <span>{{ $level }}</span>
                    <div class="h-5 overflow-hidden rounded bg-slate-100"><div class="h-full bg-teal-600" style="width: {{ $metrics['triages'] ? $count / $metrics['triages'] * 100 : 0 }}%"></div></div>
                    <span class="text-right">{{ $count }}</span>
                </div>
            @endforeach
        </div>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Weekly triage trend</h2>
        <svg viewBox="0 0 600 170" role="img" aria-label="Weekly triage totals" class="mt-3 h-44 w-full">
            <line x1="30" y1="145" x2="590" y2="145" stroke="#cbd5e1" />
            @php($maxWeekly = max(1, (int) $metrics['weekly']->max()))
            @foreach ($metrics['weekly'] as $week => $count)
                @php($barX = 38 + $loop->index * 68)
                <rect x="{{ $barX }}" y="{{ 140 - ($count / $maxWeekly * 110) }}" width="34" height="{{ $count / $maxWeekly * 110 }}" rx="4" fill="#0f766e" />
                <text x="{{ $barX + 17 }}" y="160" text-anchor="middle" font-size="9" fill="#475569">{{ substr($week, -3) }}</text>
                <text x="{{ $barX + 17 }}" y="{{ 134 - ($count / $maxWeekly * 110) }}" text-anchor="middle" font-size="10" fill="#0f172a">{{ $count }}</text>
            @endforeach
        </svg>
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <h2 class="border-b border-slate-200 px-5 py-4 font-semibold">Recent triages for clinician evaluation</h2>
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Patient reference</th><th class="px-4 py-3">AI urgency</th><th class="px-4 py-3">Specialty</th><th class="px-4 py-3">Review</th></tr></thead>
                <tbody>
                    @forelse ($checks as $check)
                        <tr class="border-t border-slate-100">
                            <td class="px-4 py-3">{{ $check->patient?->reference_code ?? 'Reference unavailable' }}</td>
                            <td class="px-4 py-3">{{ $check->urgency_level }}</td>
                            <td class="px-4 py-3">{{ $check->recommended_department }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('reports.ai-evaluation.review', $check) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf
                                    <select name="clinician_final_urgency" required class="rounded border-slate-300 text-xs">
                                        @foreach (['Emergency', 'Urgent', 'Routine', 'Self-Care'] as $level)<option value="{{ $level }}" @selected($check->clinician_final_urgency === $level)>{{ $level }}</option>@endforeach
                                    </select>
                                    <select name="clinician_agreement" required class="rounded border-slate-300 text-xs">
                                        @foreach (['agree' => 'Agree', 'too_low' => 'AI too low', 'too_high' => 'AI too high'] as $key => $label)<option value="{{ $key }}" @selected($check->clinician_agreement === $key)>{{ $label }}</option>@endforeach
                                    </select>
                                    <button class="rounded bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white">Save review</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-8 text-center text-slate-500">No triage records in this date range.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
