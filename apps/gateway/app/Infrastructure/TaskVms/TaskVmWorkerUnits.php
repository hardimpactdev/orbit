<?php

declare(strict_types=1);

namespace App\Infrastructure\TaskVms;

use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\TaskVms\TaskVmError;
use App\Domain\TaskVms\TaskVmQueue;
use App\Domain\TaskVms\TaskVmSettings;
use App\Infrastructure\Gateway\GatewayApplicationPath;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Shared\StoredValue;
use App\Models\TaskVm;
use Closure;
use Illuminate\Support\Facades\Artisan;
use RuntimeException;
use Throwable;

/**
 * The queue workers of task VM jobs: one systemd unit on the Gateway host for each {@see TaskVmQueue}. They run outside
 * the scheduler, so a long scheduled command never delays a job, and an idle worker finds a new job within a second.
 *
 * `orbit:gateway-web` and the scheduler phase of each release's runtime handoff converge them. After the install,
 * `queue:restart` lets each worker finish its running job on the release it started on and exit. systemd starts it
 * again from the stable application path, so it then runs the new release. No release cuts off or waits for a job.
 */
final readonly class TaskVmWorkerUnits
{
    /** How long systemd waits for a worker after SIGTERM: longer than any job, so a stop lets the running job finish. */
    public const int StopSeconds = 1800;

    /** @var Closure(): void */
    private Closure $restartWorkers;

    /** @param  (Closure(): void)|null  $restartWorkers  asks the running workers to exit after their current job */
    public function __construct(
        private ProcessRunner $processes,
        private string $phpBinary = PHP_BINARY,
        private string $applicationPath = '',
        private string $orbitHome = '',
        private string $user = 'orbit',
        private string $unitDirectory = '/etc/systemd/system',
        ?Closure $restartWorkers = null,
    ) {
        $this->restartWorkers = $restartWorkers ?? static function (): void {
            Artisan::call('queue:restart');
        };
    }

    /**
     * Installs and starts the workers while the Gateway can have task VM jobs. Otherwise it stops and removes the units
     * that exist, and changes nothing on a Gateway that never had them.
     *
     * @return 'started'|'stopped'|'none'
     *
     * @throws NodeProvisioningException
     */
    public function converge(): string
    {
        return self::wanted() ? $this->start() : $this->remove();
    }

    /**
     * Task VMs are enabled, the UpCloud token file is set, or a task VM is not destroyed yet: the cases in which the
     * scheduler queues task VM jobs, and the drain of what remains after the switch went off.
     */
    public static function wanted(): bool
    {
        $schedule = TaskVmSettings::scheduled();

        return $schedule->enabled || $schedule->upcloudConfigured || TaskVm::query()->live()->exists();
    }

    public function unitName(TaskVmQueue $queue): string
    {
        return 'orbit-queue-'.$queue->value.'.service';
    }

    public function render(TaskVmQueue $queue, string $application, string $orbitHome): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit task VM queue worker ('.$queue->value.')',
            'After=network-online.target',
            'Wants=network-online.target',
            '',
            '[Service]',
            'Type=simple',
            'User='.$this->user,
            'WorkingDirectory='.$this->escapeDirectivePath($application),
            'Environment=ORBIT_HOME='.$this->escapeDirectivePath($orbitHome),
            'ExecStart='.implode(' ', array_map($this->quoteArgument(...), [
                $this->phpBinary,
                $application.'/artisan',
                'queue:work',
                TaskVmQueue::Connection,
                '--queue='.$queue->value,
                '--sleep=1',
                '--timeout='.$queue->workerTimeout(),
            ])),
            'Restart=always',
            'RestartSec=5',
            // SIGTERM to the worker alone: it finishes its job and exits. The job's own processes, such as a git push,
            // keep running until then.
            'KillMode=mixed',
            'TimeoutStopSec='.self::StopSeconds,
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);
    }

    /** @return 'started' */
    private function start(): string
    {
        $application = $this->applicationPath !== '' ? $this->applicationPath : GatewayApplicationPath::resolve();
        $orbitHome = $this->orbitHome !== '' ? $this->orbitHome : StoredValue::string(config('orbit.home'));

        foreach (TaskVmQueue::cases() as $queue) {
            $this->install($this->path($queue), $this->render($queue, $application, $orbitHome));
        }

        $this->run(['sudo', 'systemctl', 'daemon-reload']);

        try {
            ($this->restartWorkers)();
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: 'task-vm-workers-restart',
                errorCode: TaskVmError::WorkersFailed->value,
                message: 'The task VM queue workers could not be asked to restart.',
                previous: $exception,
            );
        }

        $this->run(['sudo', 'systemctl', 'enable', '--now', ...$this->units()]);

        return 'started';
    }

    /** @return 'stopped'|'none' */
    private function remove(): string
    {
        $present = array_values(array_filter(TaskVmQueue::cases(), fn (TaskVmQueue $queue): bool => is_file($this->path($queue))));

        if ($present === []) {
            return 'none';
        }

        // Without waiting: a worker that still runs a job stops when the job ends.
        $this->run(['sudo', 'systemctl', 'disable', '--now', '--no-block', ...array_map($this->unitName(...), $present)]);
        $this->run(['sudo', 'rm', '-f', ...array_map($this->path(...), $present)]);
        $this->run(['sudo', 'systemctl', 'daemon-reload']);

        return 'stopped';
    }

    /** @return non-empty-list<string> */
    private function units(): array
    {
        return array_map($this->unitName(...), TaskVmQueue::cases());
    }

    private function path(TaskVmQueue $queue): string
    {
        return rtrim($this->unitDirectory, '/').'/'.$this->unitName($queue);
    }

    private function install(string $path, string $contents): void
    {
        $input = null;

        try {
            $input = ProtectedInput::fromString($contents);
            $source = stream_get_meta_data($input->stream())['uri'] ?? null;

            if (! is_string($source) || $source === '') {
                throw new RuntimeException('The task VM worker unit file is unavailable.');
            }

            $this->run(['sudo', 'install', '-m', '0644', $source, $path]);
        } catch (NodeProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: 'task-vm-workers-install',
                errorCode: TaskVmError::WorkersFailed->value,
                message: "The task VM worker unit [{$path}] could not be installed.",
                previous: $exception,
            );
        } finally {
            $input?->close();
        }
    }

    /** @param non-empty-list<string> $arguments */
    private function run(array $arguments): void
    {
        $result = $this->processes->run(new ProcessInvocation($arguments));

        if ($result->succeeded()) {
            return;
        }

        throw new NodeProvisioningException(
            step: 'task-vm-workers',
            errorCode: TaskVmError::WorkersFailed->value,
            message: 'The task VM queue workers step ['.implode(' ', array_slice($arguments, 0, 3)).'] failed.',
            result: $result,
        );
    }

    private function quoteArgument(string $argument): string
    {
        return '"'.str_replace(['\\', '"', '$', '%'], ['\\\\', '\\"', '$$', '%%'], $argument).'"';
    }

    private function escapeDirectivePath(string $value): string
    {
        $escaped = preg_replace_callback(
            '/[\x00-\x20"\'$%\\\\\x7F]/',
            static fn (array $match): string => $match[0] === '%' ? '%%' : sprintf('\\x%02x', ord($match[0])),
            $value,
        );

        return $escaped ?? $value;
    }
}
