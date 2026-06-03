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

    public function apply(Node $node, App $app, Process $process, ?Workspace $workspace = null, ?string $preApplyScript = null): bool
    {
        $script = collect([
            $preApplyScript,
            $this->renderer->installScript($app, $process, $workspace),
        ])->filter(fn (?string $script): bool => $script !== null && trim($script) !== '')->implode(PHP_EOL);

        return $this->remoteShell->run($node, $script)->successful();
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        return $this->remoteShell->run($node, $this->removeScript($runtimeUnit))->successful();
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        return sprintf(
            'sudo rm -f /etc/supervisor/conf.d/%s.conf 2>/dev/null; sudo supervisorctl reread 2>/dev/null; sudo supervisorctl remove %s 2>/dev/null; true',
            $runtimeUnit,
            escapeshellarg($runtimeUnit),
        );
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

    private function removeScript(string $runtimeUnit): string
    {
        $configPath = "/etc/supervisor/conf.d/{$runtimeUnit}.conf";

        return sprintf(
            <<<'SH'
sudo supervisorctl stop %1$s >/dev/null 2>&1 || true
sudo rm -f %2$s
sudo supervisorctl reread
sudo supervisorctl remove %1$s >/dev/null 2>&1 || true
sudo supervisorctl update
SH,
            escapeshellarg($runtimeUnit),
            escapeshellarg($configPath),
        );
    }
}
