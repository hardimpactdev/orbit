<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;

/**
 * A clone's `instance.complete` with nothing left on its Node (docs/reference/development-containers.md#complete-a-clone): no database,
 * no Route to publish, no fleet to start, and a services part that the default's seed already holds (the create's `services: seed`).
 * The engine runs it with no step, so it ends at once and its projection makes the clone active, without a Swamp run. Any other
 * completion starts on Swamp ([EngineUnsupported]).
 */
final readonly class InstanceCompleteEngineFlow implements EngineDefinition
{
    public function __construct(private InstanceCompleteProjection $projection) {}

    public function steps(Operation $operation): array
    {
        if (! self::nothingLeft($operation)) {
            throw new EngineUnsupported('A completion with work on its Node runs on Swamp.');
        }

        return [];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        throw new LogicException("Step [{$step}] is not a completion step.");
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException("Step [{$step}] is not a completion step.");
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $data = $run->succeeded() && $operation->cancel_requested_at === null
            ? ['status' => 'succeeded', 'error_code' => null, 'result' => ['instance_id' => $operation->target_id, 'ready' => true]]
            : ['status' => 'cancelled', 'error_code' => 'operation.cancelled', 'result' => ['instance_id' => $operation->target_id, 'ready' => false]];
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /** Whether the completion has nothing to do on its Node: what its Swamp run would skip, and the services part the seed holds. */
    public static function nothingLeft(Operation $operation): bool
    {
        $input = $operation->input;
        $plan = ValidatedData::object($input['direct_provisioning'] ?? []);
        $container = ValidatedData::object($plan['container'] ?? []);
        $create = Operation::query()->find(ValidatedData::integer($input['create_operation_id'] ?? null));
        $instance = Instance::query()->find($operation->target_id);
        $runtime = $instance?->runtime()->first();
        if ($instance === null || $runtime === null || ($create?->result['services'] ?? null) !== 'seed') {
            return false;
        }

        return ($plan['database'] ?? null) === null && ($plan['server_database'] ?? null) === null && ($plan['server_valkey'] ?? null) === null
            && ($input['route_create'] ?? null) === null && ($container['processes'] ?? []) === []
            && ! NativeInstanceCompleteInput::startsFleet($operation, $instance, $runtime);
    }
}
