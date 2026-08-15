<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\SiteCertificateInstaller;
use App\Models\App;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Processes\ProcessRuntimeDriverRegistry;
use App\Services\Workspaces\WorkspacePlacement;
use App\Services\Workspaces\WorkspaceRoleGuard;
use RuntimeException;
use Throwable;

final readonly class EnsureAppProcessRuntimeUnits
{
    public function __construct(
        private SiteCertificateInstaller $siteCertificateInstaller,
        private ProcessRuntimeDriverRegistry $runtimeDrivers,
        private WorkspacePlacement $placement,
        private WorkspaceRoleGuard $workspaceRoleGuard,
    ) {}

    /**
     * @return list<array<string, string>>
     */
    public function handle(App $app, ?Instance $instance = null, ?Node $consumer = null): array
    {
        $app->loadMissing(['node', 'instances']);
        $instance ??= $this->soleInstance($app);
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new RuntimeException("Instance '{$app->name}.{$instance->name}' has no owning node.");
        }

        // The logical app is used as-is; process-unit renderers resolve
        // placement from each process's own instance.
        $app->setRelation('node', $node);
        $app->setRelation(
            'processes',
            $app->processes()->where('instance_id', $instance->id)->orderBy('sort_order')->get(),
        );
        $app->setRelation(
            'workspaces',
            $app->workspaces()->where('instance_id', $instance->id)->orderBy('name')->get(),
        );

        if ($app->processes->isEmpty()) {
            return [];
        }

        $this->validateProcessRuntimes($app);

        $warnings = [];

        foreach ($this->runtimeContexts($app, $consumer) as $workspace) {
            $tlsWarning = $this->ensureSiteCertificate($app, $workspace, $instance);

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

    private function soleInstance(App $app): Instance
    {
        $instances = $app->instances->values();
        $instance = $instances->first();

        if ($instances->count() === 1 && $instance instanceof Instance) {
            return $instance;
        }

        throw new RuntimeException("App '{$app->name}' requires one concrete instance for process enactment.");
    }

    /**
     * @return list<array<string, string>>
     */
    private function applyProcess(App $app, Process $process, ?Workspace $workspace): array
    {
        $driver = $this->runtimeDrivers->forProcess($process);
        $unitName = $driver->runtimeUnitName($app, $process, $workspace);

        if (! $driver->apply($app->node, $app, $process, $workspace)) {
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

    /**
     * @return array<string, string>|null
     */
    private function ensureSiteCertificate(App $app, ?Workspace $workspace, ?Instance $instance = null): ?array
    {
        if ($app->node === null) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $host = $this->host($app, $workspace, $instance);

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

    private function host(App $app, ?Workspace $workspace, ?Instance $instance = null): string
    {
        $url = $workspace instanceof Workspace ? $workspace->url() : $this->placement->runtimeUrl($app, $instance);
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        return preg_replace('#^https?://#', '', $url) ?: $app->name;
    }

    /**
     * @return list<Workspace|null>
     */
    private function runtimeContexts(App $app, ?Node $consumer): array
    {
        $contexts = [null];

        foreach ($app->workspaces as $workspace) {
            if ($this->workspaceRoleGuard->allowsWorkspaceTarget($workspace, $consumer)) {
                $contexts[] = $workspace;
            }
        }

        return $contexts;
    }
}
