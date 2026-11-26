<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\ReceiptBoundDefinition;
use App\Domain\Engine\StepSpec;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Operations\OperationStore;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;
use LogicException;

final readonly class InstanceDestroyEngineFlow implements ReceiptBoundDefinition
{
    public const string Kind = 'instance.destroy';

    private const string Remove = 'remove';

    public function __construct(private DevelopmentInstanceDestroyProjection $projection) {}

    public function steps(Operation $operation): array
    {
        $container = self::container($operation);

        return [new StepSpec(self::Remove, ValidatedData::integer($operation->node_id), self::Kind, (string) ValidatedData::integer($container['instance_id']), budgetMs: 600_000, resendMs: 15_000)];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        return match ($step) {
            self::Remove => Arr::only(self::container($operation), ['project', 'name', 'pool', 'acl', 'inner_volume']),
            default => throw new LogicException("Unknown destroy step [{$step}]."),
        };
    }

    public function lockKey(Operation $operation): string
    {
        return OperationStore::lockKey('instance', $operation->target_id);
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException('Destroy has no local steps.');
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $this->projection->applyEngine($operation, $run);
    }

    /** @return array<string, mixed> */
    private static function container(Operation $operation): array
    {
        $input = $operation->input;
        $container = ValidatedData::object($input['container_destroy'] ?? []);
        $plan = ValidatedData::object($input['direct_destroy'] ?? []);
        $runtime = ValidatedData::object($plan['runtime'] ?? []);
        $database = ValidatedData::object($plan['database'] ?? []);
        $teardown = $plan['teardown'] ?? null;
        $members = InstanceLifecycleInput::members($input);
        $id = $operation->target_id;
        $name = 'instance-'.$id;
        $cloneSource = $container['clone_source_instance_id'] ?? null;

        if ($operation->target_type !== 'instance' || $id < 1 || ($input['instance_id'] ?? null) !== $id || ($operation->node_id ?? 0) < 1
            || ($input['node_id'] ?? null) !== $operation->node_id || ($plan['node_id'] ?? null) !== $operation->node_id
            || ($input['environment'] ?? null) !== 'development' || ($input['force'] ?? false) !== true
            || ($input['name'] ?? DefaultInstanceName::Name) === DefaultInstanceName::Name
            || ! is_int($cloneSource) || $cloneSource < 1 || $cloneSource === $id
            || isset($input['after_operation_id']) || isset($container['ci_hold'])
            || count($members) !== 1 || ($members[0]['instance_id'] ?? null) !== $id
            || isset($members[0]['route_id']) || ($input['additional_route_ids'] ?? null) !== [] || ($plan['routes'] ?? null) !== []
            || ($members[0]['run_teardown'] ?? null) !== false || ! is_array($teardown) || count($teardown) !== 1 || ! is_array($teardown[0] ?? null)
            || ($teardown[0]['instance_id'] ?? null) !== $id || ($teardown[0]['steps'] ?? null) !== []
            || ($runtime['processes'] ?? null) !== [] || ($runtime['schedules'] ?? null) !== []
            || ($database['connection_ids'] ?? null) !== [] || ($database['servers'] ?? null) !== [] || ($database['sqlite'] ?? null) !== []) {
            throw new EngineUnsupported('Only a forced development clone removal without other teardown runs on the engine.');
        }

        if (($container['instance_id'] ?? null) !== $id || ($container['name'] ?? null) !== $name || ($container['acl'] ?? null) !== $name
            || ! in_array($container['project'] ?? null, [InstanceHostIntent::Project, InstancePlacement::NestedProject], true)
            || ($container['pool'] ?? null) !== InstanceHostIntent::Pool
            || ($container['mirror'] ?? null) !== "/var/lib/orbit/instance-repositories/{$id}.git"
            || ($container['firewall_prefix'] ?? null) !== "orbit:instance-{$id}-"
            || ($container['asleep_marker'] ?? null) !== RuntimeHibernation::asleepPath(RuntimeHibernation::key($id))
            || (array_key_exists('inner_volume', $container) && $container['inner_volume'] !== NestedFleetInnerVolume::name($name))) {
            throw new EngineUnsupported('The saved clone identity is outside the signed removal contract.');
        }

        return $container;
    }
}
