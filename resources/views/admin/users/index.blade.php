@extends('layouts.hims')

@section('title', 'User Management')

@section('content')
<div class="space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-2xl font-bold text-slate-800">User Management</h1>
            <p class="mt-1 text-sm text-slate-500">Create and manage accounts. Super Admin accounts are restricted to Super Admin operators.</p>
        </div>
        <a href="{{ route('admin.users.create') }}" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Create user</a>
    </div>

    @if (session('success'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-sm text-green-800" role="status">{{ session('success') }}</div>
    @endif
    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white">
        <table class="min-w-full divide-y divide-slate-200 text-sm">
            <thead class="bg-slate-50 text-left text-xs uppercase tracking-wide text-slate-500">
                <tr><th class="px-4 py-3">User</th><th class="px-4 py-3">Username</th><th class="px-4 py-3">Role</th><th class="px-4 py-3">Account status</th><th class="px-4 py-3">Actions</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($users as $managedUser)
                    <tr>
                        <td class="px-4 py-4">
                            <div class="font-medium text-slate-800">{{ $managedUser->name }}</div>
                            <div class="text-slate-500">{{ $managedUser->email }}</div>
                        </td>
                        <td class="px-4 py-4 font-mono text-slate-700">{{ $managedUser->username ?? '—' }}</td>
                        <td class="px-4 py-4">
                            <form method="POST" action="{{ route('admin.users.role', $managedUser) }}" class="flex flex-wrap gap-2">
                                @csrf
                                @method('PATCH')
                                <label class="sr-only" for="role-{{ $managedUser->id }}">Role for {{ $managedUser->name }}</label>
                                <select id="role-{{ $managedUser->id }}" name="role" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                    @foreach ($managedRoles as $roleName => $roleLabel)
                                        <option value="{{ $roleName }}" @selected($managedUser->roles->contains('name', $roleName))>{{ $roleLabel }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="rounded-lg border border-slate-300 px-3 py-1.5 font-medium text-slate-700 hover:bg-slate-50">Save role</button>
                                @if (! $managedUser->patient)
                                    <details class="basis-full">
                                        <summary class="cursor-pointer text-xs text-slate-500">Patient profile fields (required when assigning Patient)</summary>
                                        <div class="mt-2 grid gap-2 md:grid-cols-2">
                                            <input name="first_name" maxlength="100" placeholder="First name" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                            <input name="middle_name" maxlength="100" placeholder="Middle name" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                            <input name="last_name" maxlength="100" placeholder="Last name" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                            <input name="date_of_birth" type="date" max="{{ now()->toDateString() }}" aria-label="Patient date of birth" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                            <select name="sex" aria-label="Patient sex" class="rounded-lg border border-slate-300 px-2 py-1.5">
                                                <option value="">Select sex</option>
                                                @foreach (['Male', 'Female', 'Other'] as $sexOption)<option value="{{ $sexOption }}">{{ $sexOption }}</option>@endforeach
                                            </select>
                                        </div>
                                    </details>
                                @endif
                            </form>
                        </td>
                        <td class="px-4 py-4">
                            <span class="rounded-full px-2.5 py-1 text-xs font-semibold {{ $managedUser->is_active ? 'bg-green-100 text-green-800' : 'bg-slate-200 text-slate-700' }}">
                                {{ $managedUser->is_active ? 'Active' : 'Deactivated' }}
                            </span>
                        </td>
                        <td class="px-4 py-4">
                            <form method="POST" action="{{ route('admin.users.status', $managedUser) }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="is_active" value="{{ $managedUser->is_active ? '0' : '1' }}">
                                <button type="submit" class="rounded-lg px-3 py-1.5 font-medium {{ $managedUser->is_active ? 'border border-red-200 text-red-700 hover:bg-red-50' : 'border border-green-200 text-green-700 hover:bg-green-50' }}">
                                    {{ $managedUser->is_active ? 'Deactivate' : 'Activate' }}
                                </button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-4 py-8 text-center text-slate-500">No user accounts found.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $users->links() }}
</div>
@endsection
