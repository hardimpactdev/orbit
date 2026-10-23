<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\DevelopmentProjectionOperationLock;
use App\Domain\AppDev\VitePortRuntime;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Processes\ProcessOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;

/**
 * A development Instance's Vite server on its Node, through the agent's `vite.*` commands
 * (docs/reference/processes-and-schedules.md).
 */
final readonly class RemoteVitePortRuntime implements VitePortRuntime
{
    public function __construct(
        private AgentCommandRunner $agents,
        private RemoteAppDevCaddyManager $caddy,
        private DevelopmentProjectionOperationLock $projection,
        private SystemdProcessRenderer $systemd,
    ) {}

    public function selectPort(Node $node, int $preferred, array $excluded): int
    {
        try {
            $result = $this->agents->run($node, 'vite.select-port', ['preferred' => $preferred, 'excluded' => array_values($excluded)]);
        } catch (AgentCommandRefused $refusal) {
            if ($refusal->errorCode === 'vite.ports_exhausted') {
                throw new ProcessOperationException('vite-port-check', 'vite.ports_exhausted', 'No available Vite port remains on this Node.');
            }

            throw new ProcessOperationException('vite-port-check', 'vite.port_check_failed', 'The Node could not check its Vite ports.');
        }
        $port = filter_var($result['port'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1024, 'max_range' => 65535]]);

        if (! is_int($port)) {
            throw new ProcessOperationException('vite-port-check', 'vite.port_check_invalid', 'The Node returned an invalid port check result.');
        }

        return $port;
    }

    /** @phpstan-impure */
    public function ownsListener(Process $process, Instance $instance, int $port): bool
    {
        return $this->listener($process, $instance, $port, false)['owned'] === true;
    }

    public function ready(Process $process, Instance $instance, int $port): bool
    {
        return $this->listener($process, $instance, $port, true)['ready'] === true;
    }

    public function suspendTraffic(Instance $instance): void
    {
        $this->traffic($instance, ['operation' => 'suspend', 'marker' => $this->marker($instance)], 'vite-suspend-traffic', 'vite.suspend_failed');
    }

    public function markAwake(Instance $instance): void
    {
        $this->traffic($instance, ['operation' => 'awake', 'marker' => $this->marker($instance)], 'vite-mark-awake', 'vite.awake_failed');
    }

    public function prepare(Process $process, Instance $instance): void
    {
        $this->traffic($instance, [
            'operation' => 'environment',
            'application' => $instance->source_is_laravel === true ? $instance->applicationDirectory() : $instance->checkout_path,
            'path' => SystemdProcessRenderer::viteEnvironmentPath($instance->id),
            'instance_id' => $instance->id,
            'marker' => $this->marker($instance),
            'content' => "# Orbit Instance {$instance->id}\nORBIT_DEV_SERVER_PORT={$instance->vite_port}\n",
        ], 'vite-environment', 'vite.environment_failed');
    }

    /** @return array{owned: bool, ready: bool} */
    private function listener(Process $process, Instance $instance, int $port, bool $probe): array
    {
        $result = AgentStep::run($this->agents, $instance->node, 'vite.check-listener', [
            'unit' => $this->systemd->unitName($process),
            'port' => $port,
            'process_id' => $process->id,
            'probe' => $probe,
        ], 'vite-listener-owner', 'vite.listener_check_failed', 'Vite');

        return ['owned' => ($result['owned'] ?? null) === true, 'ready' => ($result['ready'] ?? null) === true];
    }

    /** @param  array<string, mixed>  $input */
    private function traffic(Instance $instance, array $input, string $step, string $errorCode): void
    {
        AgentStep::run($this->agents, $instance->node, 'vite.set-traffic', $input, $step, $errorCode, 'Vite');
    }

    private function marker(Instance $instance): string
    {
        return RuntimeHibernation::awakePath(RuntimeHibernation::key($instance->id));
    }

    public function project(Instance $instance): void
    {
        $this->projection->run(function () use ($instance): void {
            $instance->loadMissing('routes');
            if ($instance->routes->isNotEmpty()) {
                $this->caddy->build($instance->node);
            }
        });
    }
}
