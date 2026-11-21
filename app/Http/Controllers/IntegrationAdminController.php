<?php

namespace App\Http\Controllers;

use App\Models\IntegrationLog;
use App\Models\IntegrationOutbox;
use App\Models\IntegrationPartner;
use Illuminate\View\View;

class IntegrationAdminController extends Controller
{
    public function index(): View
    {
        $this->authorize('viewAny', IntegrationPartner::class);

        return view('admin.integrations.index', [
            'partners' => IntegrationPartner::query()->orderBy('code')->get(),
            'deliveries' => IntegrationOutbox::query()->with('partner')->latest()->paginate(25),
            'logs' => IntegrationLog::query()->latest()->limit(50)->get(),
        ]);
    }
}
