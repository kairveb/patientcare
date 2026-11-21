<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\ErVisit;
use App\Models\TransportRequest;
use App\Models\User;
use App\Services\TransportRequestService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class TransportRequestController extends Controller
{
    public function storeForEr(Request $request, ErVisit $visit, TransportRequestService $transports): RedirectResponse
    {
        $this->authorizeTransportMutation($request, 'er_visit');
        $validated = $this->validated($request, 'er_visit', $visit->id);
        $transports->create($validated, $request->user());

        return back()->with('success', 'Transport request recorded.');
    }

    public function storeForAdmission(Request $request, Admission $admission, TransportRequestService $transports): RedirectResponse
    {
        $this->authorizeTransportMutation($request, 'admission');
        $validated = $this->validated($request, 'admission', $admission->id);
        $transports->create($validated, $request->user());

        return back()->with('success', 'Transport request recorded.');
    }

    public function storeApi(Request $request, TransportRequestService $transports): JsonResponse
    {
        $validated = $request->validate([
            'source_type' => ['required', Rule::in(['er_visit', 'admission'])],
            'source_id' => ['required', 'integer', 'min:1'],
            'priority' => ['required_if:source_type,admission', 'nullable', Rule::in(['EMERGENCY', 'URGENT', 'ROUTINE'])],
            'pickup_zone' => ['required', Rule::in(array_keys(config('integrations.zones', [])))],
            'destination_zone' => ['required', Rule::in(array_keys(config('integrations.zones', [])))],
            'needs' => ['nullable', 'array:stretcher_required,oxygen_support,wheelchair'],
            'needs.stretcher_required' => ['sometimes', 'boolean'],
            'needs.oxygen_support' => ['sometimes', 'boolean'],
            'needs.wheelchair' => ['sometimes', 'boolean'],
            'passenger_count' => ['nullable', 'integer', 'between:1,20'],
        ]);
        $this->authorizeTransportMutation($request, $validated['source_type']);
        $this->assertSourceExists($validated['source_type'], $validated['source_id']);
        $transport = $transports->create($validated, $request->user());

        return response()->json(['success' => true, 'data' => $this->resource($transport)], 201);
    }

    public function indexApi(Request $request): JsonResponse
    {
        Gate::authorize('viewAny', TransportRequest::class);
        $transports = TransportRequest::query()
            ->where(function (Builder $query) use ($request): void {
                if ($request->user()->hasPermission('view-er')) {
                    $query->orWhere('source_type', 'er_visit');
                }
                if ($request->user()->hasPermission('view-admissions')) {
                    $query->orWhere('source_type', 'admission');
                }
            })
            ->latest()
            ->paginate(25);

        return response()->json([
            'success' => true,
            'data' => $transports->getCollection()->map(fn (TransportRequest $item) => $this->resource($item)),
            'meta' => ['current_page' => $transports->currentPage(), 'last_page' => $transports->lastPage()],
        ]);
    }

    public function showApi(TransportRequest $transportRequest): JsonResponse
    {
        Gate::authorize('view', $transportRequest);

        return response()->json(['success' => true, 'data' => $this->resource($transportRequest)]);
    }

    public function cancelWeb(Request $request, TransportRequest $transportRequest, TransportRequestService $transports): RedirectResponse
    {
        $this->authorizeTransportMutation($request, $transportRequest->source_type);
        Gate::authorize('cancel', $transportRequest);
        $data = $request->validate(['cancelled_reason' => ['nullable', 'string', 'max:500']]);
        $transports->cancel($transportRequest, $request->user(), $data['cancelled_reason'] ?? null);

        return back()->with('success', 'Transport request cancelled.');
    }

    public function cancelApi(Request $request, TransportRequest $transportRequest, TransportRequestService $transports): JsonResponse
    {
        $this->authorizeTransportMutation($request, $transportRequest->source_type);
        Gate::authorize('cancel', $transportRequest);
        $data = $request->validate(['cancelled_reason' => ['nullable', 'string', 'max:500']]);
        $transport = $transports->cancel($transportRequest, $request->user(), $data['cancelled_reason'] ?? null);

        return response()->json(['success' => true, 'data' => $this->resource($transport)]);
    }

    private function validated(Request $request, string $sourceType, int $sourceId): array
    {
        $data = $request->validate([
            'priority' => [$sourceType === 'admission' ? 'required' : 'nullable', Rule::in(['EMERGENCY', 'URGENT', 'ROUTINE'])],
            'pickup_zone' => ['required', Rule::in(array_keys(config('integrations.zones', [])))],
            'destination_zone' => ['required', Rule::in(array_keys(config('integrations.zones', [])))],
            'needs' => ['nullable', 'array:stretcher_required,oxygen_support,wheelchair'],
            'needs.stretcher_required' => ['sometimes', 'boolean'],
            'needs.oxygen_support' => ['sometimes', 'boolean'],
            'needs.wheelchair' => ['sometimes', 'boolean'],
            'passenger_count' => ['nullable', 'integer', 'between:1,20'],
        ]);

        return $data + ['source_type' => $sourceType, 'source_id' => $sourceId];
    }

    private function assertSourceExists(string $sourceType, int $sourceId): void
    {
        ($sourceType === 'er_visit' ? ErVisit::class : Admission::class)::query()->findOrFail($sourceId);
    }

    private function authorizeTransportMutation(Request $request, string $sourceType): void
    {
        $user = $request->user();
        $sourcePermission = $sourceType === 'er_visit' ? 'view-er' : 'view-admissions';
        abort_unless(
            $user instanceof User
                && $user->hasAnyRole(['nurse', 'registration', 'super-admin'])
                && $user->hasPermission('request-patient-transport')
                && $user->hasPermission($sourcePermission),
            403
        );
    }

    private function resource(TransportRequest $transport): array
    {
        return [
            'id' => $transport->id,
            'source_type' => $transport->source_type,
            'priority' => $transport->priority,
            'pickup_zone' => $transport->pickup_zone,
            'destination_zone' => $transport->destination_zone,
            'needs' => $transport->needs,
            'passenger_count' => $transport->passenger_count,
            'status' => $transport->status,
            'vehicle_ref' => $transport->vehicle_ref,
            'eta_at' => $transport->eta_at?->toIso8601String(),
            'status_updated_at' => $transport->status_updated_at?->toIso8601String(),
            'cancelled_reason' => $transport->cancelled_reason,
        ];
    }
}
