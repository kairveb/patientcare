@extends('layouts.hims')

@section('title', 'My Patient ID')
@section('page-title', 'My Patient ID')
@section('page-kicker', 'Patient portal')
@section('page-description', 'Show or print your Patient Reference Code (PRC) for check-in.')

@section('content')
<div class="mx-auto max-w-xl rounded-2xl border border-slate-200 bg-white p-8 text-center shadow-sm">
    <p class="text-sm font-medium text-slate-500">{{ $patient->full_name }}</p>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">My Patient ID</h1>
    @if ($patient->reference_code && $referenceCodeQr)
        <p class="mt-6 text-xs font-semibold uppercase tracking-[0.25em] text-slate-500">Patient Reference Code</p>
        <p class="mt-2 text-xs font-semibold uppercase tracking-wide text-slate-500">Patient Reference Code (PRC)</p>
        <p class="mt-2 break-all font-mono text-2xl font-bold tracking-wider text-teal-800">{{ $patient->reference_code }}</p>
        <img src="{{ $referenceCodeQr }}" alt="QR code containing only your patient reference code" class="mx-auto mt-6 h-56 w-56 rounded-xl border border-slate-200 bg-white p-3">
        <p class="mt-4 text-sm text-slate-600">Staff can scan the QR code or type this code to find your patient record.</p>
        <button type="button" onclick="window.print()" class="mt-6 rounded-xl bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800 print:hidden">Print Patient ID</button>
    @else
        <p class="mt-6 rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">Your Patient Reference Code has not been assigned yet. Please contact Registration Staff.</p>
    @endif
    <a href="{{ route('patients.portal') }}" class="ml-2 mt-6 inline-flex rounded-xl border border-slate-300 px-5 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50 print:hidden">Back to portal</a>
</div>
<style>
    @media print {
        body { background: #fff !important; }
        body * { visibility: hidden; }
        .max-w-xl, .max-w-xl * { visibility: visible; }
        .max-w-xl { position: absolute; inset: 0 auto auto 0; width: 100%; max-width: none; border: 0; box-shadow: none; }
    }
</style>
@endsection
