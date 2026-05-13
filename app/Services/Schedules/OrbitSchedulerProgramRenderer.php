<?php

declare(strict_types=1);

namespace App\Services\Schedules;

use App\Data\RuntimeBackend\SupervisorProgramDefinition;
use App\Models\Node;
use App\Services\RuntimeBackend\SupervisorProgramRenderer;

final readonly class OrbitSchedulerProgramRenderer
{
    public function __construct(
        private SupervisorProgramRenderer $renderer = new SupervisorProgramRenderer,
    ) {}

    public function render(Node $node, ?int $sleepSeconds = null): string
    {
        return $this->renderer->render($this->definition($node, $sleepSeconds));
    }

    public function installScript(Node $node, ?int $sleepSeconds = null): string
    {
        return $this->renderer->renderInstallScript($this->definition($node, $sleepSeconds));
    }

    public function definition(Node $node, ?int $sleepSeconds = null): SupervisorProgramDefinition
    {
        $user = $node->user ?: 'orbit';
        $home = $user === 'root' ? '/root' : "/home/{$user}";
        $command = 'php artisan orbit-scheduler';

        if ($sleepSeconds !== null) {
            $command .= " --sleep-seconds={$sleepSeconds}";
        }

        return new SupervisorProgramDefinition(
            name: 'orbit_scheduler',
            directory: $node->orbit_path,
            command: $command,
            user: $user,
            restartPolicy: 'true',
            stdoutLogFile: "{$home}/.config/orbit/logs/orbit_scheduler.log",
            environment: [
                'PATH' => "{$home}/.local/bin:/usr/local/bin:/usr/bin:/bin",
                'HOME' => $home,
            ],
            autostart: true,
            startSeconds: 1,
        );
    }
}
