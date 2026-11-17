<?php

declare(strict_types=1);

namespace Orbit\Extensions\Conn\Domain;

use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Domain\Operations\OperationKinds;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Database\Eloquent\Builder;
use Orbit\Extensions\Conn\Models\ConnProfileBinding;

final readonly class ConnLaunchAccess
{
    public function __construct(private NodeAccessAuthorizer $nodes) {}

    public function lockedProfileId(Node $caller): ?int
    {
        return ConnProfileBinding::query()->where('node_id', $caller->id)->lockForUpdate()->first()?->conn_profile_id;
    }

    public function allowsTarget(Node $caller, ?int $nodeId): bool
    {
        $target = $nodeId === null ? null : Node::query()->find($nodeId);

        return $target !== null && $this->nodes->allows($caller, $target);
    }

    /** @return Builder<Operation> */
    public function visible(Node $caller, int $profileId): Builder
    {
        return Operation::query()->where('kind', OperationKinds::ConnLaunch)->where('input->profile_id', $profileId)
            ->whereIn('node_id', $this->nodes->accessibleNodeIds($caller));
    }

    public function assertVisible(Node $caller, ?int $profileId, Operation $operation): void
    {
        abort_unless($profileId !== null && $profileId === ($operation->input['profile_id'] ?? null)
            && $operation->kind === OperationKinds::ConnLaunch && $this->allowsTarget($caller, $operation->node_id), 404);
    }
}
