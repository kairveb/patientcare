<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Approval {{ $careRequest->approval_reference }}</title>
    <style>
        body{font-family:Arial,sans-serif;color:#172033;margin:0;padding:32px}
        main{max-width:760px;margin:0 auto;border:1px solid #cbd5e1;padding:32px}
        h1{margin:0 0 8px;font-size:24px}.muted{color:#64748b;font-size:13px}
        dl{display:grid;grid-template-columns:180px 1fr;gap:14px;margin:28px 0}
        dt{color:#64748b}dd{margin:0;font-weight:600;white-space:pre-wrap}
        footer{border-top:1px solid #cbd5e1;padding-top:16px;margin-top:36px;color:#9f1239;font-weight:700}
        .actions{max-width:760px;margin:16px auto}
        @media print{body{padding:0}main{border:0}.actions{display:none}}
    </style>
</head>
<body>
    <div class="actions"><button type="button" onclick="window.print()">Print approval slip</button></div>
    <main>
        <p class="muted">OUTPATIENT SERVICE APPROVAL</p>
        <h1>Approval {{ $careRequest->approval_reference }}</h1>
        <p class="muted">Status: {{ $careRequest->status }}</p>
        <dl>
            <dt>Patient name</dt><dd>{{ $careRequest->patient->full_name }}</dd>
            <dt>Patient reference code</dt><dd>{{ $careRequest->patient->reference_code }}</dd>
            <dt>Approval reference</dt><dd>{{ $careRequest->approval_reference }}</dd>
            <dt>Approved service</dt><dd>{{ ucfirst($careRequest->type) }} — {{ $careRequest->description }}</dd>
            <dt>Status</dt><dd>{{ $careRequest->status }}</dd>
            <dt>Decision date</dt><dd>{{ $careRequest->decided_at?->format('M d, Y g:i A') ?? '—' }}</dd>
            <dt>Approver</dt><dd>{{ $careRequest->decidedBy->name ?? '—' }}</dd>
        </dl>
        <footer>Demo document. Not a real insurance authorization.</footer>
    </main>
</body>
</html>
