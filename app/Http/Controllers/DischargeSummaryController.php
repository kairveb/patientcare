<?php

namespace App\Http\Controllers;

use App\Models\Admission;
use App\Models\DischargeSummary;
use App\Models\ErVisit;
use App\Services\DischargeSummaryService;
use Illuminate\Http\Request;

class DischargeSummaryController extends Controller
{
    public function storeAdmission(Request $request, Admission $admission, DischargeSummaryService $summaries)
    {
        $this->authorize('view', $admission);
        $data = $request->validate([
            'final_diagnosis' => ['required', 'string', 'max:10000'],
            'clinical_course' => ['required', 'string', 'max:30000'],
            'discharge_instructions' => ['required', 'string', 'max:30000'],
            'follow_up_plan' => ['nullable', 'string', 'max:10000'],
        ]);
        $summary = $summaries->saveAdmission($admission, $request->user(), $data);

        return back()->with('success', 'Discharge summary saved as draft.')->with('discharge_summary_id', $summary->id);
    }

    public function approveAdmission(Request $request, Admission $admission, DischargeSummaryService $summaries)
    {
        $this->authorize('view', $admission);
        $summary = $admission->dischargeSummary;
        abort_unless($summary, 404);
        $summaries->approve($summary, $request->user());

        return back()->with('success', 'Discharge summary approved.');
    }

    public function storeErVisit(Request $request, ErVisit $visit, DischargeSummaryService $summaries)
    {
        $data = $request->validate([
            'final_diagnosis' => ['required', 'string', 'max:10000'],
            'clinical_course' => ['required', 'string', 'max:30000'],
            'discharge_instructions' => ['required', 'string', 'max:30000'],
            'follow_up_plan' => ['nullable', 'string', 'max:10000'],
        ]);
        $summaries->saveErVisit($visit, $request->user(), $data);

        return back()->with('success', 'Discharge summary saved as draft.');
    }

    public function approveErVisit(Request $request, ErVisit $visit, DischargeSummaryService $summaries)
    {
        $summary = $visit->dischargeSummary;
        abort_unless($summary, 404);
        $summaries->approve($summary, $request->user());

        return back()->with('success', 'Discharge summary approved.');
    }

}
