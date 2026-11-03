@extends('layouts.hims')

@section('title', 'Follow-up Questions')

@section('content')
<div class="mx-auto max-w-3xl space-y-6">
    <section class="rounded-xl border border-slate-200 bg-white p-6">
        <h1 class="text-2xl font-bold text-slate-800">A few follow-up questions</h1>
        <p class="mt-2 text-sm text-slate-600">Your answers help the symptom checker provide a more useful care-routing suggestion. This is not a medical diagnosis.</p>

        @if ($errors->any())
            <div role="alert" class="mt-4 rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-800">
                <ul class="list-inside list-disc">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
            </div>
        @endif

        <form method="POST" action="{{ route('symptom-checker.follow-up') }}" class="mt-5 space-y-4">
            @csrf
            <input type="hidden" name="state" value="{{ $state }}">
            @foreach ($questions as $index => $question)
                <div>
                    <label for="follow-up-{{ $index }}" class="mb-1 block text-sm font-medium text-slate-700">{{ $question }}</label>
                    <textarea id="follow-up-{{ $index }}" name="answers[{{ $index }}]" rows="2" required maxlength="1000" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm"></textarea>
                </div>
            @endforeach
            <button type="submit" class="rounded-lg bg-teal-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-teal-800">Continue</button>
        </form>
    </section>
</div>
@endsection
