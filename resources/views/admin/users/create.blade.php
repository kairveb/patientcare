@extends('layouts.hims')

@section('title', 'Create User')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <div>
        <a href="{{ route('admin.users.index') }}" class="text-sm font-medium text-teal-700 hover:underline">&larr; User Management</a>
        <h1 class="mt-2 text-2xl font-bold text-slate-800">Create user account</h1>
        <p class="mt-1 text-sm text-slate-500">Select a system role. Nurse and registration records remain separate for access control and display as Staff.</p>
    </div>

    @if ($errors->any())
        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800" role="alert">
            <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('admin.users.store') }}" class="space-y-5 rounded-xl border border-slate-200 bg-white p-6">
        @csrf
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label for="name" class="mb-1 block text-sm font-medium text-slate-700">Account name</label>
                <input id="name" name="name" value="{{ old('name') }}" required maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label for="email" class="mb-1 block text-sm font-medium text-slate-700">Email</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label for="username" class="mb-1 block text-sm font-medium text-slate-700">Username <span class="font-normal text-slate-500">(optional; defaults from email)</span></label>
                <input id="username" name="username" value="{{ old('username') }}" maxlength="190" autocomplete="username" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
            <div>
                <label for="role" class="mb-1 block text-sm font-medium text-slate-700">Role</label>
                <select id="role" name="role" required class="w-full rounded-lg border border-slate-300 px-3 py-2">
                    <option value="">Select role</option>
                    @foreach ($managedRoles as $roleName => $roleLabel)
                        <option value="{{ $roleName }}" @selected(old('role') === $roleName)>{{ $roleLabel }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label for="password" class="mb-1 block text-sm font-medium text-slate-700">Temporary password</label>
                <input id="password" name="password" type="password" required autocomplete="new-password" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                <p class="mt-1 text-xs text-slate-500">At least 8 characters with upper/lowercase letters, a number, and a symbol.</p>
            </div>
            <div>
                <label for="password_confirmation" class="mb-1 block text-sm font-medium text-slate-700">Confirm password</label>
                <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="w-full rounded-lg border border-slate-300 px-3 py-2">
            </div>
        </div>

        <fieldset class="space-y-4 rounded-lg border border-slate-200 p-4">
            <legend class="px-1 text-sm font-semibold text-slate-700">Patient record details (required for Patient role)</legend>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label for="first_name" class="mb-1 block text-sm font-medium text-slate-700">First name</label><input id="first_name" name="first_name" value="{{ old('first_name') }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2"></div>
                <div><label for="middle_name" class="mb-1 block text-sm font-medium text-slate-700">Middle name</label><input id="middle_name" name="middle_name" value="{{ old('middle_name') }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2"></div>
                <div><label for="last_name" class="mb-1 block text-sm font-medium text-slate-700">Last name</label><input id="last_name" name="last_name" value="{{ old('last_name') }}" maxlength="100" class="w-full rounded-lg border border-slate-300 px-3 py-2"></div>
                <div><label for="date_of_birth" class="mb-1 block text-sm font-medium text-slate-700">Date of birth</label><input id="date_of_birth" name="date_of_birth" type="date" value="{{ old('date_of_birth') }}" max="{{ now()->toDateString() }}" class="w-full rounded-lg border border-slate-300 px-3 py-2"></div>
                <div>
                    <label for="sex" class="mb-1 block text-sm font-medium text-slate-700">Sex</label>
                    <select id="sex" name="sex" class="w-full rounded-lg border border-slate-300 px-3 py-2">
                        <option value="">Select</option>
                        @foreach (['Male', 'Female', 'Other'] as $sexOption)
                            <option value="{{ $sexOption }}" @selected(old('sex') === $sexOption)>{{ $sexOption }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </fieldset>

        <div class="flex justify-end gap-3">
            <a href="{{ route('admin.users.index') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-medium text-slate-700">Cancel</a>
            <button type="submit" class="rounded-lg bg-teal-600 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-700">Create account</button>
        </div>
    </form>
</div>
@endsection
