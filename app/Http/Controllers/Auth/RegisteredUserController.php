<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\PatientConsentService;
use App\Services\PatientService;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'privacy_notice_accepted' => ['accepted'],
            'terms_accepted' => ['accepted'],
            'password' => [
                'required',
                'confirmed',
                Rules\Password::min(8)->letters()->mixedCase()->numbers()->symbols(),
            ],
        ], [
            'password.min' => 'Password must be at least 8 characters.',
            'password.letters' => 'Password must include at least one letter.',
            'password.mixed_case' => 'Password must include both upper and lower case letters.',
            'password.numbers' => 'Password must include at least one number.',
            'password.symbols' => 'Password must include at least one symbol.',
        ]);

        $nameParts = preg_split('/\s+/', trim($request->name));
        $firstName = $nameParts[0] ?? '';
        $lastName = $nameParts[count($nameParts) - 1] ?? '';
        [$user] = DB::transaction(function () use ($request, $firstName, $lastName): array {
            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->password),
            ]);
            $patientRole = Role::where('name', 'patient')->first();
            if ($patientRole) {
                $user->roles()->syncWithoutDetaching([$patientRole->id]);
            }

            $patient = app(PatientService::class)->register([
                'user_id' => $user->id,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'date_of_birth' => now()->subYears(18)->toDateString(),
                'sex' => 'Not specified',
                'email' => $user->email,
                'lookup_code' => strtoupper(Str::random(8)),
                'pre_registration_status' => 'not_started',
                'verified' => false,
            ], $user->id);
            $consents = app(PatientConsentService::class);
            $consents->record($patient, 'DATA_PRIVACY', true, $user, 'self');
            $consents->record($patient, 'TERMS_OF_USE', true, $user, 'self');
            app(AuditLogService::class)->log('REGISTER_PATIENT_ACCOUNT', 'patient', $patient->id, 'SUCCESS', [
                'privacy_version' => config('privacy.notice_version'),
                'terms_version' => config('privacy.terms_version'),
            ], $user->id);

            return [$user];
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect()->route('dashboard');
    }
}
