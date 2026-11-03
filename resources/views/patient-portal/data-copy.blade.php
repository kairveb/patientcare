<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>My Data Copy</title><style>body{font-family:Arial,sans-serif;max-width:900px;margin:2rem auto;padding:0 1rem;color:#1f2937}h1,h2{color:#0f766e}section{margin:1.5rem 0;border-bottom:1px solid #ddd;padding-bottom:1rem}pre{white-space:pre-wrap;overflow-wrap:anywhere}@media print{button{display:none}}</style></head>
<body>
<button onclick="window.print()">Print this copy</button>
<h1>My Data Copy</h1><p>Generated {{ $data['generated_at'] }}. This copy contains information associated with your patient account.</p>
@foreach (['profile' => 'Profile', 'consents' => 'Consent records', 'appointments' => 'Appointments', 'approvals' => 'Approvals', 'prescriptions' => 'Prescriptions'] as $key => $label)
    <section><h2>{{ $label }}</h2><pre>{{ json_encode($data[$key], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre></section>
@endforeach
</body></html>
