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
    public const string Target = 'conn_profile';

    public function __construct(private NodeAccessAuthorizer $nodes) {}

    public static function operationId(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_unless(is_int($id), 404);

        return $id;
    }

    public function profileId(Node $caller, bool $lock = false): ?int
    {
        $binding = ConnProfileBinding::query()->where('node_id', $caller->id);
        if ($lock) {
            $binding->lockForUpdate();
        }

        return $binding->first()?->conn_profile_id;
    }

    public function allowsTarget(Node $caller, ?int $nodeId): bool
    {
        $target = $nodeId === null ? null : Node::query()->find($nodeId);

        return $target !== null && $this->nodes->allows($caller, $target);
    }

    /** @return Builder<Operation> */
    public function visible(Node $caller, int $profileId): Builder
    {
        return Operation::query()->where('kind', OperationKinds::ConnLaunch)->where('target_type', self::Target)->where('target_id', $profileId)
            ->whereIn('node_id', $this->nodes->accessibleNodeIds($caller));
    }

    public function assertVisible(Node $caller, ?int $profileId, Operation $operation): void
    {
        abort_unless($profileId !== null && $operation->target_type === self::Target && $operation->target_id === $profileId
            && $operation->kind === OperationKinds::ConnLaunch && $this->allowsTarget($caller, $operation->node_id), 404);
    }
}
