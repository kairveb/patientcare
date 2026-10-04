<?php

namespace App\Console\Commands;

use App\Models\Admission;
use Illuminate\Console\Command;

class ReportAdmissionsMissingAttending extends Command
{
    protected $signature = 'hims:admissions:missing-attending';

    protected $description = 'List admission records without an assigned attending provider';

    public function handle(): int
    {
        $admissions = Admission::query()
            ->whereNull('attending_provider_id')
            ->orderBy('id')
            ->get(['id']);

        if ($admissions->isEmpty()) {
            $this->info('No admissions are missing an attending provider.');

            return self::SUCCESS;
        }

        $this->table(['Admission ID'], $admissions->toArray());

        return self::SUCCESS;
    }
}
