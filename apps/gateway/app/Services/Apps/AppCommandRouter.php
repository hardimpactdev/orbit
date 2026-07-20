<?php

declare(strict_types=1);

namespace App\Services\Apps;

use App\Contracts\AppRuntimeUserResolver;
use App\Enums\Apps\AppRuntimeKind;
use App\Models\Node;
use App\Models\Project;
use App\Services\Nodes\NodeHostPaths;

final readonly class AppCommandRouter
{
    public function __construct(
        private AppRuntimeUserResolver $appRuntimeUser = new AppRuntimeUser,
    ) {}

    /**
     * Route PHP, Composer, and Artisan commands through Orbit's version-matched
     * host PHP toolchain. Non-PHP commands run as provided in the app path.
     *
     * @param array<string, string> $environment
     */
    public function route(Project $app, string $command, array $environment = []): string
    {
        return $this->routeForPath($app, $command, $app->path, $environment);
    }

    /**
     * Route PHP, Composer, and Artisan commands through Orbit's version-matched
     * host PHP toolchain while using a caller-selected source path. Non-PHP
     * commands run as provided in the caller's working directory.
     *
     * @param array<string, string> $environment
     */
    public function routeForPath(Project $app, string $command, string $path, array $environment = []): string
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return $command;
        }

        if (! $this->usesPhpTools($command)) {
            return $command;
        }

        $runtimeUser = $this->appRuntimeUser->forApp($app);

        return $this->wrapWithPathAssignment(
            command: $command,
            path: $path,
            environment: $environment,
            pathAssignment: 'PATH=/opt/orbit/php/'.escapeshellarg($app->php_version).'/bin:$PATH ',
            runtimeUser: $runtimeUser,
        );
    }

    /**
     * Route lifecycle setup/teardown commands through the selected app user's
     * host toolchain while using a caller-selected source path.
     *
     * @param array<string, string> $environment
     */
    public function routeLifecycleForPath(Project $app, string $command, string $path, array $environment = []): string
    {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return $command;
        }

        $runtimeUser = $this->appRuntimeUser->forApp($app);

        return $this->wrapWithPathAssignment(
            command: $command,
            path: $path,
            environment: $environment,
            pathAssignment: $this->managedToolPathAssignment($app, $runtimeUser),
            runtimeUser: $runtimeUser,
        );
    }

    /**
     * Route workspace lifecycle commands through the selected workspace node's
     * host toolchain while retaining the app's PHP/runtime settings.
     *
     * @param array<string, string> $environment
     */
    public function routeLifecycleForNodePath(
        Project $app,
        Node $node,
        string $command,
        string $path,
        array $environment = [],
    ): string {
        if ($app->runtimeKind() !== AppRuntimeKind::Php) {
            return $command;
        }

        $runtimeUser = is_string($node->user) && trim($node->user) !== ''
            ? trim($node->user)
            : 'orbit';

        return $this->wrapWithPathAssignment(
            command: $command,
            path: $path,
            environment: $environment,
            pathAssignment: $this->managedToolPathAssignment($app, $runtimeUser, $node),
            runtimeUser: $runtimeUser,
        );
    }

    public function usesPhpTools(string $command): bool
    {
        $normalized = preg_replace('/[\'"].*?[\'"]/', '', $command);

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php-fpm\b/', (string) $normalized) === 1) {
            return false;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php\d+\.\d+-fpm\b/', (string) $normalized) === 1) {
            return false;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*php\s/', (string) $normalized) === 1) {
            return true;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*composer\s/', (string) $normalized) === 1) {
            return true;
        }

        if (preg_match('/(?:^|\s|&&|\|\||;)\s*artisan\b/', (string) $normalized) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, string>  $environment
     */
    private function wrapWithPathAssignment(
        string $command,
        string $path,
        array $environment,
        string $pathAssignment,
        string $runtimeUser,
    ): string {
        $workingDirectory = rtrim($path, '/');
        $envPrefix = '';

        foreach ($environment as $key => $value) {
            $envPrefix .= "{$key}=".escapeshellarg($value).' ';
        }

        $inner = 'cd '.escapeshellarg($workingDirectory).' && '.$pathAssignment.$envPrefix.$command;

        return implode(' ', array_map(escapeshellarg(...), ['sudo', '-u', $runtimeUser, '-H', 'bash', '-lc', $inner]));
    }

    private function managedToolPathAssignment(Project $app, string $runtimeUser, ?Node $node = null): string
    {
        $home = $node instanceof Node
            ? NodeHostPaths::homeDirectoryFor($node->platform, $runtimeUser)
            : $this->homeDirectory($app, $runtimeUser);

        $pathPrefix = implode(':', [
            "/opt/orbit/php/{$app->php_version}/bin",
            "{$home}/.local/bin",
            "{$home}/.vite-plus/bin",
            "{$home}/.bun/bin",
            '/opt/homebrew/bin',
            '/opt/homebrew/sbin',
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ]);

        return 'PATH='.escapeshellarg($pathPrefix).':$PATH ';
    }

    private function homeDirectory(Project $app, string $runtimeUser): string
    {
        $app->loadMissing('node');
        $node = $app->node;

        if ($node instanceof Node) {
            return NodeHostPaths::homeDirectoryFor($node->platform, $runtimeUser);
        }

        return NodeHostPaths::homeDirectoryFor(null, $runtimeUser);
    }
}
