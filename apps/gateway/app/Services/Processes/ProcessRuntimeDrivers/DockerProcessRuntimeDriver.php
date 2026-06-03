<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessDockerRuntimeManager;

final readonly class DockerProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private ProcessDockerContainerRenderer $renderer,
        private ProcessDockerRuntimeManager $manager,
    ) {}

    public function runtimeUnitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->containerName($app, $process, $workspace);
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->start($node, $runtimeUnit);
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->stop($node, $runtimeUnit);
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->manager->restart($node, $runtimeUnit);
    }

    public function logScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string
    {
        return collect([
            'docker logs',
            "--tail {$lines}",
            $follow ? '--follow' : null,
            escapeshellarg($runtimeUnit),
            '2>&1',
        ])->filter()->implode(' ');
    }
}
