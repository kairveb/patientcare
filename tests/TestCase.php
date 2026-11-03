<?php

namespace Tests;

use App\Models\Patient;
use App\Models\User;
use App\Services\PatientConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase {
        refreshDatabase as protected refreshDatabaseUsingTrait;
    }

    /**
     * Track output buffer level at the start of each test to avoid
     * leaving stray buffers open (which PHPUnit flags as risky).
     */
    protected int $initialObLevel = 0;

    public function refreshDatabase()
    {
        $driver = config('database.default');
        if (
            in_array($driver, ['mysql', 'mariadb'], true)
            && config("database.connections.{$driver}.database") !== 'hims_test'
        ) {
            throw new \RuntimeException('Tests may only use the dedicated hims_test MySQL database.');
        }

        $this->refreshDatabaseUsingTrait();
    }

    protected function acceptRequiredPrivacyDocuments(Patient $patient, User $user): void
    {
        $consents = app(PatientConsentService::class);
        foreach (['DATA_PRIVACY', 'TERMS_OF_USE'] as $type) {
            if (! $consents->hasCurrentGrant($patient, $type)) {
                $consents->record($patient, $type, true, $user, 'self');
            }
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->initialObLevel = ob_get_level();
    }

    protected function tearDown(): void
    {
        while (ob_get_level() > $this->initialObLevel) {
            @ob_end_clean();
        }

        parent::tearDown();
    }
}
