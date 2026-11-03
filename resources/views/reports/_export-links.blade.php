<div class="flex flex-wrap gap-2">
    @foreach (['csv' => 'Export CSV', 'pdf' => 'Export PDF'] as $format => $label)
        <a href="{{ route('reports.export', ['report' => $report, 'format' => $format, 'start' => request('start', $start ?? today()->subDays(30)->toDateString()), 'end' => request('end', $end ?? today()->toDateString()), 'department_id' => request('department_id'), 'doctor_id' => request('doctor_id')]) }}" class="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white">{{ $label }}</a>
    @endforeach
</div>
