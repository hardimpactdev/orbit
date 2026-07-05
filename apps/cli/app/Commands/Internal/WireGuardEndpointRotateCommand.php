<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Network\LocalWireGuardEndpointRotateAction;
use App\Services\Network\LocalWireGuardEndpointRotateFailure;

final class WireGuardEndpointRotateCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:wireguard-endpoint:rotate {endpoint} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Rotate local WireGuard peer endpoints through fixed argv operations';

    public function handle(LocalWireGuardEndpointRotateAction $rotate): int
    {
        if (! $this->verifyOperationToken('internal:wireguard-endpoint:rotate')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($rotate->run($this->argument('endpoint')));
        } catch (LocalWireGuardEndpointRotateFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
