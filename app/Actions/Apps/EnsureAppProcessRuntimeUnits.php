<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use RuntimeException;

final readonly class EnsureAppProcessRuntimeUnits
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $renderer,
        private RuntimeBackendProbe $runtimeBackendProbe,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing(['node', 'processes', 'workspaces']);

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        if ($app->processes->isEmpty()) {
            return [];
        }

        $probe = $this->runtimeBackendProbe->check($app->node);

        if (! $probe->available) {
            return [[
                'code' => 'process.runtime_backend_unavailable',
                'family' => 'process',
                'message' => "Supervisor is not available on '{$app->node->name}'. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --fix --family=process --restore',
            ]];
        }

        $warnings = [];

        foreach ($app->processes as $process) {
            if (! $process instanceof Process) {
                continue;
            }

            foreach ($this->runtimeContexts($app) as $workspace) {
                $programName = $this->renderer->programName($app, $process, $workspace);
                $result = $this->remoteShell->run($app->node, $this->renderInstallScript($app, $process, $workspace));

                if (! $result->successful()) {
                    $warnings[] = [
                        'code' => 'process.runtime_unit_missing',
                        'family' => 'process',
                        'message' => "Process runtime unit '{$programName}' was not enacted. Run doctor to converge process runtime units.",
                        'next_command' => 'doctor --fix --family=process --restore',
                    ];
                }
            }
        }

        return $warnings;
    }

    /**
     * @return list<Workspace|null>
     */
    private function runtimeContexts(App $app): array
    {
        return [
            null,
            ...$app->workspaces->all(),
        ];
    }

    private function renderInstallScript(App $app, Process $process, ?Workspace $workspace): string
    {
        return $this->renderer->installScript($app, $process, $workspace);
    }
}
