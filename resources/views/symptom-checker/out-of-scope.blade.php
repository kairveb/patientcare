@extends('layouts.hims')

@section('title', 'Symptom triage')
@section('page-title', 'Symptom triage')
@section('page-description', 'This assistant has a focused healthcare purpose.')

@section('content')
<section class="mx-auto max-w-2xl rounded-2xl border border-sky-200 bg-sky-50 p-6 text-sky-950">
    <h2 class="text-lg font-semibold">Let’s keep this focused on your care</h2>
    <p class="mt-2">{{ $message }}</p>
    <a href="{{ route('symptom-checker.create') }}" class="mt-4 inline-flex rounded-lg bg-sky-800 px-4 py-2 font-semibold text-white">Return to symptom checker</a>
</section>
@endsection
