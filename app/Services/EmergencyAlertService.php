<?php

namespace App\Services;

use App\Events\EmergencyQueueAlert;
use App\Models\ErQueue;
use App\Models\TriageAssessment;
use App\Models\TriageReassessment;

class EmergencyAlertService
{
    public function visitCreated(): void
    {
        EmergencyQueueAlert::dispatch('New ER arrival requires triage.');
    }

    public function telehealthEscalated(): void
    {
        EmergencyQueueAlert::dispatch('A telehealth patient has been escalated to the ER and requires triage.');
    }

    public function triageRecorded(TriageAssessment $assessment): void
    {
        $priority = (string) $assessment->priority;
        $rankablePriority = str_replace('_', ' ', $priority);

        if (ErQueue::priorityRank($rankablePriority) > 2) {
            return;
        }

        EmergencyQueueAlert::dispatch('Critical or severe triage assessment recorded.', $priority);
    }

    public function triageReassessmentMoreSevere(TriageReassessment $reassessment): void
    {
        $level = match ($reassessment->ai_suggested_level) {
            'Emergency' => 'Level 1',
            'Urgent' => 'Level 2',
            'Prompt' => 'Level 3',
            'Non-Urgent' => 'Level 4',
            default => 'Level 5',
        };

        EmergencyQueueAlert::dispatch(
            'AI reassessment suggests a more severe level; nurse/doctor review is required.',
            $level
        );
    }
}
