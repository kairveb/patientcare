@extends('layouts.hims')

@section('title', 'Patient Consents')
@section('page-title', 'Consent Preferences')
@section('page-kicker', $self ? 'Patient portal' : 'Patient record')
@section('page-description', $self ? 'Review and manage your recorded consents.' : 'Current consent status and append-only history.')

@section('content')
<div class="mx-auto max-w-4xl space-y-5">
    @if ($requiredType && isset($types[$requiredType]))
        <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
            <p class="font-semibold">{{ $types[$requiredType]['title'] }} is required for this action.</p>
            <p class="mt-1">{{ $self ? 'Review the version below and record your choice to continue.' : 'Record the patient’s choice below before continuing.' }}</p>
        </div>
    @endif
    @if (session('warning'))
        <div role="alert" class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">{{ session('warning') }}</div>
    @endif
    @if (session('success'))
        <div role="status" class="rounded-xl border border-emerald-300 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</div>
    @endif

    @foreach ($types as $type => $consentType)
        @php
            $latestConsent = $latest[$type] ?? null;
            $isCurrent = $latestConsent
                && $latestConsent->granted
                && $latestConsent->version === $consentType['version'];
            $canRecord = $self || auth()->user()->hasRole('registration');
        @endphp
        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="font-semibold text-slate-900">{{ $consentType['title'] }}</h2>
                    <p class="mt-1 text-xs text-slate-500">Version {{ $consentType['version'] }} · {{ $isCurrent ? 'Granted' : 'Not currently granted' }}</p>
                </div>
                @if ($latestConsent)
                    <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $latestConsent->granted ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-900' }}">
                        {{ $latestConsent->granted ? 'Granted' : 'Withdrawn' }}
                    </span>
                @endif
            </div>
            <p class="text-sm leading-6 text-slate-700">{{ $consentType['text'] }}</p>

            @if ($canRecord)
                <div class="flex flex-wrap gap-2 border-t border-slate-100 pt-4">
                    @unless ($isCurrent)
                        <form method="POST" action="{{ $self ? route('patient-consents.portal.store') : route('patients.consents.store', $patient) }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <input type="hidden" name="granted" value="1">
                            <button type="submit" class="rounded-lg bg-teal-700 px-3 py-2 text-sm font-semibold text-white">
                                {{ $self ? 'I agree' : 'Record consent granted' }}
                            </button>
                        </form>
                    @endunless
                    @if ($isCurrent && ! ($consentType['required'] ?? false))
                        <form method="POST" action="{{ $self ? route('patient-consents.portal.store') : route('patients.consents.store', $patient) }}">
                            @csrf
                            <input type="hidden" name="type" value="{{ $type }}">
                            <input type="hidden" name="granted" value="0">
                            <button type="submit" class="rounded-lg border border-amber-300 px-3 py-2 text-sm font-semibold text-amber-900">
                                {{ $self ? 'Withdraw consent' : 'Record withdrawal' }}
                            </button>
                        </form>
                    @endif
                </div>
            @endif
        </section>
    @endforeach

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="font-semibold text-slate-900">Consent history</h2>
        @if ($history->isEmpty())
            <p class="mt-3 text-sm text-slate-500">No consent records are on file.</p>
        @else
            <div class="mt-3 overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                    <thead class="text-xs uppercase text-slate-500"><tr><th class="py-2 pr-4">Type / version</th><th class="py-2 pr-4">Decision</th><th class="py-2 pr-4">Channel</th><th class="py-2 pr-4">Recorded</th></tr></thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($history as $entry)
                            <tr>
                                <td class="py-2 pr-4">{{ $types[$entry->type]['title'] ?? $entry->type }} · {{ $entry->version ?? 'legacy' }}</td>
                                <td class="py-2 pr-4">{{ $entry->granted ? 'Granted' : 'Withdrawn' }}</td>
                                <td class="py-2 pr-4">{{ $entry->channel ?? 'legacy' }}</td>
                                <td class="py-2 pr-4">{{ $entry->recorded_at?->format('M d, Y g:i A') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
