<?php

declare(strict_types=1);

namespace App\Services\Workspaces;

use App\Enums\Apps\AppRuntimeKind;
use App\Models\App;
use App\Models\Node;
use App\Models\Workspace;
use App\Services\Apps\AppRuntimeUser;
use App\Services\Apps\RemoteAppCacheClear;
use App\Services\DatabaseConnections\EnvFileEditor;
use App\Services\RemoteShell\RemoteEnvFile;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity
 */
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
     *
     * @mago-expect lint:halstead
     */
    public function apply(Workspace $workspace, array $updates): WorkspaceEnvApplyResult
    {
        $this->roleGuard->ensureWorkspaceSupported($workspace);
        $workspace->loadMissing('app');
        $app = $workspace->app;
        $node = $this->placement->nodeForWorkspace($workspace);

        if (! $app instanceof App || ! $node instanceof Node) {
            throw new RuntimeException("Workspace '{$workspace->name}' has no Orbit-managed owner.");
        }

        $path = $this->envPath($workspace);
        $envWritten = false;

        try {
            $contents = $this->readContents($node, $path);
            $this->writeContents(
                $node,
                $path,
                $this->envFileEditor->update($contents, $updates),
                $this->runtimeUserForWrite($app, $node, $workspace),
            );
            $envWritten = true;
        } catch (WorkspaceEnvApplyException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw new WorkspaceEnvApplyException(
                phase: 'env_write',
                envWritten: false,
                message: $exception->getMessage(),
                meta: [
                    'path' => $path,
                    'workspace' => $workspace->name,
                ],
                previous: $exception,
            );
        }

        $cacheCleared = false;
        $runtimeOutcome = null;

        if ($app->runtimeKind() === AppRuntimeKind::Php) {
            try {
                $runtimeApp = clone $app;
                $runtimeApp->path = $workspace->path;
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
                    restartIfRunning: true,
                );
            } catch (Throwable $exception) {
                throw new WorkspaceEnvApplyException(
                    phase: 'runtime',
                    envWritten: true,
                    message: $exception->getMessage(),
                    meta: [
                        'path' => $path,
                        'workspace' => $workspace->name,
                        'cache_cleared' => $cacheCleared,
                    ],
                    previous: $exception,
                );
            }
        }

        return new WorkspaceEnvApplyResult($path, $cacheCleared, $runtimeOutcome, envWritten: $envWritten);
    }

    public function envPath(Workspace $workspace): string
    {
        return rtrim(string: $workspace->path, characters: '/').'/.env';
    }

    private function readContents(Node $node, string $path): string
    {
        if ($this->shouldUseLocalFilesystem($node)) {
            if (! is_file($path)) {
                return '';
            }

            $contents = file_get_contents($path);

            if (! is_string($contents)) {
                throw new RuntimeException("Env file could not be read at {$path}.");
            }

            return $contents;
        }

        return app(RemoteEnvFile::class)->readForApply($node, $path) ?? '';
    }

    private function writeContents(Node $node, string $path, string $contents, ?string $runtimeUser): void
    {
        if ($this->shouldUseLocalFilesystem($node)) {
            $this->writeLocalContents($path, $contents);

            return;
        }

        app(RemoteEnvFile::class)->write($node, $path, $contents, $runtimeUser);
    }

    private function writeLocalContents(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o775, recursive: true)
            && ! is_dir($directory)
        ) {
            throw new RuntimeException("Env file directory could not be created at {$directory}.");
        }

        $mode = is_file($path) ? fileperms($path) & 0o777 : 0o600;
        $temporary = $directory.'/.env.tmp.'.bin2hex(random_bytes(8));

        try {
            if (file_put_contents($temporary, $contents, LOCK_EX) === false) {
                throw new RuntimeException("Env file could not be written at {$path}.");
            }

            if (! chmod($temporary, $mode)) {
                throw new RuntimeException("Env file permissions could not be set at {$path}.");
            }

            if (! rename($temporary, $path)) {
                throw new RuntimeException("Env file could not be published at {$path}.");
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }

    private function runtimeUserForWrite(App $app, Node $node, Workspace $workspace): ?string
    {
        $runtimeApp = clone $app;
        $runtimeApp->path = $workspace->path;

        $user = $this->runtimeUser->forApp($runtimeApp);

        // Production-style /home/<user>/app paths require the owning runtime user.
        // Development workspaces under the node user write without sudo elevation.
        if (preg_match('#\A/home/[^/]+/app/#', $workspace->path) === 1) {
            return $user;
        }

        return null;
    }

    private function shouldUseLocalFilesystem(Node $node): bool
    {
        return $node->hasActiveRole('gateway');
    }
}
