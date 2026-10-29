<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\StartNodePhpPackagesAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class NodePhpPackagesChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartNodePhpPackagesAction $packages) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $input = $intent['input'];
        if ($intent['kind'] !== 'node.prepare_php_packages' || count($input) !== 2
            || ($input['profile'] ?? null) !== 'app-prod' || ! is_array($input['versions'] ?? null)) {
            throw new ResourceOperationException('operation.child_forbidden', 'Only the recorded production PHP package selection is admitted.', 422);
        }

        return $this->packages->execute(Node::query()->findOrFail($intent['node_id']), $input['versions']);
    }
}
