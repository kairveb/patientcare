@extends('layouts.hims')

@section('title', 'AI Triage Accuracy Report')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">AI Triage Accuracy</h1>
            <p class="mt-1 text-sm text-slate-500">{{ $start }} to {{ $end }} · Precision treats the AI suggestion as the prediction and the clinician’s final level as reference.</p>
        </div>
        <div class="flex gap-2">
            <a href="{{ route('reports.ai-triage-accuracy.export', ['start' => $start, 'end' => $end]) }}" class="rounded-lg bg-teal-700 px-4 py-2 text-sm text-white hover:bg-teal-800">Export CSV</a>
            <a href="{{ route('reports.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm hover:bg-slate-50">Back</a>
        </div>
    </div>

    @include('reports._date-form')

    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="text-3xl font-bold">{{ $total }}</div>
            <div class="text-sm text-slate-500">Total triage assessments</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="text-3xl font-bold">{{ $evaluated }}</div>
            <div class="text-sm text-slate-500">With recognized AI and final levels</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="text-3xl font-bold text-teal-700">{{ $agreementRate === null ? 'N/A' : number_format($agreementRate, 2).'%' }}</div>
            <div class="text-sm text-slate-500">AI/final agreement ({{ $agreementCount }} of {{ $evaluated }})</div>
        </div>
        <div class="rounded-xl border border-slate-200 bg-white p-5">
            <div class="text-3xl font-bold text-amber-700">{{ $overrideRate === null ? 'N/A' : number_format($overrideRate, 2).'%' }}</div>
            <div class="text-sm text-slate-500">Override rate ({{ $overrideCount }} of {{ $evaluated }})</div>
        </div>
    </div>

    @if ($excluded > 0)
        <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ $excluded }} assessment(s) are excluded from accuracy metrics because AI and final levels are missing or unrecognized.</p>
    @endif

    <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4 font-semibold">Confusion matrix <span class="font-normal text-slate-500">(rows: final level; columns: AI suggestion)</span></div>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left">Final / AI</th>
                    @foreach ($levels as $level)
                        <th class="px-4 py-3 text-right">{{ $level }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($levels as $finalLevel)
                    <tr class="border-t border-slate-100">
                        <th class="px-4 py-3 text-left font-medium">{{ $finalLevel }}</th>
                        @foreach ($levels as $aiLevel)
                            <td class="px-4 py-3 text-right">{{ $matrix[$finalLevel][$aiLevel] }}</td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>

    <section class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <div class="border-b border-slate-200 px-5 py-4 font-semibold">Per-level precision and recall</div>
        <table class="min-w-full text-sm">
            <thead class="bg-slate-50">
                <tr>
                    <th class="px-4 py-3 text-left">Level</th>
                    <th class="px-4 py-3 text-right">AI predictions</th>
                    <th class="px-4 py-3 text-right">Final cases</th>
                    <th class="px-4 py-3 text-right">Correct</th>
                    <th class="px-4 py-3 text-right">Precision</th>
                    <th class="px-4 py-3 text-right">Recall</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($levelMetrics as $level => $metrics)
                    <tr class="border-t border-slate-100">
                        <th class="px-4 py-3 text-left font-medium">{{ $level }}</th>
                        <td class="px-4 py-3 text-right">{{ $metrics['aiPredictions'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $metrics['finalCases'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $metrics['correct'] }}</td>
                        <td class="px-4 py-3 text-right">{{ $metrics['precision'] === null ? 'N/A' : number_format($metrics['precision'], 2).'%' }}</td>
                        <td class="px-4 py-3 text-right">{{ $metrics['recall'] === null ? 'N/A' : number_format($metrics['recall'], 2).'%' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
</div>
@endsection
