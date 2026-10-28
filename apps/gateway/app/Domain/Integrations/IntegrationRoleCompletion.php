<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Models\Operation;

/** The Node parent owns assignment, Settings, Route associations and finalization. */
final readonly class IntegrationRoleCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void {}
}
