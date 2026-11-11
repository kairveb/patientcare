<?php

use App\Http\Controllers\Api\V1\AdmissionApiController;
use App\Http\Controllers\Api\V1\ApiAuthController;
use App\Http\Controllers\Api\V1\AppointmentApiController;
use App\Http\Controllers\Api\V1\BedApiController;
use App\Http\Controllers\Api\V1\BillingApiController;
use App\Http\Controllers\Api\V1\ClinicalDocumentApiController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\Api\V1\DashboardApiController;
use App\Http\Controllers\Api\V1\DepartmentApiController;
use App\Http\Controllers\Api\V1\DischargeApiController;
use App\Http\Controllers\Api\V1\EmergencyApiController;
use App\Http\Controllers\Api\V1\EncounterApiController;
use App\Http\Controllers\Api\V1\EncounterNoteApiController;
use App\Http\Controllers\Api\V1\NotificationApiController;
use App\Http\Controllers\Api\V1\PatientApiController;
use App\Http\Controllers\Api\V1\PhilippineAddressDataController;
use App\Http\Controllers\PatientConsentController;
use App\Http\Controllers\PatientPortalController;
use App\Http\Controllers\Api\V1\ProviderApiController;
use App\Http\Controllers\Api\V1\ProviderScheduleApiController;
use App\Http\Controllers\Api\V1\RoomApiController;
use App\Http\Controllers\Api\V1\ScheduleApiController;
use App\Http\Controllers\Api\V1\TelehealthApiController;
use App\Http\Controllers\Api\V1\TelehealthParticipantApiController;
use App\Http\Controllers\Api\V1\TransferApiController;
use App\Http\Controllers\Api\V1\TriageApiController;
use App\Http\Controllers\Api\V1\WaitlistApiController;
use App\Http\Controllers\Api\V1\WardApiController;
use App\Http\Controllers\SymptomCheckerController;
use App\Http\Controllers\TelehealthController;
use App\Http\Controllers\TelehealthIntakeController;
use App\Http\Controllers\TelehealthReferralController;
use App\Http\Controllers\TriageReassessmentController;
use App\Http\Controllers\OpdVitalsController;
use App\Http\Middleware\TrackInactivity;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureCurrentPrivacyAcceptance;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

/*
|--------------------------------------------------------------------------
| API Routes (v1)
|--------------------------------------------------------------------------
*/

Route::prefix('v1')->group(function () {
    // Public auth
    Route::middleware('throttle:10,1')->group(function () {
        Route::post('/auth/login', [ApiAuthController::class, 'login']);
        Route::post('/auth/verify-otp', [ApiAuthController::class, 'verifyOtp'])
            ->middleware('throttle:10,1');
        Route::post('/auth/resend-otp', [ApiAuthController::class, 'resendOtp'])
            ->middleware('throttle:3,1');
    });

    Route::prefix('/address-data/philippines')->middleware('throttle:600,1')->group(function () {
        Route::get('/regions', [PhilippineAddressDataController::class, 'regions'])
            ->name('api.v1.address-data.regions');
        Route::get('/regions/{region}/provinces', [PhilippineAddressDataController::class, 'provinces'])
            ->name('api.v1.address-data.provinces');
        Route::get('/regions/{region}/cities', [PhilippineAddressDataController::class, 'citiesByRegion'])
            ->name('api.v1.address-data.region-cities');
        Route::get('/provinces/{province}/cities', [PhilippineAddressDataController::class, 'citiesByProvince'])
            ->name('api.v1.address-data.province-cities');
        Route::get('/cities/{city}/sub-municipalities', [PhilippineAddressDataController::class, 'submunicipalities'])
            ->name('api.v1.address-data.submunicipalities');
        Route::get('/cities/{parent}/barangays', [PhilippineAddressDataController::class, 'barangays'])
            ->name('api.v1.address-data.city-barangays');
        Route::get('/sub-municipalities/{parent}/barangays', [PhilippineAddressDataController::class, 'barangays'])
            ->name('api.v1.address-data.submunicipality-barangays');
    });

    Route::middleware(['auth:sanctum', EnsureActiveUser::class, EnsureCurrentPrivacyAcceptance::class])
        ->get('/dashboard', DashboardApiController::class);

    // Authenticated routes
    Route::middleware([
        EnsureFrontendRequestsAreStateful::class,
        'auth:sanctum',
        EnsureActiveUser::class,
        TrackInactivity::class,
        EnsureCurrentPrivacyAcceptance::class,
        'throttle:120,1',
    ])->group(function () {
        Route::post('/auth/logout', [ApiAuthController::class, 'logout'])->name('api.v1.auth.logout');
        Route::get('/auth/me', [ApiAuthController::class, 'me'])->name('api.v1.auth.me');
        Route::post('/privacy/acceptance', [ApiAuthController::class, 'acceptPrivacy'])
            ->middleware('throttle:10,1')
            ->name('api.v1.privacy.acceptance');

        // Patients
        Route::middleware(['can:view-patients', 'throttle:patient-search'])->group(function () {
            Route::post('/patients/lookup/reference-code', [PatientApiController::class, 'lookupReferenceCode'])
                ->middleware(['role:registration,nurse,doctor,super-admin', 'throttle:patient-reference-code']);
            Route::get('/patients', [PatientApiController::class, 'index']);
            Route::get('/patients/{id}', [PatientApiController::class, 'show']);
            Route::get('/patients/search/{term}', [PatientApiController::class, 'search']);
        });
        Route::middleware(['can:create-patients', 'throttle:30,1'])->group(function () {
            Route::post('/patients', [PatientApiController::class, 'store']);
        });
        Route::middleware(['can:update-patients', 'throttle:30,1'])->group(function () {
            Route::put('/patients/{id}', [PatientApiController::class, 'update']);
            Route::patch('/patients/{id}', [PatientApiController::class, 'update']);
        });
        Route::post('/patients/{patient}/merge', [PatientApiController::class, 'merge'])
            ->middleware(['can:update-patients', 'role:registration,super-admin', 'throttle:10,1']);
        Route::middleware(['can:update-patients', 'role:registration'])->group(function () {
            Route::post('/patients/{patient}/identifiers/{identifier}/verify', [PatientController::class, 'verifyIdentifier']);
            Route::post('/patients/{patient}/complete-temporary-identity', [PatientApiController::class, 'completeTemporaryIdentity']);
        });
        Route::middleware(['can:delete-patients', 'throttle:10,1'])->group(function () {
            Route::delete('/patients/{id}', [PatientApiController::class, 'destroy']);
        });
        Route::middleware(['can:portal-dashboard', 'role:patient'])->group(function () {
            Route::get('/me/patient-id', [PatientApiController::class, 'myPatientId'])->middleware('throttle:30,1');
            Route::get('/me/consents', [PatientConsentController::class, 'portalIndex']);
            Route::post('/me/consents', [PatientConsentController::class, 'portalStore']);
            Route::get('/me/discharge-summaries', [PatientPortalController::class, 'dischargeSummariesApi']);
            Route::get('/me/discharge-summaries/{id}', [PatientPortalController::class, 'dischargeSummaryApi'])->whereNumber('id');
            Route::get('/telehealth/{session}/waiting-room', [PatientPortalController::class, 'waitingRoom']);
            Route::post('/me/identity-documents', [ClinicalDocumentApiController::class, 'storeOwnIdentityDocument']);
        });
        Route::middleware(['can:view-patients', 'role:registration,nurse,doctor,super-admin'])->group(function () {
            Route::get('/patients/{patient}/consents', [PatientConsentController::class, 'patientIndex']);
        });
        Route::middleware(['can:create-patients', 'role:registration'])->group(function () {
            Route::post('/patients/{patient}/consents', [PatientConsentController::class, 'patientStore']);
        });

        // Providers / Departments / Schedules
        Route::middleware(['can:view-appointments'])->group(function () {
            Route::get('/providers', [ProviderApiController::class, 'index']);
            Route::get('/providers/{id}', [ProviderApiController::class, 'show']);
            Route::get('/departments', [DepartmentApiController::class, 'index']);
            Route::get('/departments/{id}', [DepartmentApiController::class, 'show']);
            Route::get('/schedules', [ScheduleApiController::class, 'index']);
            Route::get('/schedules/{providerId}/slots', [ScheduleApiController::class, 'slots']);
        });

        // Appointments
        Route::middleware(['can:view-appointments', 'throttle:60,1'])->group(function () {
            Route::get('/appointments', [AppointmentApiController::class, 'index']);
            Route::get('/appointments/{id}', [AppointmentApiController::class, 'show']);
        });
        Route::post('/appointments/{appointment}/vitals', [OpdVitalsController::class, 'store'])
            ->middleware(['can:view-appointments', 'role:nurse', 'throttle:30,1']);
        Route::middleware(['can:create-appointments', 'role:admin,registration,super-admin,patient', 'throttle:30,1'])->group(function () {
            Route::post('/appointments', [AppointmentApiController::class, 'store']);
        });
        Route::middleware(['can:update-appointments', 'role:admin,registration,super-admin', 'throttle:30,1'])->group(function () {
            Route::patch('/appointments/{id}', [AppointmentApiController::class, 'update']);
        });
        Route::middleware(['can:cancel-appointments', 'throttle:30,1'])->group(function () {
            Route::delete('/appointments/{id}', [AppointmentApiController::class, 'destroy']);
            Route::post('/appointments/{id}/cancel', [AppointmentApiController::class, 'cancel']);
            Route::post('/appointments/{id}/reschedule', [AppointmentApiController::class, 'reschedule']);
        });
        Route::middleware(['can:cancel-appointments', 'role:registration,super-admin', 'throttle:30,1'])->group(function () {
            Route::post('/appointments/{id}/check-in', [AppointmentApiController::class, 'checkIn'])
                ->middleware('throttle:patient-reference-workflow');
            Route::post('/appointments/{id}/no-show', [AppointmentApiController::class, 'markNoShow']);
        });
        Route::middleware(['can:view-appointments', 'role:nurse,registration,admin,super-admin', 'throttle:30,1'])
            ->post('/appointments/{appointment}/decision', [AppointmentApiController::class, 'decide'])
            ->name('api.v1.appointments.decision');

        // Encounters
        Route::middleware(['can:viewAny,App\Models\Encounter', 'throttle:encounter-read'])->group(function () {
            Route::get('/encounters', [EncounterApiController::class, 'index']);
            Route::get('/encounters/{id}', [EncounterApiController::class, 'show']);
        });
        Route::middleware(['can:create-encounters', 'role:doctor', 'throttle:30,1'])->group(function () {
            Route::post('/encounters', [EncounterApiController::class, 'store']);
        });
        Route::post('/encounters/{encounter}/prescriptions', [EncounterApiController::class, 'prescribe'])
            ->middleware(['can:update,encounter', 'throttle:30,1']);
        Route::post('/encounters/{encounter}/complete', [EncounterApiController::class, 'complete'])
            ->middleware(['can:complete,encounter', 'role:doctor', 'throttle:20,1']);
        Route::post('/encounters/{encounter}/admission-request', [EncounterApiController::class, 'requestAdmission'])
            ->middleware(['can:update,encounter', 'can:create-admissions', 'role:doctor,super-admin', 'throttle:20,1']);

        // Billing/cashier integrations
        Route::get('/encounters/{encounter}/billing-summary', [BillingApiController::class, 'encounterSummary'])
            ->middleware(['can:view-billing', 'role:super-admin', 'throttle:60,1']);
        Route::get('/admissions/{admission}/billing-summary', [BillingApiController::class, 'admissionSummary'])
            ->middleware(['can:view-billing', 'role:super-admin', 'throttle:60,1']);
        Route::post('/encounters/{encounter}/billing-statements', [BillingApiController::class, 'generateEncounter'])
            ->middleware(['can:manage-billing', 'throttle:20,1']);
        Route::post('/admissions/{admission}/billing-statements', [BillingApiController::class, 'generateAdmission'])
            ->middleware(['can:manage-billing', 'throttle:20,1']);
        Route::post('/billing-statements/{bill}/payment', [BillingApiController::class, 'markPaid'])
            ->middleware(['can:manage-billing', 'throttle:20,1']);
        Route::post('/prescriptions/{prescription}/dispense', [BillingApiController::class, 'dispense'])
            ->middleware(['can:manage-billing', 'throttle:30,1']);

        // Telehealth
        Route::middleware(['can:view-telehealth', 'throttle:60,1'])->group(function () {
            Route::get('/telehealth', [TelehealthApiController::class, 'index']);
            Route::get('/telehealth/{id}', [TelehealthApiController::class, 'show']);
            Route::get('/telehealth/{id}/participants', [TelehealthParticipantApiController::class, 'index']);
        });
        Route::middleware(['can:join-telehealth', 'throttle:30,1'])->group(function () {
            Route::post('/telehealth/{id}/participants', [TelehealthParticipantApiController::class, 'store']);
        });
        Route::middleware(['can:start-telehealth', 'throttle:30,1'])->group(function () {
            Route::post('/telehealth', [TelehealthApiController::class, 'store']);
            Route::post('/telehealth/{session}/face-to-face-referral', [TelehealthReferralController::class, 'store']);
            Route::post('/telehealth/{id}/start', [TelehealthApiController::class, 'start']);
            Route::post('/telehealth/{id}/prescription', [TelehealthApiController::class, 'prescription']);
            Route::post('/telehealth/{id}/reminder', [TelehealthApiController::class, 'reminder']);
            Route::post('/telehealth/{id}/closeout', [TelehealthApiController::class, 'closeout']);
            Route::post('/telehealth/{id}/cancel', [TelehealthApiController::class, 'cancel']);
            Route::post('/telehealth/{id}/end', [TelehealthApiController::class, 'end']);
        });

        // Clinical documents
        Route::middleware(['can:view-patients', 'throttle:patient-search'])->group(function () {
            Route::get('/patients/{patientId}/clinical-documents', [ClinicalDocumentApiController::class, 'index']);
            Route::get('/patients/{patientId}/clinical-documents/{id}', [ClinicalDocumentApiController::class, 'show']);
        });
        Route::middleware('can:create-patients')->group(function () {
            Route::post('/patients/{patientId}/clinical-documents', [ClinicalDocumentApiController::class, 'store']);
        });

        // Encounter notes
        Route::middleware(['can:viewAny,App\Models\Encounter', 'throttle:encounter-read'])->group(function () {
            Route::get('/encounters/{encounterId}/notes', [EncounterNoteApiController::class, 'index']);
        });
        Route::middleware('can:create-encounters')->group(function () {
            Route::post('/encounters/{encounterId}/notes', [EncounterNoteApiController::class, 'store']);
        });

        // Waitlist management
        Route::middleware('can:viewAny,App\Models\Waitlist')->group(function () {
            Route::get('/waitlists', [WaitlistApiController::class, 'index']);
        });
        Route::middleware(['can:create-appointments', 'role:registration,super-admin,patient'])->group(function () {
            Route::post('/waitlists', [WaitlistApiController::class, 'store']);
        });
        Route::middleware(['can:update-appointments', 'role:registration,super-admin,patient'])->group(function () {
            Route::patch('/waitlists/{id}', [WaitlistApiController::class, 'update']);
        });

        // Provider schedules
        Route::middleware('can:view-appointments')->group(function () {
            Route::get('/provider-schedules', [ProviderScheduleApiController::class, 'index']);
            Route::get('/provider-schedules/{id}', [ProviderScheduleApiController::class, 'show']);
        });
        Route::middleware(['can:manage-provider-schedules', 'role:admin,super-admin'])->group(function () {
            Route::post('/provider-schedules', [ProviderScheduleApiController::class, 'store']);
            Route::patch('/provider-schedules/{id}', [ProviderScheduleApiController::class, 'update']);
            Route::post('/provider-schedules/{id}/generate-slots', [ProviderScheduleApiController::class, 'generateSlots']);
            Route::delete('/provider-schedules/{id}', [ProviderScheduleApiController::class, 'destroy']);
        });

        // Emergency
        Route::middleware(['can:view-er', 'throttle:60,1'])->group(function () {
            Route::get('/emergency/queue', [EmergencyApiController::class, 'queue']);
            Route::get('/emergency/visits/{id}', [EmergencyApiController::class, 'showVisit']);
        });
        Route::middleware(['can:create-er-visits', 'throttle:30,1'])->group(function () {
            Route::post('/emergency/visits', [EmergencyApiController::class, 'storeVisit']);
        });
        Route::middleware(['can:create-er-visits', 'role:registration,nurse', 'throttle:30,1'])->group(function () {
            Route::post('/emergency/visits/unknown', [EmergencyApiController::class, 'storeUnknownVisit']);
        });
        Route::middleware(['can:triage-patients', 'throttle:30,1'])->group(function () {
            Route::post('/triage/score', [TriageApiController::class, 'score']);
            Route::post('/emergency/{id}/triage', [TriageApiController::class, 'store'])->middleware('role:nurse,doctor,super-admin');
        });
        Route::middleware(['role:patient,registration,super-admin', 'throttle:30,1'])->group(function () {
            Route::post('/symptom-checks', [SymptomCheckerController::class, 'store']);
            Route::get('/symptom-checks/{symptomCheck}', [SymptomCheckerController::class, 'show']);
            Route::get('/symptom-checks/{symptomCheck}/emergency', [SymptomCheckerController::class, 'emergency']);
        });
        Route::middleware(['can:triage-patients', 'role:nurse', 'throttle:30,1'])
            ->post('/emergency/visits/{visit}/reassessments', [TriageReassessmentController::class, 'store']);
        Route::middleware(['can:triage-patients', 'role:nurse,doctor', 'throttle:30,1'])
            ->post('/emergency/visits/{visit}/reassessments/{reassessment}/confirm', [TriageReassessmentController::class, 'confirm']);
        Route::middleware(['role:doctor', 'throttle:10,1'])
            ->post('/telehealth/{session}/escalate-to-er', [TelehealthController::class, 'escalateToEr']);
        Route::middleware(['role:patient,doctor,nurse', 'throttle:30,1'])->group(function () {
            Route::get('/telehealth/{session}/intake', [TelehealthIntakeController::class, 'show']);
            Route::get('/telehealth/{session}/ai-summary', [TelehealthIntakeController::class, 'showSummary']);
        });
        Route::middleware(['can:portal-dashboard', 'role:patient', 'throttle:30,1'])->group(function () {
            Route::post('/telehealth/{session}/intake', [TelehealthIntakeController::class, 'store']);
            Route::get('/telehealth/{session}/emergency-instructions', [TelehealthIntakeController::class, 'emergencyInstructions']);
        });
        Route::middleware(['can:start-telehealth', 'role:doctor', 'throttle:30,1'])->group(function () {
            Route::post('/telehealth/{session}/ai-summary', [TelehealthIntakeController::class, 'generate']);
            Route::patch('/telehealth/{session}/ai-summary', [TelehealthIntakeController::class, 'update']);
            Route::post('/telehealth/{session}/ai-summary/approve', [TelehealthIntakeController::class, 'approve']);
        });

        // Wards / Rooms / Beds
        Route::middleware(['can:view-beds', 'throttle:60,1'])->group(function () {
            Route::get('/wards', [WardApiController::class, 'index']);
            Route::get('/rooms', [RoomApiController::class, 'index']);
            Route::get('/beds', [BedApiController::class, 'index']);
            Route::get('/beds/available', [BedApiController::class, 'available']);
            Route::get('/beds/{id}', [BedApiController::class, 'show']);
        });
        Route::middleware(['can:update-bed-status', 'role:nurse,super-admin', 'throttle:30,1'])->group(function () {
            Route::patch('/beds/{id}/status', [BedApiController::class, 'updateStatus']);
        });
        Route::middleware(['can:assign-beds', 'role:registration,super-admin', 'throttle:30,1'])->group(function () {
            Route::post('/beds/{id}/reserve', [BedApiController::class, 'reserve']);
            Route::post('/beds/{id}/reservations/{reservationId}/cancel', [BedApiController::class, 'cancelReservation']);
            Route::post('/beds/{id}/assign', [BedApiController::class, 'assign']);
            Route::post('/beds/{id}/release', [BedApiController::class, 'release']);
        });
        Route::middleware(['can:transfer-patients', 'role:nurse,super-admin', 'throttle:30,1'])->group(function () {
            Route::post('/admissions/{id}/transfer', [TransferApiController::class, 'store']);
        });

        // Admissions
        Route::middleware(['can:view-admissions', 'throttle:60,1'])->group(function () {
            Route::get('/admissions', [AdmissionApiController::class, 'index']);
            Route::get('/admissions/{id}', [AdmissionApiController::class, 'show']);
        });
        Route::post('/admissions/{admission}/billing-clearance-request', [AdmissionApiController::class, 'requestBillingClearance'])
            ->middleware(['can:view-admissions', 'role:nurse,registration', 'throttle:20,1']);
        Route::patch('/admissions/{admission}/guarantee-details', [AdmissionApiController::class, 'updateGuaranteeDetails'])
            ->middleware(['can:assign-beds', 'role:registration,super-admin', 'throttle:30,1']);
        Route::middleware(['can:create-admissions', 'role:doctor,super-admin', 'throttle:30,1'])->group(function () {
            Route::post('/admissions', [AdmissionApiController::class, 'store']);
        });
        Route::middleware(['can:execute-discharges', 'role:nurse,super-admin', 'throttle:30,1'])
            ->post('/admissions/{id}/discharge', [DischargeApiController::class, 'store']);
        Route::middleware(['can:view-payment-status', 'role:doctor,nurse,super-admin', 'throttle:60,1'])
            ->get('/admissions/{admission}/payment-status', [BillingApiController::class, 'admissionPaymentStatus']);

        // Notifications
        Route::middleware('throttle:60,1')->group(function () {
            Route::get('/notifications', [NotificationApiController::class, 'index']);
            Route::post('/notifications/{id}/read', [NotificationApiController::class, 'markRead']);
        });
    });
});
