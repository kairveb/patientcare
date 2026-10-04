<?php

namespace App\Services;

use App\Models\Appointment;
use App\Models\Prescription;
use App\Models\QueueDisplayEntry;
use Illuminate\Support\Facades\DB;

class QueueDisplayService
{
    public function syncAppointment(Appointment $appointment): void
    {
        $status = match ($appointment->status) {
            Appointment::STATUS_CHECKED_IN => QueueDisplayEntry::STATUS_WAITING,
            Appointment::STATUS_IN_CONSULTATION => QueueDisplayEntry::STATUS_IN_SERVICE,
            Appointment::STATUS_COMPLETED => QueueDisplayEntry::STATUS_COMPLETED,
            Appointment::STATUS_CANCELLED, Appointment::STATUS_NO_SHOW => QueueDisplayEntry::STATUS_CANCELLED,
            default => null,
        };

        if ($status === null) {
            return;
        }

        DB::transaction(function () use ($appointment, $status): void {
            $entry = QueueDisplayEntry::firstOrCreate(
                ['appointment_id' => $appointment->id],
                [
                    'service' => QueueDisplayEntry::SERVICE_OPD,
                    'status' => $status,
                ]
            );

            if (! $entry->wasRecentlyCreated) {
                $entry->update(['status' => $status]);
            }
        });
    }

    public function addPrescription(Prescription $prescription): QueueDisplayEntry
    {
        return DB::transaction(function () use ($prescription): QueueDisplayEntry {
            $entry = QueueDisplayEntry::firstOrCreate(
                ['prescription_id' => $prescription->id],
                [
                    'service' => QueueDisplayEntry::SERVICE_PHARMACY,
                    'status' => QueueDisplayEntry::STATUS_WAITING,
                ]
            );

            return $entry;
        });
    }

    /** Return only opaque queue numbers and display statuses for public screens. */
    public function publicBoard(): array
    {
        return [
            'opd' => $this->displayRows(QueueDisplayEntry::SERVICE_OPD, 'O'),
            'pharmacy' => $this->displayRows(QueueDisplayEntry::SERVICE_PHARMACY, 'P'),
        ];
    }

    private function displayRows(string $service, string $prefix): array
    {
        $today = today();

        return QueueDisplayEntry::query()
            ->where('service', $service)
            ->whereBetween('created_at', [$today->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->whereNotIn('status', [QueueDisplayEntry::STATUS_CANCELLED])
            ->orderByDesc('id')
            ->limit(12)
            ->get(['id', 'status'])
            ->map(fn (QueueDisplayEntry $entry) => [
                'queue_number' => sprintf('%s-%06d', $prefix, $entry->id),
                'status' => $entry->status,
            ])
            ->values()
            ->all();
    }
}
