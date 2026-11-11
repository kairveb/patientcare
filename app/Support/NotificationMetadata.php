<?php

namespace App\Support;

use InvalidArgumentException;

final class NotificationMetadata
{
    public const PRIORITIES = ['Critical', 'High', 'Normal', 'Low'];

    public const MODULES = ['SPRS', 'ASS', 'TOCS', 'EERTS', 'IBMS'];

    /**
     * @return array{priority: string, module: string}
     */
    public static function make(string $priority, string $module): array
    {
        if (! in_array($priority, self::PRIORITIES, true) || ! in_array($module, self::MODULES, true)) {
            throw new InvalidArgumentException('Notification priority or module is not supported.');
        }

        return ['priority' => $priority, 'module' => $module];
    }
}
