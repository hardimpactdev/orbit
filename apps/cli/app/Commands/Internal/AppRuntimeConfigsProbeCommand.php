<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppRuntimeConfigsProbe;

final class AppRuntimeConfigsProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-runtime-configs:probe {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe Orbit-managed app runtime config files on the local node';

    public function handle(LocalAppRuntimeConfigsProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:app-runtime-configs:probe')) {
            return self::FAILURE;
        }

        return $this->emitInternalSuccess($probe->probe(), []);
    }
}
