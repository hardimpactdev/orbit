<?php

declare(strict_types=1);

namespace App\Actions\Routes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** B binds the parent's exact publication input and Node before owning admission. */
final readonly class RouteChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartOperationAction $operations) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        if (! in_array($intent['kind'], ['route.caddy_converge', 'route.private_dns_publish'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not an owned Node publication kind.', 422);
        }
        $node = Node::query()->findOrFail(ValidatedData::integer($intent['node_id']));

        return $this->operations->execute($intent['kind'], 'node_route_projection', $node->id, $node->id,
            $intent['input'], $parent->triggered_by, $parent->request_id);
    }
}
