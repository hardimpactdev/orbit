<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Data\Apps\OrbitAppInstanceDriverConfigData;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Project;
use App\Services\DatabaseConnections\EnvFileEditor;
use App\Services\RemoteShell\RemoteEnvFile;
use App\Services\Workspaces\WorkspacePlacement;
use RuntimeException;

final readonly class AppInstanceEnvApplier
{
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private EnvFileEditor $envFileEditor,
        private AppRuntimeContainerRenderer $containerRenderer,
        private AppRuntimeContainerManager $containerManager,
        private RemoteAppCacheClear $cacheClear,
        private WorkspacePlacement $placement,
        private AppRuntimeUser $runtimeUser,
    ) {}

    public function apply(Project $app, AppInstance $instance, string $key, string $value): AppInstanceEnvApplyResult
    {
        $node = $this->placement->nodeForInstance($instance);

        if (! $node instanceof Node) {
            throw new RuntimeException(
                "Instance '{$app->name}.{$instance->name}' has no Orbit-managed owning node.",
            );
        }

        $envPath = $this->envPath($instance);

        if ($envPath === null) {
            throw new RuntimeException(
                "Instance '{$app->name}.{$instance->name}' has no Orbit-managed source path.",
            );
        }

        $runtimeApp = $this->containerRenderer->runtimeAppForInstance($app, $instance);
        $contents = $this->readContents($node, $envPath);
        $updated = $this->envFileEditor->update($contents, [$key => $value]);
        $this->writeContents(
            $node,
            $envPath,
            $updated,
            $this->runtimeUser->forApp($runtimeApp),
        );

        $cacheCleared = false;
        $runtimeOutcome = null;

        if ($app->runtimeKind()->usesPhpRuntimeContainer()) {
            $this->clearCaches($node, $runtimeApp);
            $cacheCleared = true;
            $runtimeOutcome = $this->containerManager->apply(
                $node,
                $this->containerRenderer->renderForInstance($app, $instance),
            );
        }

        return new AppInstanceEnvApplyResult($envPath, $cacheCleared, $runtimeOutcome);
    }

    public function envPath(AppInstance $instance): ?string
    {
        $config = $instance->driver_config;

        if (
            ! $config instanceof OrbitAppInstanceDriverConfigData
            || ! is_string($config->path)
            || trim($config->path) === ''
        ) {
            return null;
        }

        return rtrim($config->path, '/').'/.env';
    }

    private function readContents(Node $node, string $path): string
    {
        if ($this->shouldUseLocalFilesystem($node) && is_file($path)) {
            return (string) file_get_contents($path);
        }

        return app(RemoteEnvFile::class)->read($node, $path) ?? '';
    }

    private function writeContents(
        Node $node,
        string $path,
        string $contents,
        string $runtimeUser,
    ): void {
        if ($this->shouldUseLocalFilesystem($node)) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            file_put_contents($path, $contents);

            return;
        }

        app(RemoteEnvFile::class)->write($node, $path, $contents, $runtimeUser);
    }

    private function clearCaches(Node $node, Project $app): void
    {
        $result = $this->cacheClear->clear($node, $app);

        if (! $result->successful()) {
            throw new RuntimeException($result->output());
        }
    }

    private function shouldUseLocalFilesystem(Node $node): bool
    {
        return $node->hasActiveRole('gateway');
    }
}
