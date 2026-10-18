<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Actions\Processes\CreateProcessAction;
use App\Data\Processes\AddProcessData;
use App\Domain\Processes\DesiredProcessState;
use App\Domain\Processes\ProcessOperationException;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessRuntimeManager;
use App\Domain\Processes\ProcessTargetType;
use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Illuminate\Support\Facades\Artisan;

/**
 * Laravel Horizon runs as a Process of the Gateway Node (docs/reference/gateway-queue.md#horizon). A release creates it
 * once and afterwards runs `horizon:terminate`: the master waits for its running jobs, exits, and the Process's restart
 * policy starts it again on the new release.
 */
final readonly class GatewayHorizon
{
    public const string Name = 'horizon';

    public function __construct(private CreateProcessAction $processes, private ProcessRuntimeManager $runtime) {}

    /** The longest job of any supervisor: how long a terminated master may wait before it exits. */
    public static function longestJobSeconds(): int
    {
        $timeouts = array_map(
            static fn (mixed $supervisor): int => is_array($supervisor) && is_int($supervisor['timeout'] ?? null) ? $supervisor['timeout'] : 0,
            (array) config('horizon.defaults', []),
        );

        return max([0, ...$timeouts]);
    }

    /** A Process that runs `<php> artisan horizon`. */
    public static function runs(Process $process): bool
    {
        $command = $process->runtime_config['command'] ?? null;

        return is_array($command) && array_slice($command, 1, 2) === ['artisan', 'horizon'];
    }

    /**
     * Moves Horizon to the current release:
     *
     * - `created`: the Gateway had no Process that runs Horizon. It gets one beside the scheduler, with its PHP and
     *   directory, started at once.
     * - `terminated`: the unit is active. `horizon:terminate` lets the master finish its running jobs and exit, and
     *   the Process's restart policy starts it again on the current release. The unit is never restarted: that would
     *   kill running jobs.
     * - `started`: the unit is not active, for example after a failed start, a crash loop, or a stop after a switch
     *   back. `horizon:terminate` would reach no master, so the Process is converged and started.
     *
     * @param  Closure(): Process  $scheduler  the scheduler Process, read only to create Horizon beside it
     * @return 'created'|'terminated'|'started'|'none'
     */
    public function converge(Node $gateway, Closure $scheduler): string
    {
        if (! GatewayQueueRedis::configured()) {
            return 'none';
        }

        $process = self::find($gateway);

        if (! $process instanceof Process) {
            $this->create($gateway, $scheduler());

            return 'created';
        }

        if ($this->runtime->status($process) === 'active') {
            Artisan::call('horizon:terminate');

            return 'terminated';
        }

        $this->start($process);

        return 'started';
    }

    public static function find(Node $gateway): ?Process
    {
        return Process::query()->whereMorphedTo('owner', $gateway)
            ->where('runtime_config->command[1]', 'artisan')
            ->where('runtime_config->command[2]', 'horizon')
            ->orderBy('id')
            ->first();
    }

    private function create(Node $gateway, Process $scheduler): void
    {
        $command = $scheduler->runtime_config['command'] ?? null;
        $this->processes->execute(new AddProcessData(
            targetType: ProcessTargetType::Node,
            targetId: $gateway->id,
            name: self::Name,
            runtime: ProcessRuntime::Systemd,
            command: [is_array($command) && is_string($command[0] ?? null) ? $command[0] : 'php', 'artisan', 'horizon'],
            image: null,
            workingDirectory: $scheduler->working_directory,
            environment: [],
            ports: [],
            volumes: [],
            restartPolicy: 'always',
            start: true,
        ));
    }

    /** Installs the unit again when it is missing, and starts it, as the Process's desired state. */
    private function start(Process $process): void
    {
        $process->update(['desired_state' => DesiredProcessState::Running]);

        try {
            $this->runtime->converge($process);
        } catch (ProcessOperationException $exception) {
            $process->update(['status' => LifecycleStatus::Failed, 'failed_step' => $exception->step, 'error_code' => $exception->errorCode]);

            throw $exception;
        }

        $process->update(['status' => LifecycleStatus::Active, 'failed_step' => null, 'error_code' => null]);
    }
}
