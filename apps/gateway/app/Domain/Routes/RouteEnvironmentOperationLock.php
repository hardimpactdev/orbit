<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use App\Support\ValidatedData;
use Closure;

/** Retains environment ownership between the Node-bound children of a Route parent. */
final readonly class RouteEnvironmentOperationLock implements InstanceEnvironmentOperationLock
{
    public function __construct(private InstanceEnvironmentOperationLock $locks) {}

    public function run(array $instanceIds, Closure $operation): mixed
    {
        return $this->locks->run($instanceIds, function () use ($instanceIds, $operation): mixed {
            $identity = app(OperationIdempotency::class);
            $current = request()->route('operation') ?? $identity->parent;
            $replay = $identity->kind === null ? null : $identity->replay($identity->kind);
            $ancestors = $replay === null ? [] : [$replay->id];
            while ($current instanceof Operation) {
                $ancestors[] = $current->id;
                $current = $current->parent_operation_id === null ? null : Operation::query()->find($current->parent_operation_id);
            }
            foreach (Operation::query()->whereIn('kind', RouteParentExecution::Kinds)->whereIn('status', ['queued', 'running', 'waiting'])->get() as $parent) {
                if (! in_array($parent->id, $ancestors, true) && array_intersect($instanceIds, self::instanceIds($parent)) !== []) {
                    throw new ResourceOperationException('env.operation_busy', 'An open Route Operation owns this Instance environment. Retry after it settles.', 409);
                }
            }

            return $operation();
        });
    }

    /** @return list<int> */
    public static function instanceIds(Operation $operation): array
    {
        $plan = ValidatedData::object($operation->state['route_plan'] ?? []);
        $input = $operation->input ?? [];
        $placements = RouteExecutionProof::objects($plan['placements'] ?? $input['instance_placements'] ?? []);

        return array_values(array_unique(array_filter([
            ...array_column($placements, 'instance_id'), ...ValidatedData::integerList($input['target_instance_ids'] ?? []),
            ...ValidatedData::integerList($input['previous_target_instance_ids'] ?? []), ...ValidatedData::integerList($input['removed_instance_ids'] ?? []),
            $input['instance_id'] ?? null,
        ], is_int(...))));
    }
}
