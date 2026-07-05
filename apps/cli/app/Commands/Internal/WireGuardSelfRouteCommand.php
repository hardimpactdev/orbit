<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Network\LocalWireGuardSelfRouteAction;
use App\Services\Network\LocalWireGuardSelfRouteFailure;

final class WireGuardSelfRouteCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:wireguard-self-route {address} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Inspect the local WireGuard self route';

    public function handle(LocalWireGuardSelfRouteAction $routes): int
    {
        if (! $this->verifyOperationToken('internal:wireguard-self-route')) {
            return self::FAILURE;
        }

        try {
            $result = $routes->run($this->argument('address'));
        } catch (LocalWireGuardSelfRouteFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }

        return $this->emitInternalSuccess($result['data'], $result['meta']);
    }
}
