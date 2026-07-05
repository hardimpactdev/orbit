<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppRuntimeContainersProbe;

final class AppRuntimeContainersProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-runtime-containers:probe {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe Orbit-managed app runtime containers on the local node';

    public function handle(LocalAppRuntimeContainersProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:app-runtime-containers:probe')) {
            return self::FAILURE;
        }

        return $this->emitInternalSuccess($probe->probe(), []);
    }
}
