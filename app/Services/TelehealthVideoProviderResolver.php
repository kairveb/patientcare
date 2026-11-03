<?php

namespace App\Services;

use App\Contracts\TelehealthVideoProvider;
use App\Services\TelehealthVideo\JitsiDemoProvider;
use App\Services\TelehealthVideo\ZoomProvider;
use RuntimeException;

class TelehealthVideoProviderResolver
{
    public function resolve(): TelehealthVideoProvider
    {
        $configuredProvider = config('telehealth.provider');
        if ($configuredProvider === 'zoom') {
            if (! app(ZoomService::class)->enabled()) {
                throw new RuntimeException('Zoom telehealth was selected but Zoom is not configured.');
            }

            return app(ZoomProvider::class);
        }

        if ($configuredProvider === 'jitsi_demo') {
            return app(JitsiDemoProvider::class);
        }

        return app(ZoomService::class)->enabled()
            ? app(ZoomProvider::class)
            : throw new RuntimeException(
                'No telehealth provider is configured. Set TELEHEALTH_PROVIDER=jitsi_demo only for an explicit demo connection, or configure Zoom.'
            );
    }
}
