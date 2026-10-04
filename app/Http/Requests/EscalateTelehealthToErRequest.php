<?php

namespace App\Http\Requests;

use App\Models\TelehealthSession;
use Illuminate\Foundation\Http\FormRequest;

class EscalateTelehealthToErRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $session = $this->route('session');

        return $user !== null
            && $user->hasRole('doctor')
            && $session instanceof TelehealthSession
            && $session->appointment?->provider_id === $user->provider?->id;
    }
}
