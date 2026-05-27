<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Contracts\SiteCertificateInstaller;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessDockerContainerRenderer;
use App\Services\Processes\ProcessDockerRuntimeManager;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use RuntimeException;
use Throwable;

final readonly class EnsureAppProcessRuntimeUnits
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $renderer,
        private RuntimeBackendProbe $runtimeBackendProbe,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private ProcessDockerContainerRenderer $dockerRenderer,
        private ProcessDockerRuntimeManager $dockerManager,
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

        if ($this->anyProcessNeedsSupervisor($app)) {
            $probe = $this->runtimeBackendProbe->check($app->node);

            if (! $probe->available) {
                return [[
                    'code' => 'process.runtime_backend_unavailable',
                    'family' => 'process',
                    'message' => "Supervisor is not available on '{$app->node->name}'. Run doctor to converge process runtime units.",
                    'next_command' => 'doctor --family=process --restore',
                ]];
            }
        }

        $warnings = [];

        foreach ($this->runtimeContexts($app) as $workspace) {
            $tlsWarning = $this->ensureSiteCertificate($app, $workspace);

            if ($tlsWarning !== null) {
                $warnings[] = $tlsWarning;

                continue;
            }

            foreach ($app->processes as $process) {
                if (! $process instanceof Process) {
                    continue;
                }

                $warnings = [
                    ...$warnings,
                    ...$this->applyProcess($app, $process, $workspace),
                ];
            }
        }

        return $warnings;
    }

    /**
     * @return list<array<string, string>>
     */
    private function applyProcess(App $app, Process $process, ?Workspace $workspace): array
    {
        return match ($process->runtime) {
            ProcessRuntime::Docker => $this->applyDockerProcess($app, $process, $workspace),
            ProcessRuntime::Supervisor => $this->applySupervisorProcess($app, $process, $workspace),
        };
    }

    /**
     * @return list<array<string, string>>
     */
    private function applyDockerProcess(App $app, Process $process, ?Workspace $workspace): array
    {
        // The unit identity is shared across both runtimes, so we can address
        // any stale Supervisor program by the same name and tear it down
        // before applying the Docker container.
        $unitName = $this->renderer->programName($app, $process, $workspace);
        $this->remoteShell->run($app->node, $this->supervisorCleanupScript($unitName));

        try {
            $container = $this->dockerRenderer->render($app, $process, $workspace);
            $this->dockerManager->apply($app->node, $container);

            return [];
        } catch (Throwable) {
            return [[
                'code' => 'process.runtime_unit_missing',
                'family' => 'process',
                'message' => "Docker process runtime unit '{$unitName}' was not enacted. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --family=process --restore',
            ]];
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function applySupervisorProcess(App $app, Process $process, ?Workspace $workspace): array
    {
        $programName = $this->renderer->programName($app, $process, $workspace);
        $script = $this->dockerCleanupScript($programName).PHP_EOL.$this->renderer->installScript($app, $process, $workspace);

        $result = $this->remoteShell->run($app->node, $script);

        if (! $result->successful()) {
            return [[
                'code' => 'process.runtime_unit_missing',
                'family' => 'process',
                'message' => "Process runtime unit '{$programName}' was not enacted. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --family=process --restore',
            ]];
        }

        return [];
    }

    private function anyProcessNeedsSupervisor(App $app): bool
    {
        return $app->processes->contains(
            fn (Process $process): bool => $process->runtime === ProcessRuntime::Supervisor,
        );
    }

    private function dockerCleanupScript(string $unitName): string
    {
        // Best-effort. Nodes without Docker installed still succeed because
        // the failure of `docker` is swallowed; the goal is convergence, not
        // strict removal accounting.
        return sprintf('docker rm -f %s 2>/dev/null || true', escapeshellarg($unitName));
    }

    private function supervisorCleanupScript(string $unitName): string
    {
        // Best-effort. Nodes without Supervisor still succeed.
        return sprintf(
            'sudo rm -f /etc/supervisor/conf.d/%s.conf 2>/dev/null; sudo supervisorctl reread 2>/dev/null; sudo supervisorctl remove %s 2>/dev/null; true',
            $unitName,
            escapeshellarg($unitName),
        );
    }

    /**
     * @return array<string, string>|null
     */
    private function ensureSiteCertificate(App $app, ?Workspace $workspace): ?array
    {
        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $host = $this->renderer->host($app, $workspace);

        try {
            $this->siteCertificateInstaller->ensureFor($app->node, $host);

            return null;
        } catch (Throwable) {
            return [
                'code' => 'process.tls_certificate_missing',
                'family' => 'process',
                'message' => "Process TLS certificate for '{$host}' was not installed. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --family=process --restore',
            ];
        }
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
}
