<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\RuntimeBackend\LocalRuntimeBackendProbe;
use App\Services\RuntimeBackend\LocalRuntimeBackendProbeFailure;

final class RuntimeBackendProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:runtime-backend:probe {provider} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe the local runtime backend through fixed argv operations';

    public function handle(LocalRuntimeBackendProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:runtime-backend:probe')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($probe->check($this->argument('provider')));
        } catch (LocalRuntimeBackendProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
