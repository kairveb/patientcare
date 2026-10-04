<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\MailFailureLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Throwable;

class EmailVerificationNotificationController extends Controller
{
    /**
     * Send a new email verification notification.
     */
    public function store(Request $request, MailFailureLogger $mailFailureLogger): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false));
        }

        try {
            $request->user()->sendEmailVerificationNotification();
        } catch (Throwable $exception) {
            $mailFailureLogger->recordFailure(
                'email_verification',
                $request->user()->email,
                $exception
            );

            return back()->with('warning', 'We could not send your verification email. Please try again later.');
        }

        return back()->with('status', 'verification-link-sent');
    }
}
