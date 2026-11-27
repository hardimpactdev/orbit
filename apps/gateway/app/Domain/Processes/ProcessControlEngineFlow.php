<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Engine\EngineDefinition;
use App\Domain\Engine\EngineRun;
use App\Domain\Engine\EngineUnsupported;
use App\Domain\Engine\StepSpec;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;
use LogicException;

/**
 * `process:start` and `process:stop` on the state machine (ADR 0271; docs/cli/process/engine/start.mmd and stop.mmd). One step:
 * the desired-state primitive `process.ensure_state` on the Process's Node, for a host systemd unit or a host Docker container.
 * The Node converges the run state and the boot policy and reads both back; the end records through the Process's own
 * projection, as a Swamp run's end did.
 *
 * Eligibility comes from the Process and its placement, for both verbs, so one Process never splits its lifecycle across
 * engines. These stay on Swamp (EngineUnsupported): a dev-server Process (`vp-dev`, `vite`: dev route, listener, awake marker),
 * a Process of an Instance outside app-dev (a production release layout), any Process in a container (an Instance container's
 * app-account move is a script; a Gateway release container drains), and a Node's host systemd Horizon Process, whose stop
 * waits for its drain.
 */
final readonly class ProcessControlEngineFlow implements EngineDefinition
{
    /** @var list<string> */
    public const array Kinds = ['process.start', 'process.stop'];

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

        // As Swamp's `Set boot policy`: enabled at boot only while running and not on demand.
        return [...$order, 'boot' => $running && $input['on_demand'] !== true ? 'enabled' : 'disabled'];
    }

    public function local(Operation $operation, string $step, EngineRun $run): string
    {
        throw new LogicException('The Process flow has no Gateway step.');
    }

    /**
     * The public result is the one Swamp's native checks give for the same input: a failed step is `operation.native_failed`,
     * named by the Swamp step it stands for. The primitive's own code stays on the engine step and in the failure's detail.
     */
    public function finish(Operation $operation, EngineRun $run): void
    {
        $failed = $run->failed();
        $data = match (true) {
            $failed !== null => ['status' => 'failed', 'error_code' => 'operation.native_failed',
                'failure' => ['failed_step' => self::swampStep($operation, (string) $failed->error_code),
                    'detail' => self::Action.' '.$failed->error_code, 'stderr_recorded' => false]],
            $run->succeeded() => ['status' => 'succeeded', 'error_code' => null],
            default => ['status' => 'cancelled', 'error_code' => null],
        };
        $this->projection->apply($operation, [...$data, ...$run->times()]);
    }

    /**
     * The step of operation-process-start or -stop whose failure the primitive's phase stands for: the change, a stop's plain
     * `Stop the unit`, the check that reads the state back, or the assert on what it read.
     */
    private static function swampStep(Operation $operation, string $code): string
    {
        $docker = ($operation->input['runtime'] ?? null) === ProcessRuntime::Docker->value;
        $verb = $operation->kind === 'process.stop' ? 'Stop' : 'Start';

        return match (true) {
            $code === 'process.state_mismatch' => 'Verify the state',
            $code === 'process.read_failed' => $docker ? 'Check the container' : 'Check the unit',
            $code === 'process.unit_stop_failed' => 'Stop the unit',
            $docker => "{$verb} the container",
            default => "{$verb} the Process",
        };
    }

    /** The Process's Node, once the Process and its placement are a shape the primitive covers. */
    private static function admitted(Operation $operation): int
    {
        $input = $operation->input;
        $node = $operation->node_id ?? 0;
        if (! in_array($operation->kind, ['process.start', 'process.stop'], true) || $operation->target_type !== 'process'
            || $operation->target_id < 1 || ($input['process_id'] ?? null) !== $operation->target_id || $node < 1 || ($input['node_id'] ?? null) !== $node
            || ! is_string($input['name'] ?? null) || ! is_bool($input['on_demand'] ?? null)) {
            throw new EngineUnsupported('The Process intent is outside the signed control contract.');
        }
        $runtime = $input['runtime'] ?? null;
        if (! in_array($runtime, [ProcessRuntime::Systemd->value, ProcessRuntime::Docker->value], true)
            || ($runtime === ProcessRuntime::Docker->value && ! is_string($input['image'] ?? null))) {
            throw new EngineUnsupported('The Process runtime is outside the signed control contract.');
        }
        if (isset($input['container']) || isset($input['vite_plan']) || ($input['vite'] ?? null) !== null || isset($input['directory'])) {
            throw new EngineUnsupported('A container, Vite or release-layout plan stays on Swamp.');
        }
        $process = Process::query()->find($operation->target_id);
        if ($process === null || $process->runtime->value !== $runtime || $process->isDevServerPreset()) {
            throw new EngineUnsupported('A dev-server Process stays on Swamp.');
        }
        if ($runtime === ProcessRuntime::Systemd->value && $process->owner_type === Node::class && GatewayHorizon::runs($process)) {
            // The renderer's own drain condition: a Node's host Horizon unit carries the long-drain stop directives, so its stop
            // waits for the drain; its start stays with it. A Gateway release container is a `container` intent, refused above.
            throw new EngineUnsupported('A drain-waiting Process stays on Swamp.');
        }
        $owned = match (true) {
            Instance::isMorphType($process->owner_type) => Instance::query()->find($process->owner_id)?->placedOnAppDev() === true,
            default => $process->owner_type === Node::class,
        };
        if (! $owned) {
            throw new EngineUnsupported('Only a Node Process or an app-dev Instance Process runs on the engine.');
        }

        return $node;
    }
}
