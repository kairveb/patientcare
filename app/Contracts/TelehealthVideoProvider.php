<?php

namespace App\Contracts;

use App\Models\Appointment;
use App\Models\TelehealthSession;
use App\Models\User;

interface TelehealthVideoProvider
{
    public function name(): string;

    public function provision(TelehealthSession $session, Appointment $appointment): TelehealthSession;

    /**
     * @return array<string, string|null>
     */
    public function joinDetails(TelehealthSession $session, User $user): array;
}
