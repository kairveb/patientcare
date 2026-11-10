@extends('layouts.hims')
@section('title', 'Report provider unavailability')
@section('content')
<div class="mx-auto max-w-3xl space-y-5">
    <header><h1 class="text-2xl font-bold text-slate-900">Report provider unavailability</h1><p class="mt-1 text-sm text-slate-600">This blocks open slots and starts a review of affected appointments.</p></header>
    @if (session('success'))<div role="status" class="rounded border border-green-200 bg-green-50 p-3 text-sm text-green-800">{{ session('success') }}</div>@endif
    @if ($errors->any())<div role="alert" class="rounded border border-red-200 bg-red-50 p-3 text-sm text-red-800">@foreach ($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>@endif
    <form method="POST" action="{{ route('provider-unavailabilities.store') }}" class="grid gap-4 rounded-xl border bg-white p-6 md:grid-cols-2">
        @csrf
        <label class="text-sm font-medium text-slate-700">Provider
            <select name="provider_id" required @disabled($providers->isEmpty()) class="mt-1 w-full rounded border-slate-300">
                <option value="">Select provider</option>
                @foreach ($providers as $provider)<option value="{{ $provider->id }}" @selected((string) old('provider_id', auth()->user()->provider?->id) === (string) $provider->id)>{{ $provider->full_name }} — {{ $provider->department?->name }}</option>@endforeach
            </select>
            @if ($providers->isEmpty())<span class="mt-1 block text-xs text-amber-700">No active doctors are available.</span>@endif
        </label>
        <label class="text-sm font-medium text-slate-700">Reason
            <select name="reason" required class="mt-1 w-full rounded border-slate-300"><option value="emergency" @selected(old('reason') === 'emergency')>Emergency</option><option value="sick_leave" @selected(old('reason') === 'sick_leave')>Sick leave</option><option value="other" @selected(old('reason') === 'other')>Other</option></select>
        </label>
        <label class="text-sm font-medium text-slate-700">Starts at<input name="starts_at" type="datetime-local" required class="mt-1 w-full rounded border-slate-300"></label>
        <label class="text-sm font-medium text-slate-700">Ends at<input name="ends_at" type="datetime-local" required class="mt-1 w-full rounded border-slate-300"></label>
        <label class="text-sm font-medium text-slate-700 md:col-span-2">Operational details (optional)<textarea name="details" maxlength="1000" rows="3" class="mt-1 w-full rounded border-slate-300"></textarea></label>
        <div class="md:col-span-2"><button class="rounded-lg bg-teal-700 px-4 py-2 font-semibold text-white">Record unavailable period</button></div>
    </form>
</div>
@endsection
