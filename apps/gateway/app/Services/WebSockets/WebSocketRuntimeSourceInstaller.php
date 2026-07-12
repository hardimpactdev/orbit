<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Data\RemoteShell\RemoteShellResult;
use App\Models\Node;
use App\Services\RemoteShell\RemoteShellSuccessData;
use App\Services\RemoteShell\RunsInternalCommands;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

class WebSocketRuntimeSourceInstaller
{
    public const RuntimeRoot = '/opt/orbit/websocket';

    public const AppsConfigPath = '/etc/orbit/websocket/apps.php';

    private readonly string $sourcePath;

    public function __construct(
        private readonly RunsInternalCommands $localExecutor,
        private readonly ?WebSocketRoleBaselineTiming $timing = null,
        ?string $sourcePath = null,
    ) {
        $this->sourcePath = rtrim($sourcePath ?? repo_path('apps/reverb'), DIRECTORY_SEPARATOR);
    }

    public function install(Node $node): void
    {
        $files = $this->timer()->measure('source-files', fn (): array => $this->sourceFiles());
        $sourceHash = $this->timer()->measure('source-hash', fn (): string => $this->sourceHash($files));
        $sourceArchive = $this->timer()->measure('source-archive', fn (): string => $this->sourceArchive($files));

        /** @var RemoteShellResult $result */
        $result = $this->timer()->measure(
            'source-remote',
            fn () => $this->localExecutor->runInternal(
                node: $node,
                commandName: 'internal:websocket-runtime',
                arguments: ['source:install'],
                transportOptions: [
                    'throw' => true,
                    'input' => json_encode([
                        'source_hash' => $sourceHash,
                        'archive_base64' => base64_encode((string) $sourceArchive),
                    ], JSON_THROW_ON_ERROR),
                    'metadata' => [
                        'ORBIT_OPERATION_ID' => 'websocket-runtime-source-install',
                    ],
                    'strict' => false,
                    'timeout' => 360,
                ],
            ),
        );

        $data = RemoteShellSuccessData::fromJsonEnvelope($result);
        $stdout = $data['stdout'] ?? null;

        $this->recordRemoteTimings(is_string($stdout) ? $stdout : $result->stdout);
    }

    private function normalizeSourcePath(string $sourcePath): string
    {
        $sourcePath = rtrim($sourcePath, DIRECTORY_SEPARATOR);

        if ($sourcePath === '' || ! is_dir($sourcePath)) {
            throw new InvalidArgumentException("WebSocket runtime source path [{$sourcePath}] does not exist.");
        }

        return $sourcePath;
    }

    /**
     * @return list<array{path: string, contents: string, executable: bool}>
     */
    private function sourceFiles(): array
    {
        $files = [];
        $sourcePath = $this->normalizeSourcePath($this->sourcePath);
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (! $file instanceof SplFileInfo || ! $file->isFile()) {
                continue;
            }

            $relativePath = $this->relativePath($file);

            if ($this->shouldSkip($relativePath)) {
                continue;
            }

            $files[] = [
                'path' => $relativePath,
                'contents' => File::get($file->getPathname()),
                'executable' => $relativePath === 'artisan',
            ];
        }

        usort($files, fn (array $a, array $b): int => $a['path'] <=> $b['path']);

        $this->assertRequiredFiles($files);

        return $files;
    }

    private function relativePath(SplFileInfo $file): string
    {
        $sourcePath = $this->normalizeSourcePath($this->sourcePath);
        $relativePath = substr($file->getPathname(), strlen($sourcePath) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
    }

    private function shouldSkip(string $relativePath): bool
    {
        return (
            $relativePath === '.env'
            || $relativePath === 'vendor'
            || str_starts_with($relativePath, 'vendor/')
            || preg_match('#^bootstrap/cache/(?!\.gitignore$)#', $relativePath) === 1
        );
    }

    /**
     * @param  list<array{path: string, contents: string, executable: bool}>  $files
     */
    private function assertRequiredFiles(array $files): void
    {
        $paths = array_column($files, 'path');

        foreach ([
            'artisan',
            'bootstrap/app.php',
            'composer.json',
            'composer.lock',
            'config/reverb.php',
        ] as $requiredPath) {
            if (! in_array($requiredPath, $paths, true)) {
                throw new RuntimeException("WebSocket runtime source is missing [{$requiredPath}].");
            }
        }
    }

    /**
     * @param  list<array{path: string, contents: string, executable: bool}>  $files
     */
    private function sourceHash(array $files): string
    {
        $context = hash_init('sha256');

        foreach ($files as $file) {
            hash_update($context, $file['path']."\0".hash('sha256', $file['contents'])."\0");
        }

        return hash_final($context);
    }

    /**
     * @param  list<array{path: string, contents: string, executable: bool}>  $files
     */
    private function sourceArchive(array $files): string
    {
        $basePath = tempnam(sys_get_temp_dir(), 'orbit-websocket-source-');

        if ($basePath === false) {
            throw new RuntimeException('Could not create a temporary WebSocket runtime source archive path.');
        }

        $tarPath = "{$basePath}.tar";
        @unlink($basePath);

        try {
            $archive = new PharData($tarPath);

            foreach ($files as $file) {
                $archive->addFromString($file['path'], $file['contents']);
            }

            $contents = file_get_contents($tarPath);

            if ($contents === false) {
                throw new RuntimeException('Could not read the WebSocket runtime source archive.');
            }

            return $contents;
        } finally {
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
        }
    }

    private function recordRemoteTimings(string $output): void
    {
        if (
            preg_match_all('/__orbit_websocket_source_timing\s+([a-z-]+)\s+(\d+)/', $output, $matches, PREG_SET_ORDER)
            === false
        ) {
            return;
        }

        foreach ($matches as $match) {
            $this->timer()->record("source-{$match[1]}", (int) $match[2]);
        }
    }

    private function timer(): WebSocketRoleBaselineTiming
    {
        return $this->timing ?? app(WebSocketRoleBaselineTiming::class);
    }
}
