@extends('layouts.hims')

@section('title', 'Symptom Check Result')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <div class="rounded-xl border border-slate-200 bg-white p-6">
        <p class="text-xs font-semibold uppercase tracking-wider text-teal-700">AI-assisted suggestion. Final decision belongs to a healthcare professional.</p>
        <h1 class="mt-2 text-2xl font-bold text-slate-800">{{ $symptomCheck->recommended_department }}</h1>
        <p class="mt-1 text-slate-700">Suggested specialty: <strong>{{ $symptomCheck->ai_result['recommended_specialty'] ?? $symptomCheck->recommended_department }}</strong></p>
        <p class="mt-2 text-slate-700">Recommended consultation: <strong>{{ $symptomCheck->recommended_consult_type }}</strong></p>
        <p class="mt-1 text-slate-700">Urgency: <strong>{{ $symptomCheck->urgency_level }}</strong> (Level {{ $symptomCheck->urgency_score }})</p>
        @if ($symptomCheck->ai_confidence !== null)
            <p class="mt-1 text-sm text-slate-600">AI confidence: {{ $symptomCheck->ai_confidence }}%</p>
        @endif

        @if ($symptomCheck->ai_result['fallback'] ?? false)
            <p role="status" class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">{{ $symptomCheck->ai_result['fallback_message'] }}</p>
        @endif

        <div class="mt-5">
            <h2 class="font-semibold text-slate-800">Summary</h2>
            <p class="mt-2 text-sm text-slate-700">{{ $symptomCheck->ai_result['summary'] ?? 'A rule-based symptom check was completed.' }}</p>
        </div>

        @if (! empty($symptomCheck->ai_result['red_flags']))
            <div class="mt-5 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                <strong>Red flags:</strong> {{ implode(', ', $symptomCheck->ai_result['red_flags']) }}
            </div>
        @endif

        @if (! empty($symptomCheck->ai_result['advice']))
            <div class="mt-5">
                <h2 class="font-semibold text-slate-800">Advice</h2>
                <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-700">
                    @foreach ($symptomCheck->ai_result['advice'] as $advice)
                        <li>{{ $advice }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="mt-5">
            <h2 class="font-semibold text-slate-800">Reasons</h2>
            <ul class="mt-2 list-inside list-disc space-y-1 text-sm text-slate-700">
                @foreach ($symptomCheck->ai_reasons ?? [] as $reason)
                    <li>{{ $reason }}</li>
                @endforeach
            </ul>
        </div>

        <div class="mt-5 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
            This is not a medical diagnosis.
        </div>

        <a href="{{ route('appointments.create', ['symptom_check_id' => $symptomCheck->id]) }}" class="mt-5 inline-flex rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">Book Appointment</a>
    </div>
</div>
@endsection
