@extends('layouts.hims')
@section('title', 'Edit Doctor Profile')
@section('page-title', 'Edit doctor profile')
@section('content')
<form method="POST" action="{{ route('providers.profile.update', $provider) }}" enctype="multipart/form-data" class="max-w-2xl space-y-4 rounded-2xl border bg-white p-6">
    @csrf
    @method('PUT')
    <p class="text-sm text-slate-600">Profile details are optional. Keep descriptions short and patient-friendly.</p>
    <label class="block text-sm font-medium">Short description<textarea name="bio" maxlength="1000" rows="4" class="mt-1 w-full rounded-lg border-slate-300">{{ old('bio', $provider->bio) }}</textarea></label>
    <label class="block text-sm font-medium">Years of practice<input type="number" name="years_of_practice" min="0" max="80" value="{{ old('years_of_practice', $provider->years_of_practice) }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
    <label class="block text-sm font-medium">Languages (comma-separated)<input name="languages" maxlength="255" value="{{ old('languages', $provider->languages) }}" class="mt-1 w-full rounded-lg border-slate-300"></label>
    <label class="block text-sm font-medium">Photo<input type="file" name="photo" accept="image/*" class="mt-1 block w-full text-sm"></label>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="accepts_virtual" value="1" @checked(old('accepts_virtual', $provider->accepts_virtual))>Accepts virtual consultations</label>
    <button class="rounded-lg bg-teal-700 px-5 py-2 text-sm font-semibold text-white">Save profile</button>
</form>
@endsection
