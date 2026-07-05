<?php

declare(strict_types=1);

namespace App\Commands\Internal;

use App\Services\Apps\LocalAppSecurityRepairAction;
use App\Services\Apps\LocalAppSecurityRepairFailure;

final class AppSecurityRepairCommand extends InternalExecutorCommand
{
    #[\Override]
    protected $signature = 'internal:app-security:repair {user} {home} {path} {--operation-token=} {--json}';

    #[\Override]
    protected $description = 'Repair app runtime user and filesystem permissions on the local node';

    public function handle(LocalAppSecurityRepairAction $action): int
    {
        if (! $this->verifyOperationToken('internal:app-security:repair')) {
            return self::FAILURE;
        }

        try {
            return $this->emitInternalSuccess($action->repair(
                user: $this->argument('user'),
                home: $this->argument('home'),
                path: $this->argument('path'),
            ));
        } catch (LocalAppSecurityRepairFailure $failure) {
            return $this->renderFailure($failure->errorCode, $failure->getMessage(), $failure->meta);
        }
    }
}
