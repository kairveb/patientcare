<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
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

        return view('notifications.index', [
            'notifications' => $query->paginate(20)->withQueryString(),
            'filters' => $filters,
        ]);
    }

    public function markRead(Request $request, string $notification): RedirectResponse
    {
        $request->user()->notifications()->findOrFail($notification)->markAsRead();

        return back();
    }
}
