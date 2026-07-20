<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\Node;
use App\Models\Project;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeUser;
use App\Services\Apps\RemoteAppCacheClear;
use App\Services\DatabaseConnections\EnvFileEditor;
use App\Services\RemoteShell\RemoteEnvFile;
use RuntimeException;

final readonly class WorkspaceEnvApplier
{
    /**
     * @mago-expect lint:excessive-parameter-list
     */
    public function __construct(
        private EnvFileEditor $envFileEditor,
        private WorkspacePlacement $placement,
        private WorkspaceRuntimeContainerRenderer $containerRenderer,
        private WorkspaceRuntimeContainerManager $containerManager,
        private RemoteAppCacheClear $cacheClear,
        private AppRuntimeUser $runtimeUser,
        private WorkspaceRoleGuard $roleGuard,
    ) {}

    /**
     * @param  array<string, string>  $updates
     */
    public function apply(Workspace $workspace, array $updates): WorkspaceEnvApplyResult
    {
        $this->roleGuard->ensureWorkspaceSupported($workspace);
        $workspace->loadMissing('app');
        $app = $workspace->app;
        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $app instanceof Project || ! $node instanceof Node) {
            throw new RuntimeException("Workspace '{$workspace->name}' has no Orbit-managed owner.");
        }

        $path = $this->envPath($workspace);
        $contents = $this->readContents($node, $path);
        $this->writeContents($node, $path, $this->envFileEditor->update($contents, $updates));

        $cacheCleared = false;
        $runtimeOutcome = null;

        if ($app->runtimeKind() === AppRuntimeKind::Php) {
            $runtimeApp = clone $app;
            $runtimeApp->path = $workspace->path;
            $runtimeApp->setRelation('node', $node);
            $result = $this->cacheClear->clearPath(
                node: $node,
                path: $workspace->path,
                phpVersion: (string) $workspace->effectivePhpVersion(),
                runtimeUser: $this->runtimeUser->forApp($runtimeApp),
            );

            if (! $result->successful()) {
                throw new RuntimeException($result->output());
            }

            $cacheCleared = true;
            $runtimeOutcome = $this->containerManager->apply(
                $node,
                $this->containerRenderer->render($workspace),
            );
        }

        return new WorkspaceEnvApplyResult($path, $cacheCleared, $runtimeOutcome);
    }

    public function envPath(Workspace $workspace): string
    {
        return rtrim(string: $workspace->path, characters: '/').'/.env';
    }

    private function readContents(Node $node, string $path): string
    {
        if ($this->shouldUseLocalFilesystem($node) && is_file($path)) {
            return (string) file_get_contents($path);
        }

        return app(RemoteEnvFile::class)->read($node, $path) ?? '';
    }

    private function writeContents(Node $node, string $path, string $contents): void
    {
        if ($this->shouldUseLocalFilesystem($node)) {
            if (! is_dir(dirname($path))) {
                mkdir(directory: dirname($path), permissions: 0o775, recursive: true);
            }

            file_put_contents($path, $contents);

            return;
        }

        app(RemoteEnvFile::class)->write($node, $path, $contents);
    }

    private function shouldUseLocalFilesystem(Node $node): bool
    {
        return $node->hasActiveRole('gateway');
    }
}
