<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Process;
use App\Services\Processes\SupervisorProgramRenderer;
use RuntimeException;

final readonly class EnsureAppProcessRuntimeUnits
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $renderer,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app): array
    {
        $app->loadMissing(['node', 'processes']);

        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        if ($app->processes->isEmpty()) {
            return [];
        }

        $probe = $this->remoteShell->run($app->node, 'command -v supervisorctl >/dev/null 2>&1');

        if (! $probe->successful()) {
            return [[
                'code' => 'process.runtime_backend_unavailable',
                'family' => 'process',
                'message' => "Supervisor is not available on '{$app->node->name}'. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --family=process --fix',
            ]];
        }

        $warnings = [];

        foreach ($app->processes as $process) {
            if (! $process instanceof Process) {
                continue;
            }

            $programName = $this->renderer->programName($app, $process);
            $result = $this->remoteShell->run($app->node, $this->renderInstallScript($app, $process));

            if (! $result->successful()) {
                $warnings[] = [
                    'code' => 'process.runtime_unit_missing',
                    'family' => 'process',
                    'message' => "Process runtime unit '{$programName}' was not enacted. Run doctor to converge process runtime units.",
                    'next_command' => 'doctor --family=process --fix',
                ];
            }
        }

        return $warnings;
    }

    private function renderInstallScript(App $app, Process $process): string
    {
        $programName = $this->renderer->programName($app, $process);
        $content = $this->renderer->render($app, $process);
        $path = "/etc/supervisor/conf.d/{$programName}.conf";

        return sprintf(
            <<<'SH'
sudo mkdir -p /etc/supervisor/conf.d
cat <<'ORBIT_SUPERVISOR_PROGRAM' | sudo tee %s >/dev/null
%s
ORBIT_SUPERVISOR_PROGRAM
sudo supervisorctl reread
sudo supervisorctl update %s
SH,
            escapeshellarg($path),
            $content,
            escapeshellarg($programName),
        );
    }
}
