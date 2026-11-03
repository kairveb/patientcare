<?php

return [
    'reassignment_window_days' => max(1, (int) env('APPOINTMENT_REASSIGNMENT_WINDOW_DAYS', 7)),
    'reassignment_candidate_limit' => 5,
    'fully_booked_window_days' => max(1, (int) env('APPOINTMENT_FULLY_BOOKED_WINDOW_DAYS', 7)),
    'requires_approval' => (bool) env('APPOINTMENT_REQUIRES_APPROVAL', true),
];
