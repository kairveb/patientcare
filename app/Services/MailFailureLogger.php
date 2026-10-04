<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Throwable;

class MailFailureLogger
{
    public function recordFailure(string $operation, ?string $email, Throwable $exception): void
    {
        $recipientHash = filled($email)
            ? hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'))
            : null;

        Log::warning('Email delivery failed.', [
            'operation' => $operation,
            'recipient_hash' => $recipientHash,
            'exception' => $exception::class,
        ]);
    }
}
