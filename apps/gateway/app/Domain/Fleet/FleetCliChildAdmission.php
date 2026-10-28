<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Actions\Fleet\AdmitFleetCliAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class FleetCliChildAdmission implements OperationChildAdmission
{
    public function __construct(private AdmitFleetCliAction $admit) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        if ($intent['kind'] !== 'fleet.cli_converge' || $intent['node_id'] === null || $intent['input'] !== []) {
            throw new ResourceOperationException('operation.execution_forbidden', 'A CLI child accepts only its server-selected Node and empty input.', 422);
        }

        return $this->admit->execute(Node::query()->findOrFail($intent['node_id']), 'swamp');
    }
}
