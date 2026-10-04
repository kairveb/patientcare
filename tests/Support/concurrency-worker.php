<?php

use App\Models\Appointment;
use App\Models\Encounter;
use App\Models\User;
use App\Services\AdmissionService;
use App\Services\EncounterService;
use App\Services\OpdVitalsService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $operation, $recordId, $actorId, $barrier] = $argv;
$deadline = microtime(true) + 20;
while (! is_file($barrier) && microtime(true) < $deadline) {
    usleep(10_000);
}

if (! is_file($barrier)) {
    echo json_encode(['status' => 'barrier_timeout']);
    exit(2);
}

try {
    $actor = User::findOrFail((int) $actorId);

    match ($operation) {
        'complete' => app(EncounterService::class)->complete(
            Encounter::findOrFail((int) $recordId),
            [
                'history_of_present_illness' => 'Test history.',
                'physical_examination' => 'Test examination.',
                'primary_diagnosis_code' => 'R51.9',
                'primary_diagnosis_description' => 'Test diagnosis.',
                'assessment' => 'Test assessment.',
                'plan' => 'Test plan.',
            ],
            $actor
        ),
        'vitals' => app(OpdVitalsService::class)->recordForAppointment(
            Appointment::findOrFail((int) $recordId),
            ['height_cm' => 170, 'weight' => 68, 'blood_pressure' => '120/80'],
            $actor
        ),
        'admission' => app(AdmissionService::class)->requestFromEncounter(
            Encounter::findOrFail((int) $recordId),
            $actor,
            'Test admission request.'
        ),
        default => throw new InvalidArgumentException('Unknown concurrency operation.'),
    };

    echo json_encode(['status' => 'success']);
} catch (ValidationException $exception) {
    echo json_encode(['status' => 'validation_rejected']);
} catch (HttpExceptionInterface $exception) {
    echo json_encode(['status' => 'http_rejected', 'code' => $exception->getStatusCode()]);
} catch (Throwable $exception) {
    echo json_encode(['status' => 'error', 'type' => $exception::class]);
    exit(1);
}
