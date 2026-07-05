<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Models\App;
use App\Models\Node;
use App\Services\DatabaseConnections\EnvFileEditor;
use App\Services\RemoteShell\RemoteEnvFile;
use RuntimeException;

final readonly class AppInstanceEnvApplier
{
    public function __construct(
        private EnvFileEditor $envFileEditor,
        private AppRuntimeContainerRenderer $containerRenderer,
        private AppRuntimeContainerManager $containerManager,
        private RemoteAppCacheClear $cacheClear,
    ) {}

    public function apply(App $app, string $key, string $value): AppInstanceEnvApplyResult
    {
        $app->loadMissing('node');
        $node = $app->node;

        if (! $node instanceof Node) {
            throw new RuntimeException("App '{$app->name}' has no owning node.");
        }

        $envPath = $this->envPath($app);
        $contents = $this->readContents($node, $app, $envPath);
        $updated = $this->envFileEditor->update($contents, [$key => $value]);
        $this->writeContents($node, $app, $envPath, $updated);

        $cacheCleared = false;
        $runtimeOutcome = null;

        if ($app->runtimeKind()->usesPhpRuntimeContainer()) {
            $this->clearCaches($node, $app);
            $cacheCleared = true;
            $runtimeOutcome = $this->containerManager->apply(
                $node,
                $this->containerRenderer->render($app),
            );
        }

        return new AppInstanceEnvApplyResult($envPath, $cacheCleared, $runtimeOutcome);
    }

    private function envPath(App $app): string
    {
        return rtrim($app->path, '/').'/.env';
    }

    private function readContents(Node $node, App $app, string $path): string
    {
        if ($this->shouldUseLocalFilesystem($node) && is_file($path)) {
            return (string) file_get_contents($path);
        }

        return app(RemoteEnvFile::class)->read($node, $path) ?? '';
    }

    private function writeContents(Node $node, App $app, string $path, string $contents): void
    {
        if ($this->shouldUseLocalFilesystem($node)) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0775, true);
            }

            file_put_contents($path, $contents);

            return;
        }

        app(RemoteEnvFile::class)->write($node, $path, $contents);
    }

    private function clearCaches(Node $node, App $app): void
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
