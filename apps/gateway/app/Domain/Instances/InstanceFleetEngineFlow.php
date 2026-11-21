<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\StepSpec;
use App\Domain\Operations\OperationStatus;
use App\Models\Operation;
use App\Support\ValidatedData;
use LogicException;

/**
 * `instance.fleet` on the engine ({@see InstanceFleetOperation}): one step, `fleet`, the executor's `instance.fleet_ready` on the
 * Node's part for this copy, so it locks no step of the copy. Its end records the fleet on the Instance's runtime.
 */
final readonly class InstanceFleetEngineFlow implements EngineDefinition
{
    /** The bound of the fleet's readiness wait, as the Swamp fleet start's (`fleet_ready_seconds`). */
    public const int ReadySeconds = 300;

    /** The code of a copy whose tunnel unit failed at boot: the fleet has no tunnel to wait over. */
    public const string TunnelFailed = 'instance.fleet_tunnel_failed';

    public function __construct(private InstanceFleetOperation $fleet) {}

    public function steps(Operation $operation): array
    {
        $node = ValidatedData::integer($operation->node_id);
        $id = ValidatedData::integer($operation->target_id);

        return [new StepSpec('fleet', $node, 'instance.fleet_ready', "{$node}:fleet-{$id}", budgetMs: (self::ReadySeconds + 120) * 1000, resendMs: 60_000)];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        if ($step !== 'fleet') {
            throw new LogicException("Step [{$step}] is not a fleet step.");
        }

        // The nested Gateway answers over the operator tunnel by its own name, as the proof reads it (instance-fleet.yml `fleet_curl`).
        return ['project' => ValidatedData::string($operation->input['project'] ?? null), 'name' => ValidatedData::string($operation->input['name'] ?? null),
            'guests' => array_values(NestedFleetLayout::Guests), 'hub' => NestedFleetLayout::operator()['hub'], 'host' => 'gateway.orbit',
            'nodes' => array_keys(NestedFleetLayout::Guests), 'seconds' => self::ReadySeconds];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException("Step [{$step}] runs on a Node.");
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $output = json_decode($run->output('fleet') ?? 'null', true);
        $answer = is_array($output) ? ($output['fleet'] ?? null) : null;
        // A seed whose tunnel unit is gone or failed again after its restart answers `seed_without_tunnel`: the fallback starts the fleet.
        [$status, $code] = match (true) {
            $failed !== null => [OperationStatus::Failed, $failed->error_code ?? 'instance.container_fleet_resume_failed'],
            $run->succeeded() && $answer === 'ready' => [OperationStatus::Succeeded, null],
            $run->succeeded() => [OperationStatus::Failed, self::TunnelFailed],
            default => [OperationStatus::Cancelled, 'operation.cancelled'],
        };
        $this->fleet->close($operation, $status, $code, $failed !== null ? 'fleet' : null);
    }
}
