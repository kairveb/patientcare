@extends('layouts.hims')

@section('title', 'Reports')
@section('page-kicker', 'Insights')
@section('page-title', 'Operational Reports')
@section('page-badge', 'Analytics')

@section('content')
<div class="space-y-6">
    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="text-lg font-semibold text-slate-900">HADS aggregate export</h2>
        <p class="mt-1 text-sm text-slate-600">Requests contain aggregate operational counts or totals only; patient identifiers and clinical notes are excluded.</p>
        <form method="POST" action="{{ route('reports.hads-exports.store') }}" class="mt-4 flex flex-wrap items-end gap-3">
            @csrf
            <label class="text-xs text-slate-600">Dataset
                <select name="dataset" required class="mt-1 block rounded-lg border-slate-300 text-sm">
                    <option value="appointments">Appointments</option>
                    <option value="encounters">Encounters</option>
                    <option value="triage_levels">Triage levels</option>
                    <option value="er_visits">ER visits</option>
                    <option value="bed_occupancy">Bed occupancy</option>
                    <option value="billing_totals">Billing totals</option>
                </select>
            </label>
            <label class="text-xs text-slate-600">From
                <input type="date" name="from" required value="{{ old('from', today()->subDays(30)->toDateString()) }}" class="mt-1 block rounded-lg border-slate-300 text-sm">
            </label>
            <label class="text-xs text-slate-600">To
                <input type="date" name="to" required value="{{ old('to', today()->toDateString()) }}" class="mt-1 block rounded-lg border-slate-300 text-sm">
            </label>
            <label class="text-xs text-slate-600">Format
                <select name="format" required class="mt-1 block rounded-lg border-slate-300 text-sm">
                    <option value="csv">CSV</option>
                    <option value="pdf">PDF</option>
                    <option value="txt">TXT</option>
                    <option value="xls">XLS</option>
                </select>
            </label>
            <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Queue export</button>
        </form>
        @if ($hadsExports->isNotEmpty())
            <div class="mt-5 overflow-x-auto">
                <table class="min-w-full text-left text-sm">
                    <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Dataset</th><th class="py-2 pr-4">Period</th><th class="py-2 pr-4">Format</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Rows</th><th class="py-2">Expires</th></tr></thead>
                    <tbody>
                    @foreach ($hadsExports as $export)
                        <tr class="border-b last:border-0">
                            <td class="py-2 pr-4">{{ str($export->dataset)->replace('_', ' ')->title() }}</td>
                            <td class="py-2 pr-4">{{ $export->period_from->toDateString() }} – {{ $export->period_to->toDateString() }}</td>
                            <td class="py-2 pr-4">{{ strtoupper($export->format) }}</td>
                            <td class="py-2 pr-4">{{ $export->status }}</td>
                            <td class="py-2 pr-4">{{ $export->row_count ?? '—' }}</td>
                            <td class="py-2">{{ $export->expires_at?->format('M d, Y') ?? '—' }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
    <form method="GET" class="hims-filter-bar rounded-xl border border-slate-200 bg-white p-4">
        <label class="text-xs text-slate-600">Start date<input type="date" name="start" value="{{ request('start', today()->subDays(30)->toDateString()) }}" class="mt-1 block rounded-lg border-slate-300 text-sm"></label>
        <label class="text-xs text-slate-600">End date<input type="date" name="end" value="{{ request('end', today()->toDateString()) }}" class="mt-1 block rounded-lg border-slate-300 text-sm"></label>
        <label class="text-xs text-slate-600">Department<select name="department_id" class="mt-1 block rounded-lg border-slate-300 text-sm"><option value="">All departments</option>@foreach ($reportDepartments as $department)<option value="{{ $department->id }}" @selected((string) request('department_id') === (string) $department->id)>{{ $department->name }}</option>@endforeach</select></label>
        <label class="text-xs text-slate-600">Doctor<select name="doctor_id" class="mt-1 block rounded-lg border-slate-300 text-sm"><option value="">All doctors</option>@foreach ($reportDoctors as $doctor)<option value="{{ $doctor->id }}" @selected((string) request('doctor_id') === (string) $doctor->id)>{{ $doctor->display_name }}</option>@endforeach</select></label>
        <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Set export filters</button>
    </form>
    <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach ([
            ['patients', 'Patient Registrations', 'Daily patient registrations in a date range'],
            ['appointments', 'Appointment Volume', 'Appointment counts, cancellation, and no-show'],
            ['encounters', 'Outpatient Encounters', 'Encounter volume by type'],
            ['er', 'ER Volume & Triage', 'ER visits and triage priority distribution'],
            ['beds', 'Bed Occupancy', 'Current bed status and occupied admissions'],
            ['telehealth', 'Telehealth Usage', 'Telehealth sessions by status'],
        ] as [$route, $title, $desc])
            <article class="panel-card p-5">
                <a href="{{ route('reports.' . $route, request()->query()) }}" class="font-semibold text-slate-800 hover:text-teal-700">{{ $title }}</a>
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $desc }}</p>
                <div class="mt-4 flex gap-2">
                    <a href="{{ route('reports.export', ['report' => $route, 'format' => 'csv', ...request()->query()]) }}" class="rounded bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white">CSV</a>
                    <a href="{{ route('reports.export', ['report' => $route, 'format' => 'pdf', ...request()->query()]) }}" class="rounded bg-slate-700 px-3 py-1.5 text-xs font-semibold text-white">PDF</a>
                    <a href="{{ route('reports.export', ['report' => $route, 'format' => 'txt', ...request()->query()]) }}" class="rounded bg-slate-600 px-3 py-1.5 text-xs font-semibold text-white">TXT</a>
                    <a href="{{ route('reports.export', ['report' => $route, 'format' => 'xls', ...request()->query()]) }}" class="rounded bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white">XLS</a>
                </div>
            </article>
        @endforeach
        @foreach ([
            ['ai-triage-accuracy', 'AI Triage Accuracy', 'Clinician reference comparison and rule override metrics.'],
            ['ai-triage-outcomes', 'AI Triage Outcomes', 'Provider, urgency, specialty, and rule override totals.'],
            ['care-requests', 'Care Request Approvals', 'Lab, imaging, and procedure request statuses.'],
            ['reassignments', 'Doctor Reassignment Cases', 'Provider coverage cases and their current status.'],
        ] as [$slug, $title, $description])
            <article class="panel-card p-5">
                @if ($slug === 'ai-triage-accuracy')
                    <a href="{{ route('reports.ai-triage-accuracy', request()->query()) }}" class="font-semibold text-slate-800 hover:text-teal-700">{{ $title }}</a>
                @else
                    <div class="font-semibold text-slate-800">{{ $title }}</div>
                @endif
                <p class="mt-2 text-sm leading-6 text-slate-600">{{ $description }}</p>
                <div class="mt-4 flex gap-2">
                    @foreach (['csv', 'pdf', 'txt', 'xls'] as $format)
                        <a href="{{ route('reports.export', ['report' => $slug, 'format' => $format, ...request()->query()]) }}" class="rounded bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white">{{ strtoupper($format) }}</a>
                    @endforeach
                </div>
            </article>
        @endforeach
        @if (auth()->user()->hasAnyRole(['doctor', 'admin', 'super-admin']))
            <article class="panel-card p-5">
                <a href="{{ route('reports.ai-evaluation', request()->query()) }}" class="font-semibold text-slate-800">AI evaluation and monitoring</a>
                <p class="mt-2 text-sm leading-6 text-slate-600">Clinician agreement and triage safety monitoring.</p>
                <div class="mt-4 flex gap-2">
                    @foreach (['csv', 'pdf', 'txt', 'xls'] as $format)
                        <a href="{{ route('reports.export', ['report' => 'ai-evaluation-dataset', 'format' => $format, ...request()->query()]) }}" class="rounded bg-teal-700 px-3 py-1.5 text-xs font-semibold text-white">{{ strtoupper($format) }}</a>
                    @endforeach
                </div>
            </article>
        @endif
    </div>
</div>
@endsection
