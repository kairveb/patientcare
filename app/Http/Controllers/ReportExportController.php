<?php

namespace App\Http\Controllers;

use App\Services\AuditLogService;
use App\Services\ReportExportService;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Carbon\CarbonImmutable;
use Symfony\Component\HttpFoundation\Response;

class ReportExportController extends Controller
{
    public function __invoke(
        Request $request,
        string $report,
        string $format,
        ReportExportService $exports,
        AuditLogService $audit
    ): Response {
        Gate::authorize('view-reports');
        abort_unless($request->user()->hasAnyRole(['doctor', 'nurse', 'registration', 'admin', 'super-admin']), 403);
        abort_unless(in_array($format, ['csv', 'pdf'], true), 404);

        $filters = $request->validate([
            'start' => ['nullable', 'date_format:Y-m-d'],
            'end' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:start'],
            'department_id' => ['nullable', 'integer', 'exists:departments,id'],
            'doctor_id' => ['nullable', 'integer', 'exists:providers,id'],
        ]);
        $filters = array_replace([
            'start' => today()->subDays(30)->toDateString(),
            'end' => today()->toDateString(),
        ], $filters);
        if (CarbonImmutable::parse($filters['start'])->greaterThan(CarbonImmutable::parse($filters['end']))) {
            throw ValidationException::withMessages(['end' => 'The end date must be on or after the start date.']);
        }
        $payload = $exports->data($report, $filters, $request->user());

        $audit->log('EXPORT_REPORT', 'report', null, 'SUCCESS', [
            'report' => $report,
            'format' => $format,
            'filters' => [
                'start' => $filters['start'],
                'end' => $filters['end'],
                'department_id' => $filters['department_id'] ?? null,
                'doctor_id' => $filters['doctor_id'] ?? null,
            ],
        ], $request->user()->id);

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($payload, $exports): void {
                $output = fopen('php://output', 'w');
                fputcsv($output, array_map($exports->safeCsvCell(...), $payload['headers']));
                foreach ($payload['rows'] as $row) {
                    fputcsv($output, array_map($exports->safeCsvCell(...), $row));
                }
                fclose($output);
            }, $report.'-report.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $dompdf = new Dompdf(['isRemoteEnabled' => false]);
        $dompdf->loadHtml(view('reports.export-pdf', [
            'report' => str($report)->replace('-', ' ')->title(),
            'filters' => $filters,
            ...$payload,
        ])->render());
        $dompdf->setPaper('A4', 'landscape');
        $dompdf->render();

        return response($dompdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$report.'-report.pdf"',
        ]);
    }
}
