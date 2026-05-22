<?php

declare(strict_types=1);

namespace App\Actions\Apps;

use App\Contracts\RemoteShell;
use App\Models\App;
use App\Models\Process;
use App\Models\ProxyRoute;
use App\Models\Schedule;
use App\Models\Workspace;
use App\Services\Processes\SupervisorProgramRenderer;
use App\Tools\CaddyTool;
use Illuminate\Support\Facades\DB;

final readonly class RemoveApp
{
    public function __construct(
        private RemoteShell $remoteShell,
        private SupervisorProgramRenderer $supervisorProgramRenderer,
    ) {}

    /**
     * @return array{
     *     app: array<string, mixed>,
     *     result: array{action: string},
     *     cleanup: array{
     *         proxy_routes_removed: int,
     *         workspaces_removed: int,
     *         schedules_removed: int,
     *         processes_removed: int,
     *         fpm_config_removed: bool,
     *         runtime_config_removed: bool,
     *     },
     *     warnings: list<array<string, string>>
     * }
     */
    public function handle(App $app): array
    {
        $app->loadMissing(['node', 'processes']);

        $appPayload = $this->appPayload($app);
        $processProgramNames = $app->processes
            ->map(fn (Process $process): string => $this->supervisorProgramRenderer->programName($app, $process))
            ->values()
            ->all();
        $proxyRouteIds = ProxyRoute::query()
            ->where('app_id', $app->id)
            ->pluck('id')
            ->all();
        $workspacesRemoved = Workspace::query()
            ->where('app_id', $app->id)
            ->count();
        $schedulesRemoved = Schedule::query()
            ->where('app_id', $app->id)
            ->count();
        $processesRemoved = $app->processes()->count();
        $removeAppPath = ! $app->adopted
            && App::query()
                ->where('id', '!=', $app->id)
                ->where('node_id', $app->node_id)
                ->where('path', $app->path)
                ->doesntExist();

        DB::transaction(function () use ($app, $proxyRouteIds): void {
            $app->delete();

            if ($proxyRouteIds !== []) {
                ProxyRoute::query()
                    ->whereIn('id', $proxyRouteIds)
                    ->delete();
            }
        });

        $fpmConfigRemoved = false;
        $runtimeConfigRemoved = false;
        $warnings = [];

        if ($app->node !== null) {
            $fpmResult = $this->remoteShell->run($app->node, $this->renderFpmRemovalScript($app));
            $fpmConfigRemoved = $fpmResult->successful();

            if (! $fpmConfigRemoved) {
                $warnings[] = [
                    'code' => 'app.fpm_config_extra',
                    'family' => 'app',
                    'message' => 'App PHP-FPM configuration could not be removed during cleanup.',
                    'next_command' => 'doctor --fix --family=app --restore',
                ];
            }

            $runtimeResult = $this->remoteShell->run($app->node, $this->renderRuntimeRemovalScript($app, $processProgramNames, $removeAppPath));
            $runtimeConfigRemoved = $runtimeResult->successful();

            if (! $runtimeConfigRemoved) {
                $warnings[] = [
                    'code' => 'app.runtime_config_extra',
                    'family' => 'app',
                    'message' => 'Managed app runtime configuration could not be removed during cleanup.',
                    'next_command' => 'doctor --fix --family=app --restore',
                ];
            }
        }

        return [
            'app' => $appPayload,
            'result' => ['action' => 'removed'],
            'cleanup' => [
                'proxy_routes_removed' => count($proxyRouteIds),
                'workspaces_removed' => $workspacesRemoved,
                'schedules_removed' => $schedulesRemoved,
                'processes_removed' => $processesRemoved,
                'fpm_config_removed' => $fpmConfigRemoved,
                'runtime_config_removed' => $runtimeConfigRemoved,
            ],
            'warnings' => $warnings,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function appPayload(App $app): array
    {
        return [
            'name' => $app->name,
            'node' => $app->node?->name,
            'environment' => $app->environment,
            'url' => $app->url(),
            'path' => $app->path,
            'root' => $app->document_root,
            'repository' => $app->repository,
            'php_version' => $app->php_version,
            'adopted' => $app->adopted,
        ];
    }

    private function renderFpmRemovalScript(App $app): string
    {
        $poolPath = "/etc/php/{$app->php_version}/fpm/pool.d/orbit-{$app->name}.conf";
        $service = "php{$app->php_version}-fpm";

        return sprintf(
            <<<'SH'
sudo rm -f %s
sudo systemctl reload %s || sudo systemctl reload php-fpm || true
SH,
            escapeshellarg($poolPath),
            escapeshellarg($service),
        );
    }

    /**
     * @param  list<string>  $processProgramNames
     */
    private function renderRuntimeRemovalScript(App $app, array $processProgramNames, bool $removeAppPath): string
    {
        $domain = parse_url($app->url(), PHP_URL_HOST) ?: $app->name;
        $commands = [
            'sudo rm -f '.escapeshellarg("/etc/caddy/sites/{$domain}.caddy"),
        ];

        foreach ($processProgramNames as $programName) {
            $commands[] = 'sudo supervisorctl stop '.escapeshellarg($programName).' || true';
            $commands[] = 'sudo rm -f '.escapeshellarg("/etc/supervisor/conf.d/{$programName}.conf");
        }

        if ($processProgramNames !== []) {
            $commands[] = 'sudo supervisorctl reread || true';
            $commands[] = 'sudo supervisorctl update || true';
        }

        $commands[] = CaddyTool::reloadCommand().' || true';

        if ($removeAppPath) {
            $commands[] = 'rm -rf '.escapeshellarg($app->path);
        }

        return implode("\n", $commands);
    }
}
