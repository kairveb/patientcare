<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

class AuditLogBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit logs are append-only.');
    }

    public function delete()
    {
        throw new LogicException('Audit logs are append-only.');
    }
}
