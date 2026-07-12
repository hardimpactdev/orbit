<?php

declare(strict_types=1);

namespace App\Librarian;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final readonly class TransitionalSshConsumerFinder
{
    /** @var list<string> */
    private const array SOURCE_ROOTS = [
        'apps/gateway/app',
        'apps/cli/app',
    ];

    /** @var list<string> */
    private const array CONSUMER_PATTERNS = [
        'use App\\Contracts\\RemoteShell;',
        'use App\\Contracts\\StartsRemoteShellProcesses;',
        'use App\\Services\\RemoteShell\\RemoteExecutor;',
        'use App\\Services\\RemoteShell\\SshCommandBuilder;',
        'NodeTransportPreference::TransitionalSshFallback',
        'ssh_bootstrap_binary',
    ];

    /** @var list<string> */
    private const array CLI_SELECTOR_PATTERNS = [
        '--node-transport',
        'transitional-ssh-fallback',
    ];

    /** @var list<string> */
    private const array EXCLUDED_PATHS = [
        'apps/gateway/app/Providers/AppServiceProvider.php',
        'apps/gateway/app/Contracts/RemoteShell.php',
        'apps/gateway/app/Contracts/StartsRemoteShellProcesses.php',
        'apps/gateway/app/Data/RemoteShell/RemoteShellPoolJob.php',
        'apps/gateway/app/Services/RemoteShell/RemoteExecutor.php',
        'apps/gateway/app/Services/RemoteShell/RunsInternalCommands.php',
        'apps/gateway/app/Services/RemoteShell/RemoteShellScriptComposer.php',
        'apps/gateway/app/Services/RemoteShell/SshCommandBuilder.php',
    ];

    /** @var list<string> */
    private const array EXPLICIT_EXECUTOR_PATHS = [
        'apps/gateway/app/Services/NodeCommandTransport/NodeCommandTransportSelector.php',
        'apps/gateway/app/Services/RemoteShell/ExplicitRemoteShellFallback.php',
        'apps/gateway/app/Services/RemoteShell/RemoteHostExecutor.php',
        'apps/gateway/app/Services/RemoteShell/RemoteLocalExecutor.php',
        'apps/gateway/app/Services/RemoteShell/RemoteOrbitGatewayExecutor.php',
        'apps/gateway/app/Services/RemoteShell/SshRemoteShell.php',
    ];

    /**
     * @return array<string, string>
     */
    public function find(): array
    {
        $files = [];
        $root = $this->repositoryRoot();

        foreach (self::SOURCE_ROOTS as $relativeSourceRoot) {
            $sourceRoot = "{$root}/{$relativeSourceRoot}";
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sourceRoot));

            foreach ($iterator as $file) {
                if (! $file instanceof SplFileInfo || ! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                $path = $this->relativePath($file->getPathname(), $root);

                if (in_array($path, self::EXCLUDED_PATHS, strict: true)) {
                    continue;
                }

                $contents = file_get_contents($file->getPathname());

                if (! is_string($contents) || ! $this->isConsumer($path, $contents)) {
                    continue;
                }

                $files[$path] = $contents;
            }
        }

        ksort($files);

        return $files;
    }

    /** @return list<string> */
    public function sourceRoots(): array
    {
        return self::SOURCE_ROOTS;
    }

    /** @return list<string> */
    public function consumerPatterns(): array
    {
        return self::CONSUMER_PATTERNS;
    }

    /** @return list<string> */
    public function cliSelectorPatterns(): array
    {
        return self::CLI_SELECTOR_PATTERNS;
    }

    /** @return list<string> */
    public function explicitExecutorPaths(): array
    {
        return self::EXPLICIT_EXECUTOR_PATHS;
    }

    private function isConsumer(string $path, string $contents): bool
    {
        if (in_array($path, self::EXPLICIT_EXECUTOR_PATHS, strict: true)) {
            return true;
        }

        if (
            str_starts_with($path, 'apps/cli/app/Commands/')
            && array_all(
                self::CLI_SELECTOR_PATTERNS,
                static fn (string $pattern): bool => str_contains($contents, $pattern),
            )
        ) {
            return true;
        }

        return array_any(
            self::CONSUMER_PATTERNS,
            static fn (string $pattern): bool => str_contains($contents, $pattern),
        );
    }

    private function repositoryRoot(): string
    {
        $root = realpath(base_path('../..'));

        return $root === false ? base_path('../..') : $root;
    }

    private function relativePath(string $path, string $root): string
    {
        $normalizedPath = str_replace(search: '\\', replace: '/', subject: substr($path, strlen($root)));

        return ltrim(string: $normalizedPath, characters: '/');
    }
}
