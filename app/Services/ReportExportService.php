<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\AppointmentReassignment;
use App\Models\Bed;
use App\Models\CareRequest;
use App\Models\Encounter;
use App\Models\ErVisit;
use App\Models\Patient;
use App\Models\SymptomCheck;
use App\Models\TelehealthSession;
use App\Models\TriageAssessment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ReportExportService
{
    public function data(string $report, array $filters, User $user): array
    {
        if ($user->hasRole('doctor') && ! in_array($report, [
            'appointments', 'encounters', 'telehealth', 'ai-triage-outcomes',
            'care-requests', 'reassignments', 'ai-evaluation-dataset',
        ], true)) {
            abort(403);
        }

        return match ($report) {
            'patients' => $this->patients($filters),
            'appointments' => $this->appointments($filters, $user),
            'encounters' => $this->encounters($filters, $user),
            'er' => $this->er($filters),
            'beds' => $this->beds($filters),
            'telehealth' => $this->telehealth($filters, $user),
            'ai-triage-accuracy' => $this->triageAccuracy($filters),
            'ai-triage-outcomes' => $this->triageOutcomes($filters, $user),
            'care-requests' => $this->careRequests($filters, $user),
            'reassignments' => $this->reassignments($filters, $user),
            'ai-evaluation-dataset' => $this->evaluationDataset($filters, $user),
            default => abort(404),
        };
    }

    public function safeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^\s*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
    }

    public function render(array $payload, string $format, string $title, array $filters): string
    {
        if ($format === 'csv' || $format === 'txt') {
            $stream = fopen('php://temp', 'r+');
            if (! is_resource($stream)) {
                throw new \RuntimeException('Unable to allocate an export stream.');
            }
            $delimiter = $format === 'csv' ? ',' : "\t";
            foreach ([$payload['headers'], ...$payload['rows']] as $row) {
                if (fputcsv($stream, array_map($this->safeCsvCell(...), $row), $delimiter, '"', '\\') === false) {
                    fclose($stream);
                    throw new \RuntimeException('Unable to write the export stream.');
                }
            }
            rewind($stream);
            $content = stream_get_contents($stream);
            fclose($stream);
            if (! is_string($content)) {
                throw new \RuntimeException('Unable to read the export stream.');
            }

            return $content;
        }

        if ($format === 'xls') {
            $xmlRows = collect([$payload['headers'], ...$payload['rows']])->map(function (array $row): string {
                $cells = collect($row)->map(fn (mixed $value): string => '<Cell><Data ss:Type="String">'
                    .htmlspecialchars($this->safeCsvCell($value), ENT_XML1 | ENT_QUOTES, 'UTF-8')
                    .'</Data></Cell>')->implode('');

                return '<Row>'.$cells.'</Row>';
            })->implode('');

            return '<?xml version="1.0" encoding="UTF-8"?>'
                .'<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
                .'<Worksheet ss:Name="Report"><Table>'.$xmlRows.'</Table></Worksheet></Workbook>';
        }

        if ($format !== 'pdf') {
            throw new \InvalidArgumentException('Unsupported report export format.');
        }

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml(view('reports.export-pdf', [
            'report' => $title,
            'filters' => $filters,
            ...$payload,
        ])->render());
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return $dompdf->output();
    }

    private function patients(array $filters): array
    {
        $query = Patient::query()->whereBetween('created_at', $this->dateRange($filters));
        $this->applyPatientRelatedFilters($query, $filters);

        return $this->result(['Patient reference', 'Registered at'], $query->get()->map(fn (Patient $patient): array => [
            $this->patientReference($patient),
            $patient->created_at?->toIso8601String(),
        ]));
    }

    private function appointments(array $filters, User $user): array
    {
        $query = Appointment::query()
            ->with(['patient:id,reference_code,lookup_code', 'provider:id,display_name', 'department:id,name', 'appointmentType:id,name'])
            ->whereBetween('starts_at', $this->dateRange($filters));
        if ($user->hasRole('doctor')) {
            $query->where('provider_id', $user->provider?->id ?? 0);
        }
        $this->filterProvider($query, $filters);
        if (filled($filters['department_id'] ?? null)) {
            $query->where('department_id', (int) $filters['department_id']);
        }

        return $this->result(
            ['Starts at', 'Patient reference', 'Doctor', 'Department', 'Consultation type', 'Status', 'Booking source'],
            $query->orderBy('starts_at')->get()->map(fn (Appointment $appointment): array => [
                $appointment->starts_at?->toIso8601String(),
                $this->patientReference($appointment->patient),
                $appointment->provider?->full_name,
                $appointment->department?->name,
                $appointment->appointmentType?->name,
                $appointment->status,
                $appointment->booking_source,
            ])
        );
    }

    private function encounters(array $filters, User $user): array
    {
        $query = Encounter::query()
            ->with(['patient:id,reference_code,lookup_code', 'provider:id,display_name,department_id', 'provider.department:id,name'])
            ->whereBetween('started_at', $this->dateRange($filters));
        if ($user->hasRole('doctor')) {
            $query->where('provider_id', $user->provider?->id ?? 0);
        }
        $this->filterProvider($query, $filters);

        return $this->result(
            ['Started at', 'Patient reference', 'Doctor', 'Department', 'Encounter type', 'Status'],
            $query->orderBy('started_at')->get()->map(fn (Encounter $encounter): array => [
                $encounter->started_at?->toIso8601String(),
                $this->patientReference($encounter->patient),
                $encounter->provider?->full_name,
                $encounter->provider?->department?->name,
                $encounter->type,
                $encounter->status,
            ])
        );
    }

    private function er(array $filters): array
    {
        $query = ErVisit::query()->with(['patient:id,reference_code,lookup_code', 'triageAssessments'])
            ->whereBetween('arrived_at', $this->dateRange($filters));
        $this->applyPatientRelatedFilters($query, $filters);

        return $this->result(
            ['Arrived at', 'Patient reference', 'Visit status', 'Triage priority', 'Severity category'],
            $query->orderBy('arrived_at')->get()->map(fn (ErVisit $visit): array => [
                $visit->arrived_at?->toIso8601String(),
                $this->patientReference($visit->patient),
                $visit->status,
                $visit->triageAssessments->last()?->priority,
                $visit->triageAssessments->last()?->severity_category,
            ])
        );
    }

    private function beds(array $filters): array
    {
        $query = Bed::query()->with(['patient:id,reference_code,lookup_code', 'activeAssignment'])
            ->orderBy('status')->orderBy('id');
        $this->applyPatientRelatedFilters($query, $filters, 'patient');

        return $this->result(
            ['Bed reference', 'Status', 'Patient reference', 'Assigned at'],
            $query->get()->map(fn (Bed $bed): array => [
                $bed->label,
                $bed->status,
                $this->patientReference($bed->patient),
                $bed->activeAssignment?->assigned_at?->toIso8601String(),
            ])
        );
    }

    private function telehealth(array $filters, User $user): array
    {
        $query = TelehealthSession::query()
            ->with(['appointment.patient:id,reference_code,lookup_code', 'appointment.provider:id,display_name,department_id', 'appointment.provider.department:id,name', 'appointment.appointmentType:id,name'])
            ->whereBetween('start_time', $this->dateRange($filters));
        if ($user->hasRole('doctor')) {
            $query->whereHas('appointment', fn (Builder $appointments) => $appointments
                ->where('provider_id', $user->provider?->id ?? 0));
        }
        $query->whereHas('appointment', function (Builder $appointments) use ($filters): void {
            if (filled($filters['doctor_id'] ?? null)) {
                $appointments->where('provider_id', (int) $filters['doctor_id']);
            }
            if (filled($filters['department_id'] ?? null)) {
                $appointments->where('department_id', (int) $filters['department_id']);
            }
        });

        return $this->result(
            ['Session start', 'Patient reference', 'Doctor', 'Department', 'Consultation type', 'Session status'],
            $query->orderBy('start_time')->get()->map(fn (TelehealthSession $session): array => [
                $session->start_time?->toIso8601String(),
                $this->patientReference($session->appointment?->patient),
                $session->appointment?->provider?->full_name,
                $session->appointment?->provider?->department?->name,
                $session->appointment?->appointmentType?->name,
                $session->status,
            ])
        );
    }

    private function triageAccuracy(array $filters): array
    {
        $query = TriageAssessment::query()->with(['erVisit.patient:id,reference_code,lookup_code'])
            ->whereBetween('triaged_at', $this->dateRange($filters));
        $this->applyPatientRelatedFilters($query, $filters, 'erVisit.patient');
        $assessments = $query->orderBy('triaged_at')->get();

        return $this->result(
            ['Triaged at', 'Patient reference', 'AI urgency', 'AI severity category', 'Final urgency', 'Final severity category', 'Rule override'],
            $assessments->map(fn (TriageAssessment $assessment): array => [
                $assessment->triaged_at?->toIso8601String(),
                $this->patientReference($assessment->erVisit?->patient),
                $assessment->ai_suggested_level,
                TriageAssessment::severityCategoryFor($assessment->ai_suggested_level),
                $assessment->final_level,
                $assessment->severity_category,
                $assessment->is_overridden ? 'true' : 'false',
            ])
        );
    }

    private function triageOutcomes(array $filters, User $user): array
    {
        $query = SymptomCheck::query()->with('patient:id,reference_code,lookup_code')
            ->whereBetween('created_at', $this->dateRange($filters));
        if ($user->hasRole('doctor')) {
            $query->where('clinician_reviewed_by', $user->id);
        }
        if (filled($filters['doctor_id'] ?? null)) {
            $reviewerId = \App\Models\Provider::query()->whereKey((int) $filters['doctor_id'])->value('user_id');
            $query->where('clinician_reviewed_by', $reviewerId ?? 0);
        }
        if (filled($filters['department_id'] ?? null)) {
            $department = \App\Models\Department::query()->findOrFail((int) $filters['department_id']);
            $query->where('recommended_department', $department->name);
        }

        return $this->result(
            ['Triage date', 'Patient reference', 'Provider', 'Recommended urgency', 'Severity category', 'Specialty', 'Rule override'],
            $query->orderBy('created_at')->get()->map(fn (SymptomCheck $check): array => [
                $check->created_at?->toIso8601String(),
                $this->patientReference($check->patient),
                $check->ai_provider,
                $check->urgency_level,
                in_array(strtolower((string) $check->urgency_level), ['emergency', 'urgent'], true)
                    ? 'Severe (critical)'
                    : 'Not Severe',
                $check->recommended_department,
                $check->rule_overrode_ai ? 'true' : 'false',
            ])
        );
    }

    private function careRequests(array $filters, User $user): array
    {
        $query = CareRequest::query()
            ->with(['patient:id,reference_code,lookup_code', 'encounter.provider:id,display_name,department_id', 'encounter.provider.department:id,name'])
            ->whereBetween('created_at', $this->dateRange($filters));
        if ($user->hasRole('doctor')) {
            $providerId = $user->provider?->id ?? 0;
            $query->where(fn (Builder $requests) => $requests
                ->where('requested_by', $user->id)
                ->orWhereHas('encounter', fn (Builder $encounters) => $encounters->where('provider_id', $providerId)));
        }
        $query->whereHas('encounter.provider', function (Builder $providers) use ($filters): void {
            if (filled($filters['doctor_id'] ?? null)) {
                $providers->where('id', (int) $filters['doctor_id']);
            }
            if (filled($filters['department_id'] ?? null)) {
                $providers->where('department_id', (int) $filters['department_id']);
            }
        });

        return $this->result(
            ['Requested at', 'Patient reference', 'Doctor', 'Department', 'Request type', 'Urgency', 'Status', 'Decided at'],
            $query->orderBy('created_at')->get()->map(fn (CareRequest $request): array => [
                $request->created_at?->toIso8601String(),
                $this->patientReference($request->patient),
                $request->encounter?->provider?->full_name,
                $request->encounter?->provider?->department?->name,
                $request->type,
                $request->urgency,
                $request->status,
                $request->decided_at?->toIso8601String(),
            ])
        );
    }

    private function reassignments(array $filters, User $user): array
    {
        $query = AppointmentReassignment::query()
            ->with(['appointment.patient:id,reference_code,lookup_code', 'appointment.department:id,name', 'originalProvider:id,display_name,department_id', 'suggestedProvider:id,display_name'])
            ->whereBetween('appointment_reassignments.created_at', $this->dateRange($filters));
        if ($user->hasRole('doctor')) {
            $providerId = $user->provider?->id ?? 0;
            $query->where(fn (Builder $cases) => $cases
                ->where('original_provider_id', $providerId)
                ->orWhere('suggested_provider_id', $providerId));
        }
        if (filled($filters['doctor_id'] ?? null)) {
            $doctorId = (int) $filters['doctor_id'];
            $query->where(fn (Builder $cases) => $cases
                ->where('original_provider_id', $doctorId)
                ->orWhere('suggested_provider_id', $doctorId));
        }
        if (filled($filters['department_id'] ?? null)) {
            $query->whereHas('appointment', fn (Builder $appointments) => $appointments
                ->where('department_id', (int) $filters['department_id']));
        }

        return $this->result(
            ['Case opened', 'Patient reference', 'Original doctor', 'Suggested doctor', 'Appointment time', 'Case status'],
            $query->orderBy('appointment_reassignments.created_at')->get()->map(fn (AppointmentReassignment $case): array => [
                $case->created_at?->toIso8601String(),
                $this->patientReference($case->appointment?->patient),
                $case->originalProvider?->full_name,
                $case->suggestedProvider?->full_name,
                $case->appointment?->starts_at?->toIso8601String(),
                $case->status,
            ])
        );
    }

    private function evaluationDataset(array $filters, User $user): array
    {
        $evaluations = app(AiEvaluationService::class)->dataset($filters, $user);

        return $this->result(
            ['urgency', 'specialty', 'age range', 'sex', 'clinician agreement', 'rule override flag', 'week'],
            $evaluations->map(fn (array $row): array => array_values($row))
        );
    }

    private function applyPatientRelatedFilters(Builder $query, array $filters, string $relation = 'patient'): void
    {
        if (! filled($filters['department_id'] ?? null) && ! filled($filters['doctor_id'] ?? null)) {
            return;
        }

        $query->whereHas($relation.'.encounters.provider', function (Builder $providers) use ($filters): void {
            if (filled($filters['department_id'] ?? null)) {
                $providers->where('department_id', (int) $filters['department_id']);
            }
            if (filled($filters['doctor_id'] ?? null)) {
                $providers->where('id', (int) $filters['doctor_id']);
            }
        });
    }

    private function filterProvider(Builder $query, array $filters): void
    {
        if (filled($filters['doctor_id'] ?? null)) {
            $query->where('provider_id', (int) $filters['doctor_id']);
        }
        if (filled($filters['department_id'] ?? null)) {
            $query->whereHas('provider', fn (Builder $provider) => $provider
                ->where('department_id', (int) $filters['department_id']));
        }
    }

    private function dateRange(array $filters): array
    {
        $start = CarbonImmutable::parse($filters['start'] ?? today()->subDays(30)->toDateString())->startOfDay();
        $end = CarbonImmutable::parse($filters['end'] ?? today()->toDateString())->endOfDay();

        return [$start, $end];
    }

    private function patientReference(?Patient $patient): string
    {
        return $patient?->reference_code ?: $patient?->lookup_code ?: 'REFERENCE-UNAVAILABLE';
    }

    private function result(array $headers, Collection $rows): array
    {
        return ['headers' => $headers, 'rows' => $rows->all()];
    }
}
