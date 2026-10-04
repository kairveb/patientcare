<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class AuditLogImmutabilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_log_creation_remains_available(): void
    {
        $log = AuditLog::create([
            'action' => 'TEST_AUDIT_EVENT',
            'resource_type' => 'test',
            'resource_id' => 1,
            'result' => 'SUCCESS',
            'metadata' => ['source' => 'test'],
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'id' => $log->id,
            'action' => 'TEST_AUDIT_EVENT',
        ]);
    }

    public function test_audit_log_instance_update_is_rejected(): void
    {
        $log = $this->createAuditLog();

        $this->expectException(LogicException::class);
        $log->update(['action' => 'CHANGED_EVENT']);
    }

    public function test_audit_log_instance_delete_is_rejected(): void
    {
        $log = $this->createAuditLog();

        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_audit_log_mass_update_is_rejected(): void
    {
        $this->createAuditLog();

        $this->expectException(LogicException::class);
        AuditLog::query()->where('action', 'TEST_AUDIT_EVENT')->update(['result' => 'FAILURE']);
    }

    public function test_audit_log_mass_delete_is_rejected(): void
    {
        $this->createAuditLog();

        $this->expectException(LogicException::class);
        AuditLog::query()->where('action', 'TEST_AUDIT_EVENT')->delete();
    }

    private function createAuditLog(): AuditLog
    {
        return AuditLog::create([
            'action' => 'TEST_AUDIT_EVENT',
            'resource_type' => 'test',
            'resource_id' => 1,
            'result' => 'SUCCESS',
        ]);
    }
}
