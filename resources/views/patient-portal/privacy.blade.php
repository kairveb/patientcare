@extends('layouts.hims')

@section('title', 'My Privacy')
@section('page-title', 'My Privacy')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Review consent choices, make a data request, and see staff access events.')

@section('content')
<div class="space-y-6">
    @if (session('success'))<p role="status" class="rounded-lg bg-emerald-50 p-3 text-sm text-emerald-900">{{ session('success') }}</p>@endif
    <section class="rounded-xl border bg-white p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="font-semibold">My information copy</h2>
            <div class="flex flex-wrap gap-2">
                <a class="rounded-lg border px-3 py-2 text-sm font-semibold" href="{{ route('patients.portal.privacy.export.json') }}">Download JSON</a>
                <a class="rounded-lg border px-3 py-2 text-sm font-semibold" href="{{ route('patients.portal.privacy.export.print') }}">Printable copy</a>
            </div>
        </div>
        <p class="mt-2 text-sm text-slate-600">Includes your profile, consent history, appointments, outpatient approvals, and prescriptions.</p>
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-semibold">Consent history and choices</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="py-2 pr-4">Type</th><th class="py-2 pr-4">Version</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Date</th><th>Action</th></tr></thead>
                <tbody>
                @foreach (config('consents.types') as $type => $definition)
                    @php
                        $latest = $history->firstWhere('type', $type);
                        $granted = $latest?->granted && $latest?->version === $definition['version'];
                        $optional = ! ($definition['required'] ?? false);
                    @endphp
                    <tr class="border-b">
                        <td class="py-2 pr-4">{{ $definition['title'] }}</td>
                        <td class="py-2 pr-4">{{ $latest?->version ?? 'Not recorded' }}</td>
                        <td class="py-2 pr-4">{{ $granted ? 'Granted' : ($latest ? 'Withdrawn or outdated' : 'Not recorded') }}</td>
                        <td class="py-2 pr-4">{{ $latest?->recorded_at?->format('M j, Y g:i A') ?? '—' }}</td>
                        <td>
                            @if ($optional && $granted)
                                <form method="POST" action="{{ route('patient-consents.portal.store') }}">@csrf<input type="hidden" name="type" value="{{ $type }}"><input type="hidden" name="granted" value="0"><button class="text-amber-800 underline">Withdraw</button></form>
                            @elseif ($optional)
                                <a class="text-teal-800 underline" href="{{ route('patient-consents.portal') }}">Manage</a>
                            @else
                                <span class="text-slate-500">Required</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-600">Withdrawing AI or telehealth consent blocks the related feature where the system checks that consent. Required privacy/terms acknowledgments cannot be withdrawn here; ask staff about a concern or restriction request.</p>
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-semibold">Submit a data subject request</h2>
        <form method="POST" action="{{ route('patients.portal.privacy.requests.store') }}" class="mt-4 grid gap-3">
            @csrf
            <label class="text-sm font-medium">Request type
                <select name="type" required class="mt-1 block w-full rounded-lg border-slate-300">
                    <option value="access_copy">Access or copy</option>
                    <option value="correction">Correction</option>
                    <option value="restriction_objection">Restriction or objection</option>
                    <option value="erasure">Erasure</option>
                </select>
            </label>
            <label class="text-sm font-medium">Note
                <textarea name="note" required minlength="5" maxlength="3000" rows="3" class="mt-1 block w-full rounded-lg border-slate-300" placeholder="Explain what you are requesting."></textarea>
            </label>
            @error('type')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            @error('note')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <p class="text-xs text-slate-600">Erasure requests are reviewed individually. Clinical records may need to be retained; an outcome may instead restrict or anonymize information where allowed.</p>
            <button class="justify-self-start rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Submit request</button>
        </form>
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-semibold">My requests</h2>
        <div class="mt-3 space-y-3">
            @forelse ($requests as $privacyRequest)
                <a href="{{ route('privacy.requests.show', $privacyRequest) }}" class="block rounded-lg border p-3 hover:border-teal-500">
                    <span class="font-medium">{{ $privacyRequest->typeLabel() }}</span>
                    <span class="ml-2 text-sm text-slate-600">{{ $privacyRequest->status }}</span>
                    @if ($privacyRequest->response_note)<p class="mt-1 text-sm text-slate-600">{{ $privacyRequest->response_note }}</p>@endif
                </a>
            @empty
                <p class="text-sm text-slate-600">No data requests have been submitted.</p>
            @endforelse
        </div>
        {{ $requests->links() }}
    </section>

    <section class="rounded-xl border bg-white p-5">
        <h2 class="font-semibold">Who accessed my record</h2>
        <p class="mt-1 text-sm text-slate-600">This list shows staff access events only. It does not display clinical content.</p>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="border-b text-xs uppercase text-slate-500"><tr><th class="py-2 pr-4">Role</th><th class="py-2 pr-4">Date and time</th><th>Action</th></tr></thead>
                <tbody>
                @forelse ($accessLogs as $log)
                    <tr class="border-b"><td class="py-2 pr-4">{{ $log->user?->roles->pluck('display_name')->unique()->join(', ') ?: 'Staff' }}</td><td class="py-2 pr-4">{{ $log->created_at->format('M j, Y g:i A') }}</td><td>{{ str_replace('_', ' ', strtolower($log->action)) }}</td></tr>
                @empty
                    <tr><td colspan="3" class="py-3 text-slate-600">No staff access events are currently listed.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        {{ $accessLogs->links() }}
    </section>
</div>
@endsection
