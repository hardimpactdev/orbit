<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\SupervisorProgramRenderer;

final readonly class SupervisorProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $renderer,
    ) {}

    public function runtimeUnitName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->programName($app, $process, $workspace);
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'sudo supervisorctl start '.escapeshellarg($runtimeUnit))->successful();
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'sudo supervisorctl stop '.escapeshellarg($runtimeUnit))->successful();
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, 'sudo supervisorctl restart '.escapeshellarg($runtimeUnit))->successful();
    }

    public function logScript(App $app, Process $process, ?Workspace $workspace, string $runtimeUnit, int $lines, bool $follow): string
    {
        $definition = $this->renderer->definition($app, $process, $workspace);

        return collect([
            'sudo tail',
            "-n {$lines}",
            $follow ? '-F' : null,
            escapeshellarg($definition->stdoutLogFile),
        ])->filter()->implode(' ');
    }
}
