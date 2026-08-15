<?php

declare(strict_types=1);

namespace App\Services\Processes\ProcessRuntimeDrivers;

use App\Models\App;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\RemoteSystemdService;
use App\Services\Processes\SystemdUnitRenderer;
use Throwable;

final readonly class SystemdProcessRuntimeDriver implements ProcessRuntimeDriver
{
    public function __construct(
        private SystemdUnitRenderer $renderer,
        private RemoteSystemdService $systemd,
    ) {}

    public function runtimeUnitName(?App $app, Process $process, ?Workspace $workspace = null): string
    {
        return $this->renderer->unitName($app, $process, $workspace);
    }

    public function apply(
        Node $node,
        ?App $app,
        Process $process,
        ?Workspace $workspace = null,
    ): bool {
        try {
            return $this->systemd
                ->apply(
                    node: $node,
                    service: $this->renderer->serviceName($this->runtimeUnitName($app, $process, $workspace)),
                    content: $this->renderer->render($node, $app, $process, $workspace),
                    enabled: $process->owner instanceof Node || ! $node->hasActiveRole('app-dev'),
                )
                ->successful();
        } catch (Throwable) {
            return false;
        }
    }

    public function remove(Node $node, string $runtimeUnit): bool
    {
        return $this->systemd->remove(
            node: $node,
            service: $this->renderer->serviceName($runtimeUnit),
            unitPath: $this->renderer->unitPath($runtimeUnit),
        );
    }

    public function cleanupScript(string $runtimeUnit): string
    {
        $serviceName = $this->renderer->serviceName($runtimeUnit);

        return sprintf(
            'sudo systemctl stop %1$s >/dev/null 2>&1 || true; sudo systemctl disable %1$s >/dev/null 2>&1 || true; sudo rm -f %2$s; sudo systemctl daemon-reload; sudo systemctl reset-failed %1$s >/dev/null 2>&1 || true; true',
            escapeshellarg($serviceName),
            escapeshellarg($this->renderer->unitPath($runtimeUnit)),
        );
    }

    public function start(Node $node, string $runtimeUnit): bool
    {
        return $this->systemd->start($node, $this->renderer->serviceName($runtimeUnit));
    }

    public function stop(Node $node, string $runtimeUnit): bool
    {
        return $this->systemd->stop($node, $this->renderer->serviceName($runtimeUnit));
    }

    public function restart(Node $node, string $runtimeUnit): bool
    {
        return $this->systemd->restart($node, $this->renderer->serviceName($runtimeUnit));
    }

    public function isRunning(Node $node, string $runtimeUnit): bool
    {
        return $this->systemd->isActive($node, $this->renderer->serviceName($runtimeUnit));
    }

    public function logScript(
        ?App $app,
        Process $process,
        ?Workspace $workspace,
        string $runtimeUnit,
        int $lines,
        bool $follow,
    ): string {
        return collect([
            'sudo journalctl',
            '-u',
            escapeshellarg($this->renderer->serviceName($runtimeUnit)),
            "-n {$lines}",
            $follow ? '-f' : null,
            '--no-pager',
            '--output=short-iso',
            '2>&1',
        ])
            ->filter()
            ->implode(' ');
    }
}
