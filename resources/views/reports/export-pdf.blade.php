<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $report }}</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; color: #1e293b; font-size: 10px; }
        h1 { font-size: 18px; margin-bottom: 5px; text-transform: capitalize; }
        .filters { color: #475569; margin-bottom: 16px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #cbd5e1; padding: 5px; text-align: left; }
        th { background: #f1f5f9; }
    </style>
</head>
<body>
    <h1>{{ $report }} report</h1>
    <p class="filters">Date range: {{ $filters['start'] }} to {{ $filters['end'] }}</p>
    <table>
        <thead><tr>@foreach ($headers as $header)<th>{{ $header }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>@foreach ($row as $value)<td>{{ $value }}</td>@endforeach</tr>
            @empty
                <tr><td colspan="{{ count($headers) }}">No records match these filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
