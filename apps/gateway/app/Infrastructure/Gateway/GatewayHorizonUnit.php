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
use Illuminate\Support\Facades\Artisan;
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

    /** @var Closure(): void */
    private Closure $terminate;

    /** @param  (Closure(): void)|null  $terminate  asks a running Horizon master to finish and exit */
    public function __construct(
        private ProcessRunner $processes,
        private string $phpBinary = PHP_BINARY,
        private string $applicationPath = '',
        private string $orbitHome = '',
        private string $user = 'orbit',
        private string $unitDirectory = '/etc/systemd/system',
        ?Closure $terminate = null,
    ) {
        $this->terminate = $terminate ?? static function (): void {
            Artisan::call('horizon:terminate');
        };
    }

    /**
     * Installs and starts the unit while the Gateway queue is set, and lets a running master move to the new release.
     * Otherwise it stops and removes the unit when it exists, and changes nothing when it does not.
     *
     * @return 'started'|'stopped'|'none'
     *
     * @throws NodeProvisioningException
     */
    public function converge(): string
    {
        if (! GatewayQueueRedis::configured()) {
            return $this->remove();
        }

        $application = $this->applicationPath !== '' ? $this->applicationPath : GatewayApplicationPath::resolve();
        $orbitHome = $this->orbitHome !== '' ? $this->orbitHome : StoredValue::string(config('orbit.home'));
        $this->install($this->render($application, $orbitHome));
        $this->run(['sudo', 'systemctl', 'daemon-reload']);
        $this->run(['sudo', 'systemctl', 'enable', '--now', self::Name]);

        try {
            ($this->terminate)();
        } catch (Throwable $exception) {
            throw new NodeProvisioningException(
                step: 'horizon-terminate',
                errorCode: 'gateway.horizon_unit_failed',
                message: 'The running Horizon master could not be asked to restart on the new release.',
                previous: $exception,
            );
        }

        return 'started';
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
