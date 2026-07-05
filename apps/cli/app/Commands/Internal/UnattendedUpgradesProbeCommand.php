<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Updates\LocalUnattendedUpgradesProbe;
use App\Services\Updates\LocalUnattendedUpgradesProbeFailure;

final class UnattendedUpgradesProbeCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:unattended-upgrades:probe {autoHash} {unattendedHash} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Probe unattended-upgrades posture through fixed local checks';

    public function handle(LocalUnattendedUpgradesProbe $probe): int
    {
        if (! $this->verifyOperationToken('internal:unattended-upgrades:probe')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($probe->check(
                autoHash: $this->argument('autoHash'),
                unattendedHash: $this->argument('unattendedHash'),
            ));
        } catch (LocalUnattendedUpgradesProbeFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
