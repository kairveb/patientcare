<?php

namespace App\Http\Requests;

use App\Models\TelehealthSession;
use Illuminate\Foundation\Http\FormRequest;

class StoreTelehealthReferralRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $session = $this->route('session');

        return $user !== null
            && $user->hasRole('doctor')
            && $user->hasPermission('start-telehealth')
            && $session instanceof TelehealthSession
            && $user->provider?->id === $session->appointment?->provider_id;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
        ];
    }
}
