@extends('layouts.hims')

@section('title', 'Bed Setup')

@section('content')
<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-slate-800">Bed Setup</h1>
        <p class="mt-1 text-sm text-slate-500">Configure wards, rooms, and beds. Operational status and patient assignments remain separate.</p>
    </div>
    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <section class="rounded-xl border border-slate-200 bg-white p-5">
        <h2 class="mb-4 text-lg font-semibold text-slate-800">Add ward</h2>
        <form method="POST" action="{{ route('admin.bed-setup.wards.store') }}" class="grid gap-3 md:grid-cols-4">
            @csrf
            <input name="code" required maxlength="30" placeholder="Ward code" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="name" required maxlength="255" placeholder="Ward name" class="rounded-lg border border-slate-300 px-3 py-2">
            <input name="type" maxlength="100" placeholder="Type (e.g. ICU)" class="rounded-lg border border-slate-300 px-3 py-2">
            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white">Add ward</button>
        </form>
    </section>

    @forelse ($wards as $ward)
        <section class="space-y-4 rounded-xl border border-slate-200 bg-white p-5">
            <div class="flex flex-wrap items-start gap-2 border-b border-slate-100 pb-4">
                <form method="POST" action="{{ route('admin.bed-setup.wards.update', $ward) }}" class="grid flex-1 gap-3 md:grid-cols-5">
                    @csrf
                    @method('PATCH')
                    <input name="code" value="{{ $ward->code }}" required maxlength="30" aria-label="Ward code" class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="name" value="{{ $ward->name }}" required maxlength="255" aria-label="Ward name" class="rounded-lg border border-slate-300 px-3 py-2">
                    <input name="type" value="{{ $ward->type }}" maxlength="100" aria-label="Ward type" class="rounded-lg border border-slate-300 px-3 py-2">
                    <label class="flex items-center gap-2 text-sm text-slate-700"><input type="hidden" name="active" value="0"><input type="checkbox" name="active" value="1" @checked($ward->active)> Active</label>
                    <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">Save ward</button>
                </form>
                <form method="POST" action="{{ route('admin.bed-setup.wards.destroy', $ward) }}" onsubmit="return confirm('Delete this empty ward?')">
                    @csrf
                    @method('DELETE')
                    <button class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700">Delete</button>
                </form>
            </div>

            <form method="POST" action="{{ route('admin.bed-setup.rooms.store') }}" class="grid gap-3 rounded-lg bg-slate-50 p-3 md:grid-cols-4">
                @csrf
                <input type="hidden" name="ward_id" value="{{ $ward->id }}">
                <input name="number" required maxlength="30" placeholder="Room number" class="rounded-lg border border-slate-300 px-3 py-2">
                <input name="type" maxlength="100" placeholder="Room type" class="rounded-lg border border-slate-300 px-3 py-2">
                <span class="self-center text-xs text-slate-500">Rooms must be empty to delete or move.</span>
                <button class="rounded-lg bg-slate-800 px-4 py-2 text-sm font-semibold text-white">Add room</button>
            </form>

            @foreach ($ward->rooms as $room)
                <div class="space-y-3 rounded-lg border border-slate-200 p-4">
                    <div class="flex flex-wrap items-start gap-2">
                        <form method="POST" action="{{ route('admin.bed-setup.rooms.update', $room) }}" class="grid flex-1 gap-3 md:grid-cols-4">
                            @csrf
                            @method('PATCH')
                            <select name="ward_id" required class="rounded-lg border border-slate-300 px-3 py-2" aria-label="Room ward">
                                @foreach ($wardOptions as $option)
                                    <option value="{{ $option->id }}" @selected($room->ward_id === $option->id)>{{ $option->name }}</option>
                                @endforeach
                            </select>
                            <input name="number" value="{{ $room->number }}" required maxlength="30" aria-label="Room number" class="rounded-lg border border-slate-300 px-3 py-2">
                            <input name="type" value="{{ $room->type }}" maxlength="100" aria-label="Room type" class="rounded-lg border border-slate-300 px-3 py-2">
                            <button class="rounded-lg border border-slate-300 px-3 py-2 text-sm font-medium text-slate-700">Save room</button>
                        </form>
                        <form method="POST" action="{{ route('admin.bed-setup.rooms.destroy', $room) }}" onsubmit="return confirm('Delete this empty room?')">
                            @csrf
                            @method('DELETE')
                            <button class="rounded-lg border border-red-200 px-3 py-2 text-sm font-medium text-red-700">Delete</button>
                        </form>
                    </div>

                    <form method="POST" action="{{ route('admin.bed-setup.beds.store') }}" class="flex flex-wrap gap-3">
                        @csrf
                        <input type="hidden" name="room_id" value="{{ $room->id }}">
                        <input name="number" required maxlength="30" placeholder="Bed number" class="rounded-lg border border-slate-300 px-3 py-2">
                        <button class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white">Add bed</button>
                    </form>

                    <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        @foreach ($room->beds as $bed)
                            <div class="rounded-lg bg-slate-50 p-3">
                                <form method="POST" action="{{ route('admin.bed-setup.beds.update', $bed) }}" class="flex gap-2">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="room_id" value="{{ $room->id }}">
                                    <label class="sr-only" for="bed-number-{{ $bed->id }}">Bed number</label>
                                    <input id="bed-number-{{ $bed->id }}" name="number" value="{{ $bed->number }}" required maxlength="30" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-1.5">
                                    <button class="rounded-lg border border-slate-300 px-2 py-1.5 text-xs font-medium">Save</button>
                                </form>
                                <div class="mt-2 flex items-center justify-between text-xs text-slate-500">
                                    <span>Status: {{ $bed->status }}</span>
                                    <form method="POST" action="{{ route('admin.bed-setup.beds.destroy', $bed) }}" onsubmit="return confirm('Delete this unused bed?')">
                                        @csrf
                                        @method('DELETE')
                                        <button class="font-medium text-red-700">Delete</button>
                                    </form>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>
    @empty
        <div class="rounded-xl border border-dashed border-slate-300 bg-white p-8 text-center text-slate-500">No wards are configured.</div>
    @endforelse
</div>
@endsection
