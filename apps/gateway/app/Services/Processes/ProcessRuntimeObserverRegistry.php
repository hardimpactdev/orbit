<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Data\Doctor\ProbeSnapshot;
use App\Enums\Processes\ProcessRuntime;
use App\Models\Node;
use App\Models\Process;
use App\Services\Nodes\NodeHostPaths;
use App\Services\Processes\ProcessRuntimeObservers\DockerProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\DockerSwarmProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\LaunchdProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\ProcessRuntimeObserver;
use App\Services\Processes\ProcessRuntimeObservers\SystemdProcessRuntimeObserver;
use InvalidArgumentException;

final readonly class ProcessRuntimeObserverRegistry
{
    public function __construct(
        private ProcessRuntimeContextResolver $runtimeContextResolver,
        private DockerProcessRuntimeObserver $docker,
        private DockerSwarmProcessRuntimeObserver $dockerSwarm,
        private LaunchdProcessRuntimeObserver $launchd,
        private SystemdProcessRuntimeObserver $systemd,
    ) {}

    public function observe(Process $process): ProbeSnapshot
    {
        $node = $this->runtimeContextResolver->executionNode($process);

        if (! $node instanceof Node) {
            return new ProbeSnapshot([]);
        }

        try {
            $runtime = $this->runtimeContextResolver->runtime($process);

            if ($runtime === ProcessRuntime::Launchd && ! NodeHostPaths::isMacosPlatform($node->platform)) {
                throw new InvalidArgumentException(
                    "Launchd runtime for process '{$process->name}' requires a macOS execution node; "
                    ."placement resolved to '{$node->name}' ({$node->platform}).",
                );
            }

            return $this->for($runtime)->observe($process, $node);
        } catch (InvalidArgumentException $exception) {
            return $this->unrenderableRuntimeUnitSnapshot($process, $exception);
        }
    }

    private function for(ProcessRuntime $runtime): ProcessRuntimeObserver
    {
        return match ($runtime) {
            ProcessRuntime::Docker => $this->docker,
            ProcessRuntime::DockerSwarm => $this->dockerSwarm,
            ProcessRuntime::Launchd => $this->launchd,
            ProcessRuntime::Systemd => $this->systemd,
        };
    }

    private function unrenderableRuntimeUnitSnapshot(
        Process $process,
        InvalidArgumentException $exception,
    ): ProbeSnapshot {
        return new ProbeSnapshot([
            $process->name => [
                'runtime_unit_renderable' => false,
                'runtime_unit_render_error' => $exception->getMessage(),
                'runtime_backend_available' => null,
                'runtime_backend_exit_code' => 0,
                'runtime_backend_output' => '',
                'runtime_units' => [],
                'runtime_unit_extras' => [],
            ],
        ]);
    }
}
