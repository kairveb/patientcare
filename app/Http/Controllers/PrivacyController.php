<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;

class PrivacyController extends Controller
{
    public function notice(): View
    {
        return view('legal.privacy', [
            'version' => config('privacy.notice_version'),
            'effectiveDate' => config('privacy.effective_date'),
            'dpo' => config('privacy.dpo'),
        ]);
    }

    public function terms(): View
    {
        return view('legal.terms', [
            'version' => config('privacy.terms_version'),
            'effectiveDate' => config('privacy.effective_date'),
            'dpo' => config('privacy.dpo'),
        ]);
    }
}
