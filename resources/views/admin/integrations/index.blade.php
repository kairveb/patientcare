@extends('layouts.hims')

@section('title', 'Integration deliveries')
@section('page-title', 'Integration deliveries')
@section('page-kicker', 'Admin utilities')

@section('content')
<div class="space-y-6">
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-lg font-semibold text-slate-900">Partners</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Partner</th><th class="py-2 pr-4">Active</th><th class="py-2 pr-4">Mode</th><th class="py-2">Last used</th></tr></thead>
                <tbody>
                @foreach ($partners as $partner)
                    <tr class="border-b last:border-0"><td class="py-3 pr-4 font-medium">{{ $partner->name }}</td><td class="py-3 pr-4">{{ $partner->active ? 'Yes' : 'No' }}</td><td class="py-3 pr-4">{{ $partner->mode }}</td><td class="py-3">{{ $partner->last_used_at?->format('M d, Y g:i A') ?? '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-lg font-semibold text-slate-900">Delivery status</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Partner</th><th class="py-2 pr-4">Entity</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">Attempts</th><th class="py-2">Next retry</th></tr></thead>
                <tbody>
                @foreach ($deliveries as $delivery)
                    <tr class="border-b last:border-0"><td class="py-3 pr-4">{{ $delivery->partner->code }}</td><td class="py-3 pr-4">{{ $delivery->entity_type }} · {{ $delivery->entity_id }}</td><td class="py-3 pr-4">{{ $delivery->status }}</td><td class="py-3 pr-4">{{ $delivery->attempts }}</td><td class="py-3">{{ $delivery->next_retry_at?->format('M d, Y g:i A') ?? '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
        {{ $deliveries->links() }}
    </section>
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h2 class="text-lg font-semibold text-slate-900">Recent integration logs</h2>
        <div class="mt-4 overflow-x-auto">
            <table class="min-w-full text-left text-sm">
                <thead><tr class="border-b text-slate-500"><th class="py-2 pr-4">Partner</th><th class="py-2 pr-4">Event</th><th class="py-2 pr-4">Status</th><th class="py-2 pr-4">HTTP</th><th class="py-2">Request ID</th></tr></thead>
                <tbody>
                @foreach ($logs as $log)
                    <tr class="border-b last:border-0"><td class="py-3 pr-4">{{ $log->integration }}</td><td class="py-3 pr-4">{{ $log->event }}</td><td class="py-3 pr-4">{{ $log->status }}</td><td class="py-3 pr-4">{{ $log->status_code ?? '—' }}</td><td class="py-3 font-mono text-xs">{{ $log->request_id ?? '—' }}</td></tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection
