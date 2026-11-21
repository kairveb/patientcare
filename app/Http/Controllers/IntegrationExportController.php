<?php

namespace App\Http\Controllers;

use App\Services\IntegrationExportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class IntegrationExportController extends Controller
{
    public function store(Request $request, IntegrationExportService $exports): RedirectResponse
    {
        $data = $request->validate([
            'dataset' => ['required', Rule::in([
                'appointments',
                'encounters',
                'triage_levels',
                'er_visits',
                'bed_occupancy',
                'billing_totals',
            ])],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'format' => ['required', Rule::in(['csv', 'pdf', 'txt', 'xls'])],
        ]);
        $export = $exports->request(
            $data['dataset'],
            $data['from'],
            $data['to'],
            $data['format'],
            $request->user()
        );

        $message = $export->status === 'QUEUED'
            ? 'The aggregate HADS export was queued.'
            : 'The export was recorded, but HADS delivery is unavailable. Check the export status below.';

        return redirect()->route('reports.index')->with('success', $message);
    }
}
