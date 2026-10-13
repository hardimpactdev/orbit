<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Infrastructure\Shared\StoredValue;
use Closure;
use RuntimeException;
use Throwable;

/**
 * `orbit-horizon.service`, which runs Laravel Horizon for the Gateway queue (docs/reference/gateway-queue.md#horizon).
 * `orbit:gateway-web` and the scheduler phase of each release's runtime handoff converge it.
 */
final readonly class GatewayHorizonUnit
{
    public const string Name = 'orbit-horizon.service';

    /** The file whose absence in the current release keeps the unit from starting. */
    public const string Marker = 'config/horizon.php';

    /** How long a new Horizon master has to register in Redis. */
    public const int StartSeconds = 30;

    public function __construct(
        private ProcessRunner $processes,
        private GatewayHorizonRuntime $runtime = new NativeGatewayHorizonRuntime,
        private string $phpBinary = PHP_BINARY,
        private string $applicationPath = '',
        private string $orbitHome = '',
        private string $user = 'orbit',
        private string $unitDirectory = '/etc/systemd/system',
        private ?int $taskVmWaitSeconds = null,
        private int $startSeconds = self::StartSeconds,
    ) {}

    /** The longest a `task-vms` job runs: its supervisor's timeout, which no `task-vms` job's own timeout exceeds. */
    public static function taskVmJobSeconds(): int
    {
        return (int) config('horizon.defaults.task-vms.timeout');
    }

    /** How long a release waits for the old `task-vms` worker: its longest job and a margin for its exit. */
    public static function taskVmWaitSeconds(): int
    {
        return self::taskVmJobSeconds() + 60;
    }

    /** The pause outlasts the wait, so the queue never runs again while the release still waits. */
    public static function taskVmPauseSeconds(): int
    {
        return self::taskVmWaitSeconds() + 120;
    }

    /**
     * Installs and starts the unit while the Gateway queue is set, and moves a running master to the new release.
     * Otherwise it stops and removes the unit when it exists, and changes nothing when it does not.
     *
     * The old master stops at once and its workers finish their jobs. The `task-vms` queue stays paused until the old
     * `task-vms` worker is gone, so its jobs still run one at a time and in order. When the old worker outlasts its
     * longest job, the queue stays paused until the pause expires and the release fails. It reports `started` only once
     * a new master is registered.
     *
     * @return array{outcome: 'started'|'stopped'|'none', task_vm_wait_ms?: int}
     *
     * @throws NodeProvisioningException
     */
    public function converge(): array
    {
        if (! GatewayQueueRedis::configured()) {
            return ['outcome' => $this->remove()];
        }

        $application = $this->applicationPath !== '' ? $this->applicationPath : GatewayApplicationPath::resolve();
        $orbitHome = $this->orbitHome !== '' ? $this->orbitHome : StoredValue::string(config('orbit.home'));
        $this->install($this->render($application, $orbitHome));
        $this->run(['sudo', 'systemctl', 'daemon-reload']);
        $wait = $this->taskVmWaitSeconds ?? self::taskVmWaitSeconds();

        try {
            $oldWorkers = $this->runtime->taskVmWorkers();
            $oldMasters = $this->runtime->masters();
            $startedAt = hrtime(true);
            $paused = false;

            if ($oldWorkers !== []) {
                $this->runtime->pauseTaskVms(max($wait + 120, self::taskVmPauseSeconds()));
                $paused = true;
            }

            try {
                $this->run(['sudo', 'systemctl', 'enable', '--now', self::Name]);
                $this->runtime->terminate();

                if (! $this->waitFor(fn (): bool => array_filter($oldWorkers, $this->runtime->running(...)) === [], $wait)) {
                    // Resuming now would run two task VM jobs at once. The pause expires on its own.
                    $paused = false;

                    throw new NodeProvisioningException(
                        step: 'horizon-task-vms',
                        errorCode: 'gateway.horizon_unit_failed',
                        message: "The old task-vms worker still runs after {$wait} seconds, longer than any task VM job. The task-vms queue stays paused until its pause expires.",
                    );
                }
            } finally {
                if ($paused) {
                    $this->runtime->resumeTaskVms();
                }
            }

            $waited = intdiv(hrtime(true) - $startedAt, 1_000_000);
            $started = $this->waitFor(fn (): bool => array_diff($this->runtime->masters(), $oldMasters) !== [], $this->startSeconds);
        } catch (NodeProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: 'horizon-restart',
                errorCode: 'gateway.horizon_unit_failed',
                message: 'Horizon could not be moved to the new release: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! $started) {
            throw new NodeProvisioningException(
                step: 'horizon-start',
                errorCode: 'gateway.horizon_unit_failed',
                message: 'No Horizon master registered '.$this->startSeconds.' seconds after the start. See journalctl -u '.self::Name.'.',
            );
        }

        return ['outcome' => 'started', 'task_vm_wait_ms' => $oldWorkers === [] ? 0 : $waited];
    }

    public function render(string $application, string $orbitHome): string
    {
        return implode("\n", [
            '[Unit]',
            'Description=Orbit Gateway queue (Laravel Horizon)',
            'After=network-online.target',
            'Wants=network-online.target',
            // Restart for as long as Redis is down.
            'StartLimitIntervalSec=0',
            '',
            '[Service]',
            'Type=simple',
            'User='.$this->user,
            'WorkingDirectory='.$this->escapeDirectivePath($application),
            'Environment=PATH='.SystemdProcessRenderer::Path,
            'Environment=NODE_USE_SYSTEM_CA=1',
            'Environment=ORBIT_HOME='.$this->escapeDirectivePath($orbitHome),
            // A release without Horizon, such as one before it after a switch back, never starts it.
            'ExecCondition='.implode(' ', array_map($this->quoteArgument(...), ['/usr/bin/test', '-f', $application.'/'.self::Marker])),
            'ExecStart='.implode(' ', array_map($this->quoteArgument(...), [$this->phpBinary, $application.'/artisan', 'horizon'])),
            'Restart=always',
            'RestartSec=5',
            // Only the master stops. Each worker finishes its running job on its release; the next master starts at once.
            'KillMode=process',
            '',
            '[Install]',
            'WantedBy=multi-user.target',
            '',
        ]);
    }

    /** @return 'stopped'|'none' */
    private function remove(): string
    {
        if (! is_file($this->path())) {
            return 'none';
        }

        $this->run(['sudo', 'systemctl', 'disable', '--now', '--no-block', self::Name]);
        $this->run(['sudo', 'rm', '-f', $this->path()]);
        $this->run(['sudo', 'systemctl', 'daemon-reload']);

        return 'stopped';
    }

    /** @param  Closure(): bool  $done */
    private function waitFor(Closure $done, int $seconds): bool
    {
        $deadline = hrtime(true) + $seconds * 1_000_000_000;

        while (! $done()) {
            if (hrtime(true) >= $deadline) {
                return false;
            }

            $this->runtime->sleep(500);
        }

        return true;
    }

    private function path(): string
    {
        return rtrim($this->unitDirectory, '/').'/'.self::Name;
    }

    private function install(string $contents): void
    {
        $input = null;

        try {
            $input = ProtectedInput::fromString($contents);
            $source = stream_get_meta_data($input->stream())['uri'] ?? null;

            if (! is_string($source) || $source === '') {
                throw new RuntimeException('The Horizon unit file is unavailable.');
            }

            $this->run(['sudo', 'install', '-m', '0644', $source, $this->path()]);
        } catch (NodeProvisioningException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: 'horizon-install',
                errorCode: 'gateway.horizon_unit_failed',
                message: 'The Horizon unit could not be installed.',
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
            step: 'horizon-unit',
            errorCode: 'gateway.horizon_unit_failed',
            message: 'The Horizon unit step ['.implode(' ', array_slice($arguments, 0, 3)).'] failed.',
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
