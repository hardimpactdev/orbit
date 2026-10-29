<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class NodeConvergeChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartNodeConvergeAction $admission, private PrepareNodeFootprintCommand $preparation) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $input = $intent['input'];
        if ($intent['kind'] !== 'node.converge' || count($input) !== 2
            || ! is_bool($input['force_artifacts'] ?? null) || ! is_bool($input['first_visit'] ?? null)) {
            throw new ResourceOperationException('operation.child_forbidden', 'Footprint child flags must be explicit booleans.', 422);
        }
        $operation = $this->admission->execute(Node::query()->findOrFail($intent['node_id']), $input['force_artifacts'], $input['first_visit']);
        $result = $this->preparation->run($operation, $input);
        if (($result['prepared'] ?? null) !== true) {
            throw new ResourceOperationException('node.footprint_failed', 'The Node child could not record its immutable footprint.', 409);
        }

        return $operation;
    }
}
