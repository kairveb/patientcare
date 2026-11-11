<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ConfidentialityAcknowledgementController extends Controller
{
    public function show(): View
    {
        return view('legal.confidentiality');
    }

    public function store(Request $request, AuditLogService $audit): RedirectResponse|JsonResponse
    {
        $request->validate(['acknowledged' => ['required', 'accepted']]);
        $version = (string) config('privacy.confidentiality_notice_version', '1.0');
        $alreadyAcknowledged = $request->user()->auditLogs()
            ->where('action', 'CONFIDENTIALITY_NOTICE_ACKNOWLEDGED')
            ->where('metadata->version', $version)
            ->exists();

        if (! $alreadyAcknowledged) {
            $audit->log('CONFIDENTIALITY_NOTICE_ACKNOWLEDGED', 'privacy', (int) $request->user()->id, 'SUCCESS', [
                'version' => $version,
                'acknowledged_at' => now()->toIso8601String(),
            ], (int) $request->user()->id);
        }

        if ($request->is('api/*')) {
            return response()->json([
                'success' => true,
                'message' => 'Confidentiality notice acknowledged.',
                'data' => ['version' => $version],
            ]);
        }

        return redirect()->intended(route('dashboard'));
    }
}
