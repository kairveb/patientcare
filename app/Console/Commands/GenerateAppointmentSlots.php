<?php

namespace App\Console\Commands;

use App\Models\ProviderSchedule;
use App\Services\SchedulingService;
use Illuminate\Console\Command;

class GenerateAppointmentSlots extends Command
{
    protected $signature = 'slots:generate {--days=30 : Number of upcoming days to generate slots for}';

    protected $description = 'Generate missing appointment slots for active providers';

    public function handle(SchedulingService $scheduling): int
    {
        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1) {
            $this->error('The --days option must be a positive whole number.');

            return self::INVALID;
        }

        $fromDate = now()->addDay()->startOfDay();
        $toDate = $fromDate->copy()->addDays($days - 1);
        $generated = 0;
        $scheduleCount = 0;

        ProviderSchedule::query()
            ->whereHas('provider', fn ($query) => $query->where('active', true))
            ->orderBy('id')
            ->chunkById(200, function ($schedules) use ($scheduling, $fromDate, $toDate, &$generated, &$scheduleCount): void {
                foreach ($schedules as $schedule) {
                    $generated += $scheduling->generateSlots(
                        $schedule,
                        $fromDate->toDateString(),
                        $toDate->toDateString()
                    );
                    $scheduleCount++;
                }
            });

        $this->info(sprintf(
            'Created %d missing slots from %s through %s across %d active-provider schedules.',
            $generated,
            $fromDate->toDateString(),
            $toDate->toDateString(),
            $scheduleCount
        ));

        return self::SUCCESS;
    }
}
