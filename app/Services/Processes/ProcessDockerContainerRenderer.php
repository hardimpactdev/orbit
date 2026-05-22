<?php

declare(strict_types=1);

namespace App\Services\Processes;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Process;
use App\Models\Workspace;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;

final readonly class ProcessDockerContainerRenderer
{
    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
    ) {}

    public function render(App $app, Process $process, ?Workspace $workspace = null): ProcessDockerContainer
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "App '{$app->name}' uses runtime kind '{$app->runtime_kind->value}' and cannot back a Docker process runtime unit.",
            );
        }

        $phpVersion = $this->resolvePhpVersion($app, $workspace);

        if ($phpVersion === null) {
            throw new InvalidArgumentException(
                "Process '{$process->name}' on app '{$app->name}' has no resolvable PHP version; cannot render Docker process runtime container.",
            );
        }

        $sourcePath = $this->resolveSourcePath($app, $workspace, $process);

        $policy = $this->phpRuntimePolicy->forVersion($phpVersion);

        $name = $this->containerName($app, $process, $workspace);

        return new ProcessDockerContainer(
            name: $name,
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: $process->restart_policy->toDocker(),
            appSlug: $app->name,
            workspaceSlug: $workspace?->name,
            processSlug: $process->name,
            workingDirectory: ProcessDockerContainer::SourceTarget,
            command: $process->command,
            environment: $this->environmentFor($app, $process, $workspace, $phpVersion),
            mounts: [
                [
                    'source' => $sourcePath,
                    'target' => ProcessDockerContainer::SourceTarget,
                    'read_only' => false,
                ],
            ],
            networkAliases: [$name],
        );
    }

    public function containerName(App $app, Process $process, ?Workspace $workspace = null): string
    {
        $this->assertIdentitySlug($app->name);
        $this->assertIdentitySlug($process->name);

        $scope = 'main';

        if ($workspace instanceof Workspace) {
            $this->assertIdentitySlug($workspace->name);
            $scope = $workspace->name;
        }

        return "orbit_{$app->name}_{$scope}_{$process->name}";
    }

    private function resolvePhpVersion(App $app, ?Workspace $workspace): ?string
    {
        if ($workspace instanceof Workspace) {
            $version = $workspace->effectivePhpVersion();

            return is_string($version) && trim($version) !== '' ? trim($version) : null;
        }

        $version = $app->php_version;

        return is_string($version) && trim($version) !== '' ? trim($version) : null;
    }

    private function resolveSourcePath(App $app, ?Workspace $workspace, Process $process): string
    {
        $path = $workspace instanceof Workspace ? $workspace->path : $app->path;
        $path = rtrim((string) $path, '/');

        if ($path === '') {
            throw new InvalidArgumentException(
                "Process '{$process->name}' on app '{$app->name}' has no source path; cannot render Docker process runtime container.",
            );
        }

        return $path;
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(App $app, Process $process, ?Workspace $workspace, string $phpVersion): array
    {
        $url = $workspace instanceof Workspace ? $workspace->url() : $app->url();
        $host = $this->hostFromUrl($url, $app, $workspace);
        $home = '/root';
        $tlsBase = '/etc/orbit/certs/'.$host;

        $environment = [
            'PATH' => '/app/vendor/bin:/app/node_modules/.bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin',
            'HOME' => $home,
            'APP_URL' => $url,
            'VITE_APP_URL' => $url,
            'VITE_VALET_HOST' => $host,
            'VITE_DEV_SERVER_KEY' => $tlsBase.'.key',
            'VITE_DEV_SERVER_CERT' => $tlsBase.'.crt',
            'ORBIT_APP' => $app->name,
            'ORBIT_PHP_VERSION' => $phpVersion,
        ];

        if ($workspace instanceof Workspace) {
            $environment['ORBIT_WORKSPACE'] = $workspace->name;
        }

        return $environment;
    }

    private function hostFromUrl(string $url, App $app, ?Workspace $workspace): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (is_string($host) && $host !== '') {
            return $host;
        }

        $stripped = preg_replace('#^https?://#', '', $url);

        if (is_string($stripped) && $stripped !== '') {
            return $stripped;
        }

        return $workspace instanceof Workspace ? "{$workspace->name}.{$app->name}" : $app->name;
    }

    private function assertIdentitySlug(string $value): void
    {
        if (! preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])?$/', $value)) {
            throw new InvalidArgumentException("Unsafe Docker process runtime identity segment: {$value}");
        }
    }
}
