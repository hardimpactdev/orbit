<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Operations\OperationCompletion;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Packages have no Gateway projection; success still requires the installer's typed outcome. */
final readonly class NodePhpPackagesOperationCompletion implements OperationCompletion
{
    public function complete(Operation $operation, OperationOutcome $outcome): void
    {
        if ($outcome->status === OperationStatus::Succeeded
            && ! in_array($outcome->result['outcome'] ?? null, ['current', 'applied'], true)) {
            throw new ResourceOperationException('operation.completion_unproven', 'The PHP installer did not prove that packages are ready.', 409);
        }
    }
}
