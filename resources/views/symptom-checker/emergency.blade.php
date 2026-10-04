@extends('layouts.hims')

@section('title', 'Emergency Care Instructions')

@section('content')
<div class="mx-auto max-w-3xl space-y-5">
    <section class="rounded-xl border-2 border-red-300 bg-red-50 p-6">
        <p class="text-xs font-bold uppercase tracking-wider text-red-800">Emergency routing alert</p>
        <h1 class="mt-2 text-2xl font-bold text-red-900">Do not continue with routine appointment booking</h1>
        <p class="mt-3 text-red-900">The symptom checker found an emergency-level concern. Please proceed to the nearest Emergency Department now. If the patient is in immediate danger, call 911. Do not wait for an online appointment.</p>
        <p class="mt-3 text-sm text-red-800">Suggested urgency: {{ $symptomCheck->urgency_level }} (Level {{ $symptomCheck->urgency_score }})</p>
    </section>

    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="font-semibold text-slate-800">Reasons for diversion</h2>
        <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-700">
            @foreach ($symptomCheck->ai_reasons ?? [] as $reason)
                <li>{{ $reason }}</li>
            @endforeach
        </ul>
        <p class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">AI decision support only, not a diagnosis. The OPD/Virtual/ER mapping is for clinical validation; a licensed clinician makes the final decision.</p>
    </section>
</div>
@endsection
