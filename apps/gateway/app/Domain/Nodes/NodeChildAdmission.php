<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Actions\Nodes\StartNodeConvergeAction;
use App\Actions\Nodes\StartNodePhpPackagesAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Uses Node admissions; the kit owns parent stamps, idempotency and inherited target holds. */
final readonly class NodeChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartNodeConvergeAction $footprint, private StartNodePhpPackagesAction $php) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $node = Node::query()->findOrFail($intent['node_id']);
        $input = $intent['input'];

        if ($intent['kind'] === 'node.converge') {
            $force = $input['force_artifacts'] ?? null;
            $firstVisit = $input['first_visit'] ?? null;
            if (! is_bool($force) || ! is_bool($firstVisit)) {
                throw new ResourceOperationException('operation.child_forbidden', 'Footprint child flags must be explicit booleans.', 422);
            }

            return $this->footprint->execute($node, $force, $firstVisit);
        }

        return match ($intent['kind']) {
            'node.prepare_php_packages' => ($input['profile'] ?? null) === 'app-prod'
                ? $this->php->execute($node, ValidatedData::stringList($input['versions']))
                : throw new ResourceOperationException('operation.child_forbidden', 'Only the app-prod PHP package profile is admitted.', 422),
            default => throw new ResourceOperationException('operation.child_forbidden', 'This Node child kind has no owning admission.', 422),
        };
    }
}
