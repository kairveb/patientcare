<?php

namespace App\Http\Controllers;

use App\Services\QueueDisplayService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

class PublicQueueDisplayController extends Controller
{
    public function show(QueueDisplayService $queue): Response
    {
        return response()
            ->view('queue.display', ['queue' => $queue->publicBoard()])
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate')
            ->header('X-Content-Type-Options', 'nosniff');
    }

    public function data(QueueDisplayService $queue): JsonResponse
    {
        return response()->json($queue->publicBoard())
            ->header('Cache-Control', 'no-store, private')
            ->header('X-Content-Type-Options', 'nosniff');
    }
}
