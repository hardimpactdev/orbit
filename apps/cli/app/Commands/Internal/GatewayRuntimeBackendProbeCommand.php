<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\RuntimeBackend\LocalGatewayRuntimeBackendProbe;
use App\Services\RuntimeBackend\LocalRuntimeBackendProbeFailure;

final class GatewayRuntimeBackendProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:gateway-runtime-backend:probe {container} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe the local gateway runtime container through fixed argv operations';

    public function handle(LocalGatewayRuntimeBackendProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:gateway-runtime-backend:probe')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($probe->check($this->argument('container')));
        } catch (LocalRuntimeBackendProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
