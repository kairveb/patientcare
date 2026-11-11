@extends('layouts.hims')

@section('title', 'Notifications')
@section('page-kicker', 'System alerts')
@section('page-title', 'Notifications')

@section('content')
<div class="mx-auto max-w-6xl space-y-6">
    <form method="GET" action="{{ route('notifications.index') }}" class="panel-card flex flex-wrap items-end gap-3 p-4">
        <div>
            <label for="module-filter" class="mb-1 block text-sm font-medium text-slate-700">Module</label>
            <select id="module-filter" name="module" class="rounded-lg border-slate-300 text-sm">
                <option value="">All modules</option>
                @foreach (['SPRS', 'ASS', 'TOCS', 'EERTS', 'IBMS'] as $module)
                    <option value="{{ $module }}" @selected(($filters['module'] ?? '') === $module)>{{ $module }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label for="priority-filter" class="mb-1 block text-sm font-medium text-slate-700">Priority</label>
            <select id="priority-filter" name="priority" class="rounded-lg border-slate-300 text-sm">
                <option value="">All priorities</option>
                @foreach (['Critical', 'High', 'Normal', 'Low'] as $priority)
                    <option value="{{ $priority }}" @selected(($filters['priority'] ?? '') === $priority)>{{ $priority }}</option>
                @endforeach
            </select>
        </div>
        <button type="submit" class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Filter</button>
        <a href="{{ route('notifications.index') }}" class="text-sm font-medium text-slate-600 underline">Clear</a>
    </form>

    <section class="space-y-3" aria-label="Notification list">
        @forelse ($notifications as $notification)
            @php
                $data = $notification->data;
                $priority = $data['priority'] ?? 'Normal';
                $badge = match ($priority) {
                    'Critical' => 'bg-red-100 text-red-800',
                    'High' => 'bg-orange-100 text-orange-800',
                    'Low' => 'bg-slate-100 text-slate-700',
                    default => 'bg-blue-100 text-blue-800',
                };
            @endphp
            <article class="panel-card flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between {{ $notification->read_at ? 'opacity-75' : '' }}">
                <div class="space-y-2">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="font-semibold text-slate-900">{{ $data['title'] ?? 'Notification' }}</h2>
                        <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $badge }}">{{ $priority }}</span>
                        @if (! empty($data['module']))
                            <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-medium text-slate-700">{{ $data['module'] }}</span>
                        @endif
                        @unless ($notification->read_at)
                            <span class="text-xs font-semibold text-teal-700">Unread</span>
                        @endunless
                    </div>
                    <p class="text-sm leading-6 text-slate-600">{{ $data['message'] ?? '' }}</p>
                    <p class="text-xs text-slate-500">{{ $notification->created_at?->format('M j, Y g:i A') }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    @if (filled($data['url'] ?? null))
                        <a href="{{ $data['url'] }}" class="text-sm font-semibold text-teal-700 underline">Open</a>
                    @endif
                    @unless ($notification->read_at)
                        <form method="POST" action="{{ route('notifications.read', $notification->id) }}">
                            @csrf
                            <button type="submit" class="text-sm font-medium text-slate-600 underline">Mark read</button>
                        </form>
                    @endunless
                </div>
            </article>
        @empty
            <div class="panel-card p-8 text-center text-sm text-slate-500">No notifications match these filters.</div>
        @endforelse
    </section>

    {{ $notifications->links() }}
</div>
@endsection
