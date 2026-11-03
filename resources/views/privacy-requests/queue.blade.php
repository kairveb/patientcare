@extends('layouts.hims')
@section('title', 'Privacy Request Queue')
@section('page-title', 'Data subject request queue')
@section('page-description', 'Review patient requests for access, correction, restriction, objection, or erasure.')
@section('content')
<div class="space-y-3">
    @foreach ($requests as $privacyRequest)
        <article class="rounded-xl border bg-white p-4">
            <div class="flex flex-wrap justify-between gap-3">
                <div><h2 class="font-semibold">{{ $privacyRequest->patient?->full_name }} · {{ $privacyRequest->typeLabel() }}</h2><p class="text-sm text-slate-600">{{ $privacyRequest->status }} · {{ $privacyRequest->created_at->format('M j, Y g:i A') }}</p><p class="mt-2 text-sm">{{ $privacyRequest->note }}</p></div>
                <a href="{{ route('privacy.requests.staff.show', $privacyRequest) }}" class="self-start rounded-lg border px-3 py-2 text-sm">Review</a>
            </div>
            @if ($privacyRequest->type === \App\Models\DataSubjectRequest::TYPE_ERASURE)
                <p class="mt-3 rounded-lg bg-amber-50 p-3 text-sm text-amber-900">Clinical records are not hard-deleted through this workflow. Explain whether restriction or anonymization is appropriate and allowed, or state why the request cannot be completed.</p>
            @endif
        </article>
    @endforeach
    {{ $requests->links() }}
</div>
@endsection
