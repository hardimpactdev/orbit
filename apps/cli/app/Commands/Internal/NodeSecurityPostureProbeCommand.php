<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Nodes\LocalNodeSecurityPostureProbe;
use App\Services\Nodes\LocalNodeSecurityPostureProbeFailure;

final class NodeSecurityPostureProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:node-security-posture:probe {managedUser} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe local node security posture through fixed argv operations';

    public function handle(LocalNodeSecurityPostureProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:node-security-posture:probe')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($probe->check($this->argument('managedUser')));
        } catch (LocalNodeSecurityPostureProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
