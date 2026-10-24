<?php

declare(strict_types=1);

namespace App\Infrastructure\GatewayReleases;

use App\Domain\AgentView\AgentProcessView;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\Processes\ProcessRuntime;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Gateway\GatewayHorizon;
use App\Models\Node;
use App\Models\Process;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Moves the Gateway scheduler to the new release without cutting off a scheduled command. The scheduler is the
 * Gateway Node's systemd Process that runs `schedule:work` in the Gateway application directory.
 *
 * 1. `schedule:interrupt` ends the current minute's repeating events, such as `tasks:tick`, after their current run.
 * 2. SIGTERM goes to the `schedule:work` main process only. It stops starting new `schedule:run` processes, waits for
 *    the running ones, and exits. A long command, such as a development deploy, finishes on the release it started on.
 * 3. When it has exited, the unit starts again (or systemd already restarted it). The stable application path now
 *    links to the new release.
 *
 * Every scheduled command ran to its end, so each released its own overlap mutex, and no mutex is cleared. Only when
 * the drain outlasts its limit does the handoff take the `tasks:tick` lock, stop the unit, which ends what still runs,
 * clear the overlap mutexes those commands can no longer release, and start it. The result says which happened.
 */
final readonly class GatewaySchedulerHandoff
{
    /** The cache lock that `tasks:tick` holds while it works, for up to 300 seconds. */
    public const string TickLock = 'orbit:tasks:tick';

    /** The default time the old scheduler gets to finish its running commands. */
    public const int DrainSeconds = 600;

    /** @var Closure(): void */
    private Closure $clearMutexes;

    /** @param (Closure(): void)|null $clearMutexes clears the schedule's overlap mutexes */
    public function __construct(
        private AgentCommandRunner $agents,
        private string $applicationPath,
        private int $tickWaitSeconds = 330,
        private int $drainSeconds = self::DrainSeconds,
        ?Closure $clearMutexes = null,
    ) {
        $this->clearMutexes = $clearMutexes ?? static function (): void {
            Artisan::call('schedule:clear-cache');
        };
    }

    /**
     * The Process that runs the Gateway scheduler. Every Gateway runs one ([Tasks](/reference/tasks)), so a missing one,
     * or one outside the Gateway application path, fails the release instead of leaving the old code scheduling.
     *
     * @throws GatewayReleaseException
     */
    public function process(Node $gateway): Process
    {
        $application = rtrim($this->applicationPath, '/');
        $schedulers = $this->systemdProcesses($gateway)->filter(static function (Process $process): bool {
            $command = $process->runtime_config['command'] ?? null;

            return is_array($command) && in_array('schedule:work', $command, true);
        });
        $scheduler = $schedulers->first(static fn (Process $process): bool => rtrim($process->working_directory ?? '', '/') === $application);

        if ($scheduler instanceof Process) {
            return $scheduler;
        }

        $found = $schedulers->first();

        throw new GatewayReleaseException(
            step: 'handoff',
            errorCode: $found instanceof Process ? 'gateway.release_scheduler_mismatch' : 'gateway.release_scheduler_missing',
            message: $found instanceof Process
                ? "The Gateway scheduler Process [{$found->name}] runs in [{$found->working_directory}], not in [{$application}], so it would keep the old code."
                : 'The Gateway Node has no systemd Process that runs schedule:work. Create it in the Gateway application directory before releasing.',
            status: 500,
        );
    }

    /**
     * @return array{outcome: string, unit: string, drain: array{outcome: string, waited_ms: int, running: list<string>, stopped?: list<string>}, restarted: list<string>}
     *
     * @throws GatewayReleaseException
     */
    public function handoff(Node $gateway): array
    {
        $process = $this->process($gateway);
        $unit = AgentProcessView::unitName($process).'.service';
        $started = false;

        try {
            Artisan::call('schedule:interrupt');
            $drain = $this->drain($gateway, $process);

            if ($drain['outcome'] === 'forced') {
                $this->forceRestart($gateway, $process);
            } else {
                $this->control($gateway, $process, 'gateway.start-scheduler');
            }

            $started = true;
        } finally {
            if (! $started) {
                // Whatever failed, the scheduler must not stay down. On an active unit this changes nothing.
                try {
                    $this->control($gateway, $process, 'gateway.start-scheduler');
                } catch (GatewayReleaseException) {
                    // Keep the failure that caused recovery, even when recovery also fails.
                }
            }
        }

        return ['outcome' => 'restarted', 'unit' => $unit, 'drain' => $drain, 'restarted' => $this->restartOthers($gateway, $process)];
    }

    /**
     * Other Gateway Node Processes that run from the Gateway application keep the old release until they restart.
     *
     * @return list<string> the restarted units
     */
    private function restartOthers(Node $gateway, Process $scheduler): array
    {
        $current = dirname(rtrim($this->applicationPath, '/'), 2);
        $restarted = [];

        foreach ($this->systemdProcesses($gateway) as $process) {
            $directory = rtrim($process->working_directory ?? '', '/');

            // Horizon moves with horizon:terminate, which lets its running jobs finish; a restart would kill them.
            if ($process->is($scheduler) || GatewayHorizon::runs($process) || ($directory !== $current && ! str_starts_with($directory, $current.'/'))) {
                continue;
            }

            $unit = AgentProcessView::unitName($process).'.service';
            $this->control($gateway, $process, 'gateway.restart-release-process');
            $restarted[] = $unit;
        }

        return $restarted;
    }

    /** @return Collection<int, Process> */
    private function systemdProcesses(Node $gateway): Collection
    {
        return Process::query()
            ->whereMorphedTo('owner', $gateway)
            ->orderBy('id')
            ->get()
            ->filter(static fn (Process $process): bool => $process->runtime === ProcessRuntime::Systemd)
            ->values();
    }

    /** @return array{outcome: string, waited_ms: int, running: list<string>, stopped?: list<string>, reason?: string} */
    private function drain(Node $gateway, Process $process): array
    {
        $result = $this->command($gateway, $process, 'gateway.drain-scheduler', ['drain_seconds' => $this->drainSeconds]);
        if (! in_array($result['outcome'] ?? null, ['drained', 'forced', 'not_running'], true)
            || ! is_int($result['waited_ms'] ?? null) || $result['waited_ms'] < 0
            || (isset($result['reason']) && $result['reason'] !== 'no_pcntl')) {
            throw $this->failed('The agent returned an invalid scheduler drain result.');
        }
        $drain = ['outcome' => $result['outcome'], 'waited_ms' => $result['waited_ms'], 'running' => $this->strings($result['running'] ?? null)];
        if ($result['outcome'] === 'forced' || array_key_exists('stopped', $result)) {
            $drain['stopped'] = $this->strings($result['stopped'] ?? null);
        }
        if (isset($result['reason'])) {
            $drain['reason'] = $result['reason'];
        }

        return $drain;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw $this->failed('The agent returned invalid scheduler members.');
        }
        $strings = [];
        foreach ($value as $text) {
            if (! is_string($text)) {
                throw $this->failed('The agent returned invalid scheduler members.');
            }
            $strings[] = $text;
        }

        return $strings;
    }

    /**
     * The drain outlasted its limit. Stopping the unit ends the commands that still run, so it waits for a running
     * tick first and then clears the overlap mutexes those commands can no longer release.
     */
    private function forceRestart(Node $gateway, Process $process): void
    {
        $lock = Cache::lock(self::TickLock, $this->tickWaitSeconds);

        try {
            $lock->block($this->tickWaitSeconds);
        } catch (LockTimeoutException) {
            throw new GatewayReleaseException(
                step: 'handoff',
                errorCode: 'gateway.release_scheduler_busy',
                message: "A tasks tick held its lock for more than {$this->tickWaitSeconds} seconds, so the scheduler was not stopped. It finishes its running commands and exits on its own.",
                status: 500,
            );
        }

        try {
            $this->control($gateway, $process, 'gateway.stop-scheduler');
            ($this->clearMutexes)();
            $this->control($gateway, $process, 'gateway.start-scheduler');
        } finally {
            $lock->release();
        }
    }

    private function control(Node $gateway, Process $process, string $command): void
    {
        if ($this->command($gateway, $process, $command) !== []) {
            throw $this->failed('The agent returned an invalid scheduler control result.');
        }
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function command(Node $gateway, Process $process, string $command, array $input = []): array
    {
        $name = AgentProcessView::unitName($process);
        if ($name === null) {
            throw $this->failed('The scheduler Process identity is invalid.');
        }
        try {
            return $this->agents->run($gateway, $command, ['process_id' => $process->id, 'name' => $name.'.service', ...$input]);
        } catch (AgentCommandRefused|AgentRunnerFailure $error) {
            throw new GatewayReleaseException('handoff', 'gateway.release_scheduler_failed', 'The native scheduler handoff failed: '.$error->getMessage(), 500, previous: $error);
        }
    }

    private function failed(string $message): GatewayReleaseException
    {
        return new GatewayReleaseException('handoff', 'gateway.release_scheduler_failed', $message, 500);
    }
}
