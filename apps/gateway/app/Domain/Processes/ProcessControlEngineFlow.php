<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Models\Instance;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use LogicException;

/**
 * `process:start` and `process:stop` on the state machine (ADR 0271; docs/cli/process/engine/start.mmd and stop.mmd). One step:
 * the desired-state primitive `process.ensure_state` on the Process's Node, for a host systemd unit, a host Docker container or
 * a unit inside an Instance container. The Node converges the run state and the boot policy and reads both back; the end records
 * through the Process's own projection, as a Swamp run's end did.
 *
 * A plan whose Swamp checks the primitive does not reproduce stays on Swamp (EngineUnsupported): a Vite dev Process (dev route,
 * listener and awake marker), a production release layout, a Gateway release-container Process, and a host Process that runs
 * Horizon, whose stop waits for its drain.
 */
final readonly class ProcessControlEngineFlow implements EngineDefinition
{
    public const string Action = 'process.ensure_state';

    private const string Step = 'converge';

    public function __construct(private ProcessControlProjection $projection) {}

    public function steps(Operation $operation): array
    {
        $node = self::admitted($operation);

        // The target is the Node's part for this Process: two orders for one Process run one after the other.
        return [new StepSpec(self::Step, $node, self::Action, "{$node}:process-{$operation->target_id}", budgetMs: 120_000, resendMs: 15_000)];
    }

    public function input(Operation $operation, string $step, EngineRun $run): array
    {
        if ($step !== self::Step) {
            throw new LogicException("Unknown Process step [{$step}].");
        }
        self::admitted($operation);
        $input = $operation->input;
        $running = $operation->kind === 'process.start';
        $order = ['process_id' => $operation->target_id, 'runtime' => $input['runtime'], 'name' => $input['name'], 'state' => $running ? 'running' : 'stopped'];
        if ($input['runtime'] === ProcessRuntime::Docker->value) {
            return [...$order, 'image' => ValidatedData::string($input['image'])];
        }
        // As Swamp's `Set boot policy`: enabled at boot only while running and not on demand. A unit in an Instance container
        // follows its state, as instance-container-process.yml does.
        $guest = ValidatedData::object($input['container'] ?? []);
        $order['boot'] = $running && ($guest !== [] || $input['on_demand'] !== true) ? 'enabled' : 'disabled';
        if ($guest !== []) {
            $account = ValidatedData::object($guest['app_account']);
            $order['container'] = ['project' => $guest['project'], 'name' => $guest['name'], 'account_path' => ValidatedData::string($account['path']),
                'account' => ['blob' => ['content' => ValidatedData::string($account['content'])]]];
        }

        return $order;
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException('The Process flow has no Gateway step.');
    }

    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => $failed->error_code,
                'failure' => ['failed_step' => $failed->name, 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => 'operation.cancelled'],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /** The Process's Node, once the admitted plan is a shape the primitive covers. */
    private static function admitted(Operation $operation): int
    {
        $input = $operation->input;
        $node = $operation->node_id ?? 0;
        if (! in_array($operation->kind, ['process.start', 'process.stop'], true) || $operation->target_type !== 'process'
            || $operation->target_id < 1 || ($input['process_id'] ?? null) !== $operation->target_id || $node < 1 || ($input['node_id'] ?? null) !== $node
            || ! is_string($input['name'] ?? null) || ! is_bool($input['on_demand'] ?? null)) {
            throw new EngineUnsupported('The Process intent is outside the signed control contract.');
        }
        if (isset($input['vite_plan']) || ($input['vite'] ?? null) !== null || ($input['variant'] ?? null) === 'vite' || isset($input['directory'])) {
            throw new EngineUnsupported('A Vite dev Process and a production release layout stay on Swamp.');
        }
        $guest = $input['container'] ?? null;
        $runtime = $input['runtime'] ?? null;
        if ($guest !== null) {
            $guest = ValidatedData::object($guest);
            $account = $guest['app_account'] ?? null;
            if (isset($guest['release']) || ! is_int($guest['instance_id'] ?? null) || $runtime !== ProcessRuntime::Systemd->value
                || ! is_string($guest['project'] ?? null) || ($guest['name'] ?? null) !== 'instance-'.$guest['instance_id']
                || ! is_array($account) || ! is_string($account['path'] ?? null) || ! is_string($account['content'] ?? null)) {
                throw new EngineUnsupported('Only a unit in a development Instance container runs on the engine.');
            }
        } elseif (! in_array($runtime, [ProcessRuntime::Systemd->value, ProcessRuntime::Docker->value], true)
            || ($runtime === ProcessRuntime::Docker->value && ! is_string($input['image'] ?? null))) {
            throw new EngineUnsupported('The Process runtime is outside the signed control contract.');
        }
        $process = Process::query()->find($operation->target_id);
        if ($process === null || ($guest === null && ! Instance::isMorphType($process->owner_type) && GatewayHorizon::runs($process))) {
            // Horizon's stop waits for its drain (SystemdProcessRenderer's long-drain directives); start stays with it.
            throw new EngineUnsupported('A drain-waiting Process stays on Swamp.');
        }

        return $node;
    }
}
