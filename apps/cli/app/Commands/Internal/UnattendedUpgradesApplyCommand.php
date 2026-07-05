<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Updates\LocalUnattendedUpgradesApply;
use App\Services\Updates\LocalUnattendedUpgradesApplyFailure;

final class UnattendedUpgradesApplyCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:unattended-upgrades:apply {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Apply unattended security upgrades through fixed local commands';

    public function handle(LocalUnattendedUpgradesApply $apply): int
    {
        if (! $this->verifyOperationToken('internal:unattended-upgrades:apply')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($apply->run());
        } catch (LocalUnattendedUpgradesApplyFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
