@extends('layouts.hims')

@section('title', 'Please wait')

@section('content')
<section class="mx-auto max-w-2xl rounded-xl border border-amber-200 bg-white p-6 shadow-sm" role="alert">
    <h1 class="text-xl font-semibold text-slate-800">Please wait before requesting another copy</h1>
    <p class="mt-2 text-sm text-slate-600">You have requested printable copies too frequently. Please wait a minute and try again.</p>
    <a class="mt-4 inline-flex rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white hover:bg-teal-800" href="{{ route('patients.portal.privacy') }}">Return to My Privacy</a>
</section>
@endsection
