@extends('layouts.hims')

@section('title', 'Charge Rates')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-900">Billing Charge Rates</h1>
        <p class="mt-1 text-sm text-slate-600">Only active PHP rates are used when creating itemized statements.</p>
    </div>

    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="mb-4 font-semibold text-slate-900">Add a rate</h2>
        <form method="POST" action="{{ route('billing.rates.store') }}" class="grid gap-4 md:grid-cols-2">
            @csrf
            <label class="text-sm">Charge code
                <input name="charge_code" value="{{ old('charge_code') }}" placeholder="encounter:OUTPATIENT" required class="mt-1 w-full rounded border-slate-300">
            </label>
            <label class="text-sm">Category
                <select name="category" required class="mt-1 w-full rounded border-slate-300">
                    @foreach (['ENCOUNTER', 'PRESCRIPTION', 'BED_DAILY'] as $category)<option value="{{ $category }}">{{ $category }}</option>@endforeach
                </select>
            </label>
            <label class="text-sm">Description
                <input name="description" required maxlength="255" class="mt-1 w-full rounded border-slate-300">
            </label>
            <label class="text-sm">Unit amount (PHP)
                <input name="unit_amount" type="number" min="0.01" step="0.01" required class="mt-1 w-full rounded border-slate-300">
            </label>
            <input type="hidden" name="currency" value="PHP">
            <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="active" value="1" checked> Active</label>
            <div><button class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">Create rate</button></div>
        </form>
        @error('charge_code')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
        @error('unit_amount')<p class="mt-2 text-sm text-red-700">{{ $message }}</p>@enderror
    </section>

    <section class="overflow-hidden rounded-xl border border-slate-200 bg-white">
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">Code</th><th class="px-4 py-3">Description</th><th class="px-4 py-3">Price</th><th class="px-4 py-3">Active</th><th class="px-4 py-3">Update</th></tr></thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rates as $rate)
                        <tr>
                            <td class="px-4 py-3 font-mono">{{ $rate->charge_code }}</td>
                            <td class="px-4 py-3">{{ $rate->description }} <span class="text-xs text-slate-500">({{ $rate->category }})</span></td>
                            <td class="px-4 py-3">{{ $rate->currency }} {{ number_format((float) $rate->unit_amount, 2) }}</td>
                            <td class="px-4 py-3">{{ $rate->active ? 'Yes' : 'No' }}</td>
                            <td class="px-4 py-3">
                                <form method="POST" action="{{ route('billing.rates.update', $rate) }}" class="flex flex-wrap items-center gap-2">
                                    @csrf @method('PUT')
                                    <input type="hidden" name="charge_code" value="{{ $rate->charge_code }}">
                                    <input type="hidden" name="category" value="{{ $rate->category }}">
                                    <input type="hidden" name="description" value="{{ $rate->description }}">
                                    <input type="number" name="unit_amount" min="0.01" step="0.01" value="{{ $rate->unit_amount }}" required class="w-28 rounded border-slate-300 text-sm">
                                    <input type="hidden" name="currency" value="PHP">
                                    <label class="flex items-center gap-1"><input type="checkbox" name="active" value="1" @checked($rate->active)> Active</label>
                                    <button class="rounded bg-slate-800 px-3 py-1.5 text-xs font-medium text-white">Save</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <div class="p-4">{{ $rates->links() }}</div>
    </section>
</div>
@endsection
