<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Models\Process;

final readonly class ProcessOwnershipDetail
{
    /** @return array<string, string> */
    public function for(Process $process): array
    {
        $app = $process->ownerApp();
        $process->loadMissing('instance');
        /** @var array<string, string> $detail */
        $detail = [];
        $appName = $app?->name;
        $instanceName = $process->instance?->name;

        if (is_string($appName) && $appName !== '') {
            $detail['app'] = $appName;
        }

        if (is_string($instanceName) && $instanceName !== '') {
            $detail['instance'] = $instanceName;
        }

        return $detail;
    }
}
