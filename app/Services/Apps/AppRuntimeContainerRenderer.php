<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Services\Php\PhpRuntimePolicy;
use App\Services\Runtime\OrbitContainerNames;
use InvalidArgumentException;

final readonly class AppRuntimeContainerRenderer
{
    public function __construct(
        private PhpRuntimePolicy $phpRuntimePolicy,
        private OrbitContainerNames $names,
    ) {}

    public function render(App $app, ?string $preloadPath = null): AppRuntimeContainer
    {
        if ($app->runtime_kind !== AppRuntimeKind::Php) {
            throw new InvalidArgumentException(
                "App '{$app->name}' uses runtime kind '{$app->runtime_kind->value}' and does not get a FrankenPHP runtime container.",
            );
        }

        $policy = $this->phpRuntimePolicy->forVersion($app->php_version, $preloadPath);
        $sourcePath = rtrim((string) $app->path, '/');

        if ($sourcePath === '') {
            throw new InvalidArgumentException("App '{$app->name}' has no source path; cannot render runtime container.");
        }

        return new AppRuntimeContainer(
            name: $this->containerName($app),
            image: $policy->image,
            network: $this->names->network(),
            restartPolicy: 'unless-stopped',
            appSlug: $app->name,
            environment: $this->environmentFor($app),
            mounts: [
                [
                    'source' => $sourcePath,
                    'target' => AppRuntimeContainer::SourceTarget,
                    'read_only' => false,
                ],
                [
                    'source' => $this->phpIniHostPath($app),
                    'target' => AppRuntimeContainer::PhpIniMountTarget,
                    'read_only' => true,
                ],
            ],
            networkAliases: [
                $this->containerName($app),
                "app-{$app->name}",
            ],
            phpIni: $policy->phpIni,
        );
    }

    public function containerName(App $app): string
    {
        return "orbit-app-{$app->name}";
    }

    public function phpIniHostPath(App $app): string
    {
        return "/etc/orbit/apps/{$app->name}.ini";
    }

    public function upstreamUrl(App $app): string
    {
        return 'http://'.$this->containerName($app);
    }

    /**
     * @return array<string, string>
     */
    private function environmentFor(App $app): array
    {
        return [
            // FrankenPHP-consumed envs: SERVER_NAME sets the Caddy listener
            // address; SERVER_ROOT sets the document root served inside the
            // container. Together they make app:root and app:new actually
            // change the served URL boundary.
            'SERVER_NAME' => ':80',
            'SERVER_ROOT' => $this->documentRootInContainer($app),
            'ORBIT_APP' => $app->name,
            'ORBIT_APP_DOCUMENT_ROOT' => $app->document_root,
            'ORBIT_PHP_VERSION' => $app->php_version,
        ];
    }

    public function documentRootInContainer(App $app): string
    {
        $documentRoot = trim((string) $app->document_root, '/');

        if ($documentRoot === '' || $documentRoot === '.') {
            return AppRuntimeContainer::SourceTarget;
        }

        return AppRuntimeContainer::SourceTarget.'/'.$documentRoot;
    }
}
