<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Jobs\DeliverIntegrationOutbox;
use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationPartner;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class IntegrationAdminApiController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', IntegrationPartner::class);

        return response()->json([
            'success' => true,
            'data' => [
                'partners' => IntegrationPartner::query()->orderBy('code')->get(),
                'deliveries' => IntegrationOutbox::query()->with('partner')->latest()->limit(50)->get(),
                'logs' => IntegrationLog::query()->latest()->limit(50)->get(),
            ],
        ]);
    }

    public function retry(IntegrationOutbox $outbox): JsonResponse
    {
        Gate::authorize('retry', $outbox);
        abort_unless(in_array($outbox->status, ['FAILED', 'DEAD'], true), 409, 'Only failed or dead deliveries can be retried.');

        $outbox->update([
            'status' => 'PENDING',
            'attempts' => 0,
            'next_retry_at' => null,
            'last_error' => null,
        ]);
        DeliverIntegrationOutbox::dispatch($outbox->id)->afterCommit();

        return response()->json(['success' => true, 'data' => ['status' => 'PENDING']]);
    }
}
