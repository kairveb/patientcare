<?php

use App\Http\Controllers\AppointmentController;
use App\Http\Controllers\AppointmentReassignmentController;
use App\Http\Controllers\AdminUserController;
use App\Http\Controllers\ArrivalCheckInController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\BillingStatementController;
use App\Http\Controllers\CareRequestController;
use App\Http\Controllers\BedSetupController;
use App\Http\Controllers\ChargeRateController;
use App\Http\Controllers\Api\V1\ClinicalDocumentApiController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DoctorDirectoryController;
use App\Http\Controllers\DischargeSummaryController;
use App\Http\Controllers\DoctorQueueController;
use App\Http\Controllers\EmergencyController;
use App\Http\Controllers\EncounterController;
use App\Http\Controllers\InpatientController;
use App\Http\Controllers\MfaController;
use App\Http\Controllers\OpdVitalsController;
use App\Http\Controllers\PatientController;
use App\Http\Controllers\PatientConsentController;
use App\Http\Controllers\PrivacyAcceptanceController;
use App\Http\Controllers\PrivacyController;
use App\Http\Controllers\PrivacyPortalController;
use App\Http\Controllers\PatientPortalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicQueueDisplayController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\ReportExportController;
use App\Http\Controllers\AiEvaluationController;
use App\Http\Controllers\SymptomCheckerController;
use App\Http\Controllers\TelehealthController;
use App\Http\Controllers\TelehealthIntakeController;
use App\Http\Controllers\TelehealthReferralController;
use App\Http\Controllers\TriageAssessmentController;
use App\Http\Controllers\TriageReassessmentController;
use App\Http\Middleware\TrackInactivity;
use App\Http\Middleware\EnsureActiveUser;
use App\Http\Middleware\EnsureCurrentPrivacyAcceptance;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});
Route::get('/privacy', [PrivacyController::class, 'notice'])->name('privacy.notice');
Route::get('/terms', [PrivacyController::class, 'terms'])->name('privacy.terms');

Route::get('/queue/display', [PublicQueueDisplayController::class, 'show'])
    ->name('queue.display');
Route::get('/queue/display/data', [PublicQueueDisplayController::class, 'data'])
    ->middleware('throttle:30,1')
    ->name('queue.display.data');

Route::middleware(['auth', EnsureActiveUser::class, 'verified', EnsureCurrentPrivacyAcceptance::class])->group(function () {
    Route::get('/privacy/acceptance', [PrivacyAcceptanceController::class, 'show'])->name('privacy.acceptance');
    Route::post('/privacy/acceptance', [PrivacyAcceptanceController::class, 'store'])->name('privacy.acceptance.store');
    Route::post('/heartbeat', function () {
        return response()->noContent();
    })->name('heartbeat')->middleware(TrackInactivity::class);

    Route::get('/dashboard', DashboardController::class)->name('dashboard');

    Route::middleware(['can:portal-dashboard', 'role:patient'])->group(function () {
        Route::get('patient-portal', [PatientPortalController::class, 'dashboard'])->name('patients.portal');
        Route::get('patient-portal/patient-id', [PatientPortalController::class, 'patientId'])->name('patients.portal.patient-id');
        Route::get('patient-portal/appointments', [PatientPortalController::class, 'appointments'])->name('patients.portal.appointments');
        Route::get('patient-portal/privacy', [PrivacyPortalController::class, 'index'])->name('patients.portal.privacy');
        Route::post('patient-portal/privacy/requests', [PrivacyPortalController::class, 'storeRequest'])
            ->middleware('throttle:10,1')->name('patients.portal.privacy.requests.store');
        Route::get('patient-portal/privacy/requests/{dataSubjectRequest}', [PrivacyPortalController::class, 'showRequest'])->name('privacy.requests.show');
        Route::get('patient-portal/privacy/export.json', [PrivacyPortalController::class, 'jsonCopy'])->middleware('throttle:5,1')->name('patients.portal.privacy.export.json');
        Route::get('patient-portal/privacy/export/print', [PrivacyPortalController::class, 'printableCopy'])->middleware('throttle:patient-data-print')->name('patients.portal.privacy.export.print');
        Route::get('doctors', [DoctorDirectoryController::class, 'index'])->name('doctors.index');
        Route::get('doctors/{provider}', [DoctorDirectoryController::class, 'show'])
            ->whereNumber('provider')
            ->name('doctors.show');
        Route::get('doctors/{provider}/calendar', [DoctorDirectoryController::class, 'calendar'])
            ->middleware('throttle:60,1')->name('doctors.calendar');
        Route::post('doctor-waitlist', [DoctorDirectoryController::class, 'joinWaitlist'])
            ->middleware('throttle:10,1')->name('doctors.waitlist');
        Route::get('patient-portal/approvals', [PatientPortalController::class, 'approvals'])->middleware('can:view-care-requests')->name('patients.portal.approvals');
        Route::get('patient-portal/approvals/{careRequest}', [PatientPortalController::class, 'approval'])->middleware('can:view-care-requests')->name('patients.portal.approval');
        Route::get('patient-portal/history', [PatientPortalController::class, 'history'])->name('patients.portal.history');
        Route::get('patient-portal/discharge-summaries', [PatientPortalController::class, 'dischargeSummaries'])->name('patients.portal.discharge-summaries');
        Route::get('patient-portal/telehealth', [PatientPortalController::class, 'telehealth'])->name('patients.portal.telehealth');
        Route::get('patient-portal/telehealth/{session}/waiting-room', [PatientPortalController::class, 'waitingRoom'])->name('patients.portal.telehealth.waiting-room');
        Route::get('patient-portal/telehealth/{session}/intake', [TelehealthIntakeController::class, 'create'])->name('patients.portal.telehealth.intake');
        Route::post('patient-portal/telehealth/{session}/intake', [TelehealthIntakeController::class, 'store'])->name('patients.portal.telehealth.intake.store');
        Route::get('patient-portal/telehealth/{session}/emergency-instructions', [TelehealthIntakeController::class, 'emergencyInstructions'])->name('patients.portal.telehealth.emergency');
        Route::get('patient-portal/consents', [PatientConsentController::class, 'portalIndex'])->name('patient-consents.portal');
        Route::post('patient-portal/consents', [PatientConsentController::class, 'portalStore'])->name('patient-consents.portal.store');
    });

    Route::middleware('role:registration,admin,super-admin')->group(function () {
        Route::get('privacy-requests', [PrivacyPortalController::class, 'queue'])->name('privacy.requests.queue');
        Route::get('privacy-requests/{dataSubjectRequest}', [PrivacyPortalController::class, 'showRequest'])->name('privacy.requests.staff.show');
        Route::post('privacy-requests/{dataSubjectRequest}/review', [PrivacyPortalController::class, 'review'])
            ->middleware('throttle:20,1')->name('privacy.requests.review');
    });

    Route::middleware('role:patient')->group(function () {
        Route::get('patient-portal/reassignments', [AppointmentReassignmentController::class, 'patientIndex'])->name('patient.reassignments.index');
        Route::get('patient-portal/reassignments/{reassignment}', [AppointmentReassignmentController::class, 'patientShow'])->name('patient.reassignments.show');
        Route::post('patient-portal/reassignments/{reassignment}/respond', [AppointmentReassignmentController::class, 'patientRespond'])
            ->middleware('throttle:20,1')
            ->name('patient.reassignments.respond');
    });

    Route::middleware('role:doctor,nurse,registration,admin,super-admin')->group(function () {
        Route::get('provider-unavailabilities/create', [AppointmentReassignmentController::class, 'unavailabilityForm'])
            ->name('provider-unavailabilities.create');
        Route::post('provider-unavailabilities', [AppointmentReassignmentController::class, 'storeUnavailability'])
            ->middleware('throttle:20,1')
            ->name('provider-unavailabilities.store');
    });

    Route::middleware('role:registration,admin,super-admin')->group(function () {
        Route::get('appointment-reassignments', [AppointmentReassignmentController::class, 'index'])
            ->name('appointment-reassignments.index');
        Route::post('appointment-reassignments/{reassignment}/decide', [AppointmentReassignmentController::class, 'decide'])
            ->middleware('throttle:30,1')
            ->name('appointment-reassignments.decide');
    });

    Route::middleware(['role:patient'])->group(function () {
        Route::get('patients/profile', [PatientController::class, 'profile'])->name('patients.profile');
        Route::post('patients/profile', [PatientController::class, 'saveProfile'])->name('patients.profile.save');
        Route::post('patient-portal/identity-documents', [ClinicalDocumentApiController::class, 'storeOwnIdentityDocument'])->name('patients.profile.identity-documents.store');
    });
    Route::middleware(['can:view-patients', 'role:registration,super-admin', 'throttle:patient-search'])->group(function () {
        Route::get('patients/lookup', [PatientController::class, 'lookup'])
            ->name('patients.lookup');
    });

    // Patients
    Route::middleware('can:view-patients')->group(function () {
        Route::resource('patients', PatientController::class)->except(['edit', 'update', 'destroy']);
        Route::get('patients/vitals/{patient}', [PatientController::class, 'showVitals'])->name('patients.vitals');
    });
    Route::middleware(['can:view-patients', 'role:registration,nurse,doctor,super-admin'])
        ->get('patients/{patient}/consents', [PatientConsentController::class, 'patientIndex'])
        ->name('patients.consents.index');
    Route::middleware(['can:view-patients', 'role:registration,nurse,doctor,super-admin', 'throttle:patient-reference-code'])
        ->post('patients/find-by-prc', [PatientController::class, 'findByReferenceCode'])
        ->name('patients.find-by-prc');
    Route::middleware(['can:view-patients', 'role:registration,super-admin'])
        ->get('patients/{patientId}/clinical-documents/{id}', [ClinicalDocumentApiController::class, 'show'])
        ->name('patients.clinical-documents.show');
    Route::middleware(['can:create-patients', 'role:registration'])
        ->post('patients/{patient}/consents', [PatientConsentController::class, 'patientStore'])
        ->name('patients.consents.store');
    Route::middleware(['can:update-patients', 'role:registration'])
        ->post('patients/{patient}/identifiers/{identifier}/verify', [PatientController::class, 'verifyIdentifier'])
        ->name('patients.identifiers.verify');
    Route::middleware(['can:update-patients', 'role:registration'])
        ->post('patients/{patient}/complete-temporary-identity', [PatientController::class, 'completeTemporaryIdentity'])
        ->name('patients.temporary-identity.complete');
    Route::middleware('can:create-patients')->group(function () {
        Route::post('patients/{patient}/verify', [PatientController::class, 'verify'])->name('patients.verify');
    });
    Route::post('patients/{patient}/merge', [PatientController::class, 'merge'])
        ->middleware(['can:update-patients', 'role:registration,super-admin'])
        ->name('patients.merge');

    // Appointments
    Route::middleware(['role:admin,registration,nurse,super-admin,patient'])->group(function () {
        Route::get('appointments/create', [AppointmentController::class, 'create'])->name('appointments.create');
        Route::post('appointments', [AppointmentController::class, 'store'])->name('appointments.store');
    });
    Route::middleware(['can:view-appointments', 'role:registration,nurse,admin,super-admin'])->group(function () {
        Route::get('appointments/approvals', [AppointmentController::class, 'approvalQueue'])->name('appointments.approvals');
        Route::post('appointments/{appointment}/decision', [AppointmentController::class, 'decide'])->name('appointments.decide');
    });
    Route::middleware(['role:registration,nurse,admin,super-admin'])->group(function () {
        Route::get('provider-profiles', [DoctorDirectoryController::class, 'profileIndex'])->name('providers.profiles');
        Route::get('providers/{provider}/profile/edit', [DoctorDirectoryController::class, 'editProfile'])->name('providers.profile.edit');
        Route::put('providers/{provider}/profile', [DoctorDirectoryController::class, 'updateProfile'])->name('providers.profile.update');
    });
    Route::post('encounters/{encounter}/follow-up', [EncounterController::class, 'requestFollowUp'])
        ->middleware(['role:doctor', 'throttle:20,1'])->name('encounters.follow-up');
    Route::middleware('role:patient,registration,super-admin')->group(function () {
        Route::get('symptom-checker', [SymptomCheckerController::class, 'create'])->name('symptom-checker.create');
        Route::post('symptom-checker', [SymptomCheckerController::class, 'store'])
            ->middleware('throttle:symptom-triage')
            ->name('symptom-checker.store');
        Route::post('symptom-checker/follow-up', [SymptomCheckerController::class, 'followUp'])
            ->middleware('throttle:symptom-triage')
            ->name('symptom-checker.follow-up');
        Route::get('symptom-checker/{symptomCheck}/er', [SymptomCheckerController::class, 'emergency'])->name('symptom-checker.emergency');
        Route::get('symptom-checker/{symptomCheck}', [SymptomCheckerController::class, 'show'])->name('symptom-checker.show');
    });
    Route::middleware(['can:create-appointments', 'role:admin,registration,super-admin'])->group(function () {
        Route::post('appointments/{appointment}/check-in', [AppointmentController::class, 'checkIn'])
            ->middleware('throttle:patient-reference-workflow')
            ->name('appointments.check-in');
    });
    Route::middleware('can:view-appointments')->group(function () {
        Route::get('appointments', [AppointmentController::class, 'index'])->name('appointments.index');
        Route::get('appointments/{appointment}', [AppointmentController::class, 'show'])->name('appointments.show');
        Route::get('appointments/slots/json', [AppointmentController::class, 'slots'])->name('appointments.slots');
    });
    Route::post('appointments/{appointment}/vitals', [OpdVitalsController::class, 'store'])
        ->middleware(['can:view-appointments', 'role:nurse', 'throttle:30,1'])
        ->name('appointments.vitals.store');
    Route::middleware('can:cancel-appointments')->group(function () {
        Route::post('appointments/{appointment}/cancel', [AppointmentController::class, 'cancel'])->name('appointments.cancel');
        Route::post('appointments/{appointment}/reschedule', [AppointmentController::class, 'reschedule'])->name('appointments.reschedule');
    });
    Route::middleware(['can:cancel-appointments', 'role:registration,super-admin'])->group(function () {
        Route::post('appointments/{appointment}/no-show', [AppointmentController::class, 'markNoShow'])->name('appointments.no-show');
    });

    // Outpatient / Encounters
    Route::middleware(['can:create-encounters', 'role:doctor'])->group(function () {
        Route::get('encounters/create', [EncounterController::class, 'create'])->name('encounters.create');
        Route::post('encounters', [EncounterController::class, 'store'])->name('encounters.store');
    });
    Route::middleware('can:view-encounters')->group(function () {
        Route::get('outpatient', [EncounterController::class, 'index'])->name('outpatient.index');
        Route::get('encounters', [EncounterController::class, 'index'])->name('encounters.index');
        Route::get('encounters/{encounter}', [EncounterController::class, 'show'])->name('encounters.show');
    });
    Route::post('encounters/{encounter}/care-requests', [CareRequestController::class, 'store'])
        ->middleware(['can:create-care-requests', 'role:doctor', 'throttle:20,1'])
        ->name('encounters.care-requests.store');
    Route::middleware(['can:view-care-requests', 'role:doctor,nurse,registration,super-admin,patient'])->group(function () {
        Route::get('care-requests', [CareRequestController::class, 'index'])->name('care-requests.index');
        Route::get('care-requests/{careRequest}', [CareRequestController::class, 'show'])->name('care-requests.show');
        Route::get('care-requests/{careRequest}/result-file', [CareRequestController::class, 'resultFile'])->name('care-requests.result-file');
        Route::get('care-requests/{careRequest}/slip', [CareRequestController::class, 'slip'])->name('care-requests.slip');
    });
    Route::post('care-requests/{careRequest}/decision', [CareRequestController::class, 'decide'])
        ->middleware(['can:approve-care-requests', 'role:registration,super-admin', 'throttle:30,1'])
        ->name('care-requests.decide');
    Route::post('care-requests/{careRequest}/cancel', [CareRequestController::class, 'cancel'])
        ->middleware(['role:doctor', 'throttle:20,1'])
        ->name('care-requests.cancel');
    Route::post('care-requests/{careRequest}/result', [CareRequestController::class, 'storeResult'])
        ->middleware(['role:doctor', 'throttle:20,1'])
        ->name('care-requests.result.store');
    Route::post('encounters/{encounter}/complete', [EncounterController::class, 'complete'])
        ->middleware(['can:complete,encounter', 'role:doctor'])
        ->name('encounters.complete');
    Route::post('encounters/{encounter}/admission-request', [EncounterController::class, 'requestAdmission'])
        ->middleware(['can:update,encounter', 'can:create-admissions', 'role:doctor,super-admin', 'throttle:20,1'])
        ->name('encounters.admission-request');
    Route::post('encounters/{encounter}/prescriptions', [EncounterController::class, 'prescribe'])
        ->middleware('can:update,encounter')
        ->name('encounters.prescriptions.store');
    Route::post('prescriptions/{prescription}/dispense', [BillingStatementController::class, 'dispense'])
        ->middleware(['can:manage-billing', 'role:super-admin'])
        ->name('prescriptions.dispense');

    Route::middleware(['can:manage-billing', 'role:super-admin'])->prefix('billing')->name('billing.')->group(function () {
        Route::get('rates', [ChargeRateController::class, 'index'])->name('rates.index');
        Route::post('rates', [ChargeRateController::class, 'store'])->name('rates.store');
        Route::put('rates/{chargeRate}', [ChargeRateController::class, 'update'])->name('rates.update');
        Route::post('encounters/{encounter}/generate', [BillingStatementController::class, 'generateEncounter'])->name('encounters.generate');
        Route::post('admissions/{admission}/generate', [BillingStatementController::class, 'generateAdmission'])->name('admissions.generate');
    });

    Route::middleware(['role:doctor', 'can:view-encounters'])->group(function () {
        Route::post('admissions/{admission}/discharge-summary', [DischargeSummaryController::class, 'storeAdmission'])->name('admissions.discharge-summary.store');
        Route::post('emergency/{visit}/discharge-summary', [DischargeSummaryController::class, 'storeErVisit'])->name('emergency.discharge-summary.store');
    });
    Route::middleware(['role:doctor', 'can:order-discharges'])->group(function () {
        Route::post('admissions/{admission}/discharge-summary/approve', [DischargeSummaryController::class, 'approveAdmission'])->name('admissions.discharge-summary.approve');
        Route::post('emergency/{visit}/discharge-summary/approve', [DischargeSummaryController::class, 'approveErVisit'])->name('emergency.discharge-summary.approve');
    });

    // Emergency / ER
    Route::middleware(['can:view-er', 'role:nurse,doctor,super-admin,registration'])->group(function () {
        Route::get('emergency', [EmergencyController::class, 'index'])->name('emergency.index');
        Route::get('emergency/create', [EmergencyController::class, 'create'])->name('emergency.create');
        Route::post('emergency', [EmergencyController::class, 'store'])
            ->middleware('throttle:patient-reference-workflow')
            ->name('emergency.store');
        Route::post('emergency/unknown-arrivals', [EmergencyController::class, 'storeUnknownArrival'])
            ->middleware(['can:create-er-visits', 'role:registration,nurse'])
            ->name('emergency.unknown-arrivals.store');
        Route::get('emergency/{visit}', [EmergencyController::class, 'show'])->name('emergency.show');
        Route::post('emergency/{visit}/triage', [EmergencyController::class, 'triage'])->middleware('role:nurse,doctor')->name('emergency.triage');
        Route::post('emergency/{visit}/reassessments', [TriageReassessmentController::class, 'store'])
            ->middleware(['can:triage-patients', 'role:nurse'])
            ->name('emergency.reassessments.store');
        Route::post('emergency/{visit}/reassessments/{reassessment}/confirm', [TriageReassessmentController::class, 'confirm'])
            ->middleware(['can:triage-patients', 'role:nurse,doctor'])
            ->name('emergency.reassessments.confirm');
        Route::post('emergency/queue/{queue}/status', [EmergencyController::class, 'queueStatus'])->name('emergency.queue-status');
        Route::get('emergency/check-in/reference', [ArrivalCheckInController::class, 'lookupByReference'])
            ->middleware('throttle:patient-reference-code')
            ->name('emergency.checkin.lookup');
        Route::get('emergency/check-in/{token}', [ArrivalCheckInController::class, 'show'])->name('emergency.checkin.show');
        Route::post('emergency/check-in/confirm', [ArrivalCheckInController::class, 'confirm'])->name('emergency.checkin.confirm');
    });

    Route::middleware(['can:create-admissions', 'role:doctor,super-admin'])->group(function () {
        Route::get('emergency/{visit}/admission/create', [InpatientController::class, 'createFromErVisit'])->name('emergency.admission.create');
        Route::post('emergency/{visit}/admission', [InpatientController::class, 'storeFromErVisit'])->name('emergency.admission.store');
    });

    Route::middleware(['can:triage-patients', 'role:nurse,doctor,super-admin'])->group(function () {
        Route::get('triage', [TriageAssessmentController::class, 'create'])->name('triage.dashboard');
        Route::get('triage/create', [TriageAssessmentController::class, 'create'])->name('triage.create');
        Route::post('triage', [TriageAssessmentController::class, 'store'])->middleware('role:nurse,doctor,super-admin')->name('triage.store');
        Route::get('triage/{triageAssessment}/er-intake', [EmergencyController::class, 'createFromTriage'])->name('triage.er-intake');
    });

    Route::middleware(['can:view-opd-queue', 'role:doctor,registration,nurse,super-admin'])->group(function () {
        Route::get('doctors/queue', [DoctorQueueController::class, 'index'])->name('doctors.queue');
        Route::get('doctors/queue/{triageAssessment}', [DoctorQueueController::class, 'show'])->name('doctors.queue.show');
    });
    Route::middleware(['can:manage-opd-queue', 'role:doctor,registration,super-admin'])->group(function () {
        Route::post('doctors/queue/{triageAssessment}/status', [DoctorQueueController::class, 'updateStatus'])->name('doctors.queue.status');
    });

    // Inpatient / Beds / Admissions
    Route::middleware(['can:view-beds', 'role:nurse,doctor,registration,super-admin'])->group(function () {
        Route::get('inpatient', [InpatientController::class, 'overview'])->name('inpatient.index');
        Route::get('beds', [InpatientController::class, 'beds'])->name('beds.index');
    });
    Route::middleware(['can:view-admissions', 'role:nurse,doctor,registration,super-admin'])->group(function () {
        Route::get('admissions', [InpatientController::class, 'admissions'])->name('admissions.index');
        Route::get('admissions/{admission}', [InpatientController::class, 'showAdmission'])->name('admissions.show');
    });
    Route::post('admissions/{admission}/billing-clearance-request', [InpatientController::class, 'requestBillingClearance'])
        ->middleware(['can:view-admissions', 'role:nurse,registration'])
        ->name('admissions.billing-clearance.request');
    Route::middleware(['can:create-admissions', 'role:doctor,super-admin'])->group(function () {
        Route::get('admissions/create', [InpatientController::class, 'createAdmission'])->name('admissions.create');
        Route::post('admissions', [InpatientController::class, 'storeAdmission'])->name('admissions.store');
    });
    Route::middleware(['can:update-bed-status', 'role:nurse,super-admin'])->group(function () {
        Route::post('beds/{bed}/status', [InpatientController::class, 'setBedStatus'])->name('beds.status');
    });
    Route::middleware(['can:assign-beds', 'role:registration,super-admin'])->group(function () {
        Route::post('admissions/{admission}/approve', [InpatientController::class, 'approveAdmission'])->name('admissions.approve');
        Route::post('admissions/{admission}/admit', [InpatientController::class, 'admit'])->name('admissions.admit');
        Route::post('admissions/{admission}/reserve', [InpatientController::class, 'reserveBed'])->name('admissions.reserve');
        Route::post('admissions/{admission}/reservations/{reservation}/cancel', [InpatientController::class, 'cancelReservation'])->name('admissions.reservations.cancel');
        Route::patch('admissions/{admission}/guarantee-details', [InpatientController::class, 'updateGuaranteeDetails'])->name('admissions.guarantee-details.update');
    });
    Route::middleware(['can:transfer-patients', 'role:nurse,super-admin'])->group(function () {
        Route::post('admissions/{admission}/transfer', [InpatientController::class, 'transfer'])->name('admissions.transfer');
    });
    Route::middleware(['can:execute-discharges', 'role:nurse,super-admin'])->group(function () {
        Route::post('admissions/{admission}/discharge', [InpatientController::class, 'discharge'])->name('admissions.discharge');
    });

    // Telehealth
    Route::middleware(['can:view-telehealth', 'role:doctor,nurse,super-admin'])->group(function () {
        Route::get('telehealth', [TelehealthController::class, 'index'])->name('telehealth.index');
        Route::get('telehealth/{session}', [TelehealthController::class, 'show'])->name('telehealth.show');
    });
    Route::post('telehealth/{session}/escalate-to-er', [TelehealthController::class, 'escalateToEr'])
        ->middleware(['can:view-telehealth', 'role:doctor', 'throttle:10,1'])
        ->name('telehealth.escalate-to-er');
    Route::middleware(['can:start-telehealth', 'role:doctor'])->group(function () {
        Route::post('telehealth/{session}/face-to-face-referral', [TelehealthReferralController::class, 'store'])->name('telehealth.face-to-face-referral');
        Route::post('telehealth/{session}/ai-summary', [TelehealthIntakeController::class, 'generate'])->name('telehealth.ai-summary.generate');
        Route::patch('telehealth/{session}/ai-summary', [TelehealthIntakeController::class, 'update'])->name('telehealth.ai-summary.update');
        Route::post('telehealth/{session}/ai-summary/approve', [TelehealthIntakeController::class, 'approve'])->name('telehealth.ai-summary.approve');
    });
    Route::get('telehealth/{session}/join', [TelehealthController::class, 'join'])->name('telehealth.join');
    Route::post('telehealth/{session}/join', [TelehealthController::class, 'connect'])
        ->middleware('throttle:10,1')
        ->name('telehealth.connect');
    Route::post('telehealth/{session}/leave', [TelehealthController::class, 'leave'])
        ->middleware('throttle:10,1')
        ->name('telehealth.leave');
    Route::middleware('can:start-telehealth')->group(function () {
        Route::post('telehealth/{session}/create-meeting', [TelehealthController::class, 'createMeeting'])->name('telehealth.create-meeting');
    });

    // Reports
    Route::middleware(['can:view-reports', 'role:nurse,registration,admin,super-admin'])->group(function () {
        Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
        Route::get('reports/patients', [ReportController::class, 'patients'])->name('reports.patients');
        Route::get('reports/appointments', [ReportController::class, 'appointments'])->name('reports.appointments');
        Route::get('reports/encounters', [ReportController::class, 'encounters'])->name('reports.encounters');
        Route::get('reports/er', [ReportController::class, 'erVolume'])->name('reports.er');
        Route::get('reports/beds', [ReportController::class, 'bedOccupancy'])->name('reports.beds');
        Route::get('reports/telehealth', [ReportController::class, 'telehealth'])->name('reports.telehealth');
    });
    Route::middleware(['can:view-reports', 'role:nurse,registration,admin,super-admin'])->group(function () {
        Route::get('reports/ai-triage-accuracy', [ReportController::class, 'aiTriageAccuracy'])->name('reports.ai-triage-accuracy');
        Route::get('reports/ai-triage-accuracy/export', [ReportController::class, 'exportAiTriageAccuracy'])
            ->middleware(['report.password.confirm', 'throttle:10,1'])
            ->name('reports.ai-triage-accuracy.export');
    });
    Route::middleware(['can:view-reports', 'role:doctor,nurse,registration,admin,super-admin'])->group(function () {
        Route::get('reports/{report}/export/{format}', ReportExportController::class)
            ->middleware(['report.password.confirm', 'throttle:10,1'])
            ->whereIn('format', ['csv', 'pdf'])
            ->name('reports.export');
    });
    Route::middleware(['can:view-reports', 'role:doctor,admin,super-admin'])->group(function () {
        Route::get('reports/ai-evaluation', [AiEvaluationController::class, 'index'])->name('reports.ai-evaluation');
        Route::post('reports/ai-evaluation/{symptomCheck}/review', [AiEvaluationController::class, 'review'])
            ->middleware('throttle:20,1')
            ->name('reports.ai-evaluation.review');
    });

    // Audit logs
    Route::middleware(['can:view-audit-logs', 'role:super-admin'])->group(function () {
        Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit.index');
    });

    Route::prefix('admin')->name('admin.')->middleware(['role:admin,super-admin'])->group(function () {
        Route::middleware('can:manage-users')->group(function () {
            Route::get('users', [AdminUserController::class, 'index'])->name('users.index');
            Route::get('users/create', [AdminUserController::class, 'create'])->name('users.create');
            Route::post('users', [AdminUserController::class, 'store'])->name('users.store');
            Route::patch('users/{user}/role', [AdminUserController::class, 'assignRole'])->name('users.role');
            Route::patch('users/{user}/status', [AdminUserController::class, 'setStatus'])->name('users.status');
        });
        Route::middleware(['can:manage-wards', 'can:manage-beds'])->prefix('bed-setup')->name('bed-setup.')->group(function () {
            Route::get('/', [BedSetupController::class, 'index'])->name('index');
            Route::post('wards', [BedSetupController::class, 'storeWard'])->name('wards.store');
            Route::patch('wards/{ward}', [BedSetupController::class, 'updateWard'])->name('wards.update');
            Route::delete('wards/{ward}', [BedSetupController::class, 'destroyWard'])->name('wards.destroy');
            Route::post('rooms', [BedSetupController::class, 'storeRoom'])->name('rooms.store');
            Route::patch('rooms/{room}', [BedSetupController::class, 'updateRoom'])->name('rooms.update');
            Route::delete('rooms/{room}', [BedSetupController::class, 'destroyRoom'])->name('rooms.destroy');
            Route::post('beds', [BedSetupController::class, 'storeBed'])->name('beds.store');
            Route::patch('beds/{bed}', [BedSetupController::class, 'updateBed'])->name('beds.update');
            Route::delete('beds/{bed}', [BedSetupController::class, 'destroyBed'])->name('beds.destroy');
        });
    });

    // Profile
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Two-factor authentication (MFA) management
    Route::get('profile/mfa', [MfaController::class, 'show'])->name('mfa.setup');
    Route::post('profile/mfa/enable', [MfaController::class, 'enable'])->name('mfa.enable');
    Route::post('profile/mfa/disable', [MfaController::class, 'disable'])->name('mfa.disable');
});

require __DIR__.'/auth.php';
require __DIR__.'/portal.php';
