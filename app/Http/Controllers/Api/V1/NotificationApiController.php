<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationApiController extends Controller
{
    /**
     * List the current user's notifications.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'module' => ['nullable', 'in:SPRS,ASS,TOCS,EERTS,IBMS'],
            'priority' => ['nullable', 'in:Critical,High,Normal,Low'],
        ]);
        $query = $request->user()->notifications()->latest();
        if (! empty($filters['module'])) {
            $query->where('data->module', $filters['module']);
        }
        if (! empty($filters['priority'])) {
            $query->where('data->priority', $filters['priority']);
        }
        $notifications = $query->paginate(15);

        return response()->json([
            'success' => true,
            'message' => 'Notifications retrieved successfully.',
            'data' => $notifications->items(),
            'meta' => ['current_page' => $notifications->currentPage(), 'last_page' => $notifications->lastPage(), 'total' => $notifications->total()],
        ]);
    }

    /**
     * Mark a notification as read.
     */
    public function markRead(string $id): JsonResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($id);

        $notification->markAsRead();

        return response()->json([
            'success' => true,
            'message' => 'Notification marked as read.',
        ]);
    }
}
