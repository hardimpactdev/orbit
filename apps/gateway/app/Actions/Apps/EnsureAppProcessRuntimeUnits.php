<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\SiteCertificateInstaller;
use App\Enums\Processes\ProcessRuntime;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Services\RuntimeBackend\RuntimeBackendProbe;
use RuntimeException;
use Throwable;

final readonly class EnsureAppProcessRuntimeUnits
{
    public function __construct(
        private SupervisorProgramRenderer $renderer,
        private RuntimeBackendProbe $runtimeBackendProbe,
        private SiteCertificateInstaller $siteCertificateInstaller,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
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

        $this->validateProcessRuntimes($app);

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

                if ($this->isManagedRuntimeArtifactProcess($process)) {
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
        $driver = $this->runtimeDrivers->forProcess($process);
        $unitName = $driver->runtimeUnitName($app, $process, $workspace);
        $staleRuntime = $this->staleRuntime($process);
        $cleanupScript = $staleRuntime instanceof ProcessRuntime
            ? $this->runtimeDrivers->for($staleRuntime)->cleanupScript($unitName)
            : null;

        if (! $driver->apply($app->node, $app, $process, $workspace, $cleanupScript)) {
            return [[
                'code' => 'process.runtime_unit_missing',
                'family' => 'process',
                'message' => "Process runtime unit '{$unitName}' was not enacted. Run doctor to converge process runtime units.",
                'next_command' => 'doctor --family=process --restore',
            ]];
        }

        return [];
    }

    private function isManagedRuntimeArtifactProcess(Process $process): bool
    {
        $config = is_array($process->runtime_config) ? $process->runtime_config : [];
        $hashLabel = $config['container_spec_hash_label'] ?? null;

        return is_string($hashLabel) && trim($hashLabel) !== '';
    }

    private function validateProcessRuntimes(App $app): void
    {
        $app->processes->each(fn (Process $process): mixed => $this->runtimeDrivers->forProcess($process));
    }

    private function anyProcessNeedsSupervisor(App $app): bool
    {
        return $app->processes->contains(
            fn (Process $process): bool => $process->getRawOriginal('runtime') === ProcessRuntime::Supervisor->value,
        );
    }

    private function staleRuntime(Process $process): ?ProcessRuntime
    {
        return match ($process->runtime) {
            ProcessRuntime::Docker => ProcessRuntime::Supervisor,
            ProcessRuntime::Supervisor => ProcessRuntime::Docker,
            ProcessRuntime::Systemd => null,
        };
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
