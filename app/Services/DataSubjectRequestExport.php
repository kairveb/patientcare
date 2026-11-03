<?php

namespace App\Services;

use App\Models\Patient;

class DataSubjectRequestExport
{
    public function forPatient(Patient $patient): array
    {
        $patient->loadMissing(['addresses', 'appointments.provider', 'appointments.appointmentType', 'careRequests', 'prescriptions']);

        return [
            'generated_at' => now()->toIso8601String(),
            'profile' => [
                'first_name' => $patient->first_name,
                'middle_name' => $patient->middle_name,
                'last_name' => $patient->last_name,
                'date_of_birth' => $patient->date_of_birth?->toDateString(),
                'sex' => $patient->sex,
                'email' => $patient->email,
                'phone' => $patient->phone,
                'addresses' => $patient->addresses->map(fn ($address): array => [
                    'line1' => $address->line1,
                    'line2' => $address->line2,
                    'city' => $address->city,
                    'province' => $address->province,
                    'postal_code' => $address->postal_code,
                    'primary' => $address->primary,
                ])->values()->all(),
            ],
            'consents' => $patient->consents()
                ->orderBy('recorded_at')
                ->get(['type', 'version', 'granted', 'channel', 'recorded_at'])
                ->map(fn ($consent): array => [
                    'type' => $consent->type,
                    'version' => $consent->version,
                    'granted' => $consent->granted,
                    'channel' => $consent->channel,
                    'recorded_at' => $consent->recorded_at?->toIso8601String(),
                ])->all(),
            'appointments' => $patient->appointments->map(fn ($appointment): array => [
                'appointment_number' => $appointment->appointment_number,
                'doctor' => $appointment->provider?->full_name,
                'consultation_type' => $appointment->appointmentType?->name,
                'starts_at' => $appointment->starts_at?->toIso8601String(),
                'ends_at' => $appointment->ends_at?->toIso8601String(),
                'status' => $appointment->status,
                'booking_source' => $appointment->booking_source,
            ])->all(),
            'approvals' => $patient->careRequests->map(fn ($careRequest): array => [
                'reference' => $careRequest->approval_reference,
                'type' => $careRequest->type,
                'description' => $careRequest->description,
                'urgency' => $careRequest->urgency,
                'status' => $careRequest->status,
                'remarks' => $careRequest->remarks,
                'requested_at' => $careRequest->created_at?->toIso8601String(),
                'decided_at' => $careRequest->decided_at?->toIso8601String(),
            ])->all(),
            'prescriptions' => $patient->prescriptions->map(fn ($prescription): array => [
                'medication_name' => $prescription->medication_name,
                'dosage' => $prescription->dosage,
                'instructions' => $prescription->instructions,
                'prescribed_at' => $prescription->prescribed_at?->toIso8601String(),
            ])->all(),
        ];
    }
}
