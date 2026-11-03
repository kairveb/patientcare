@extends('layouts.hims')
@section('title', 'Privacy Request')
@section('page-title', 'Privacy request')
@section('page-description', 'Review your request and any response from the privacy review team.')
@section('content')
<section class="mx-auto max-w-3xl space-y-4 rounded-xl border bg-white p-5">
    <div class="flex flex-wrap justify-between gap-3"><h2 class="font-semibold">{{ $dataSubjectRequest->typeLabel() }}</h2><span class="rounded-full bg-slate-100 px-3 py-1 text-sm">{{ $dataSubjectRequest->status }}</span></div>
    <p><strong>Submitted:</strong> {{ $dataSubjectRequest->created_at->format('M j, Y g:i A') }}</p>
    <div><strong>Your note</strong><p class="mt-1 whitespace-pre-line">{{ $dataSubjectRequest->note }}</p></div>
    @if ($dataSubjectRequest->response_note)
        <div class="rounded-lg bg-teal-50 p-4"><strong>Response</strong><p class="mt-1 whitespace-pre-line">{{ $dataSubjectRequest->response_note }}</p><p class="mt-2 text-xs">Updated {{ $dataSubjectRequest->updated_at->format('M j, Y g:i A') }}</p></div>
    @endif
    @can('review', $dataSubjectRequest)
        @if ($dataSubjectRequest->type === \App\Models\DataSubjectRequest::TYPE_ERASURE)
            <p class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">Clinical records are not hard-deleted through this workflow. Explain whether restriction or anonymization is appropriate and allowed.</p>
        @endif
        <form method="POST" action="{{ route('privacy.requests.review', $dataSubjectRequest) }}" class="space-y-3 border-t pt-4">
            @csrf
            <label class="block text-sm font-medium">Status
                <select name="status" required class="mt-1 block w-full rounded-lg border-slate-300">
                    @if ($dataSubjectRequest->status === \App\Models\DataSubjectRequest::STATUS_SUBMITTED)
                        <option value="IN_REVIEW">In review</option>
                    @endif
                    <option value="COMPLETED">Completed</option>
                    <option value="REJECTED">Rejected</option>
                </select>
            </label>
            <label class="block text-sm font-medium">Response note
                <textarea name="response_note" required minlength="5" maxlength="3000" rows="3" class="mt-1 block w-full rounded-lg border-slate-300">{{ old('response_note') }}</textarea>
            </label>
            @error('status')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            @error('response_note')<p class="text-sm text-red-700">{{ $message }}</p>@enderror
            <button class="rounded-lg bg-teal-700 px-4 py-2 text-sm font-semibold text-white">Save review</button>
        </form>
    @endcan
    <ol class="space-y-2 border-l pl-4">
        @foreach ($dataSubjectRequest->statusHistories as $history)
            <li><strong>{{ $history->to_status }}</strong> · {{ $history->created_at->format('M j, Y g:i A') }} @if($history->note)<p class="text-sm text-slate-600">{{ $history->note }}</p>@endif</li>
        @endforeach
    </ol>
    @if (auth()->user()->isPatient())
        <a href="{{ route('patients.portal.privacy') }}" class="text-teal-800 underline">Back to My Privacy</a>
    @else
        <a href="{{ route('privacy.requests.queue') }}" class="text-teal-800 underline">Back to request queue</a>
    @endif
</section>
@endsection
