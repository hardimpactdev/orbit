<?php

declare(strict_types=1);

namespace App\Domain\Tools;

use App\Actions\Tools\ConvergeToolManagerAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Records manager intent through the normal action after Kit B authorizes the child. */
final readonly class ToolManagerChildAdmission implements OperationChildAdmission
{
    public function __construct(private ConvergeToolManagerAction $converge) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $input = $intent['input'];
        if ($intent['kind'] !== 'tool.manager_converge' || $intent['node_id'] === null
            || count($input) !== 2 || ! is_string($input['manager'] ?? null)
            || ! is_bool($input['image_provides_manager'] ?? null)) {
            throw new ResourceOperationException('operation.child_forbidden', 'The manager child requires a Node and exact manager intent.', 422);
        }

        $manager = ToolManagerName::tryFrom($input['manager']);
        $node = Node::query()->find($intent['node_id']);
        if ($manager === null || $node === null) {
            throw new ResourceOperationException('operation.child_forbidden', 'The manager child requires a known manager and stored Node.', 422);
        }

        return $this->converge->execute($node, $manager, $input['image_provides_manager']);
    }
}
