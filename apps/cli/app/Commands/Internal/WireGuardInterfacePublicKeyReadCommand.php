<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Network\LocalWireGuardInterfacePublicKeyReadAction;
use App\Services\Network\LocalWireGuardInterfacePublicKeyReadFailure;

final class WireGuardInterfacePublicKeyReadCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:wireguard-interface-public-key:read {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Read the local Orbit WireGuard interface public key';

    public function handle(LocalWireGuardInterfacePublicKeyReadAction $reader): int
    {
        if (! $this->verifyOperationToken('internal:wireguard-interface-public-key:read')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($reader->run());
        } catch (LocalWireGuardInterfacePublicKeyReadFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
