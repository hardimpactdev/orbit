<?php

declare(strict_types=1);

namespace App\Services\WebSockets;

use App\Contracts\RemoteShell;
use App\Models\Node;
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
        private readonly RemoteShell $remoteShell,
        ?string $sourcePath = null,
    ) {
        $this->sourcePath = $this->normalizeSourcePath($sourcePath ?? resource_path('websocket-runtime'));
    }

    public function install(Node $node): void
    {
        $files = $this->sourceFiles();
        $sourceHash = $this->sourceHash($files);

        $this->remoteShell->run($node, $this->installScript($sourceHash), [
            'throw' => true,
            'input' => base64_encode($this->sourceArchive($files)),
            'metadata' => [
                'ORBIT_OPERATION_ID' => 'websocket-runtime-source-install',
            ],
        ]);
    }

    private function installScript(string $sourceHash): string
    {
        return sprintf(
            <<<'SH'
set -e
runtime_root=%s
release_dir="${runtime_root}/releases/%s"
shared_dir="${runtime_root}/shared"
shared_env="${shared_dir}/.env"
apps_config=%s
expected_hash=%s
source_archive="$(mktemp)"
cleanup() {
    rm -f "$source_archive"
}
trap cleanup EXIT

cat > "$source_archive"

sudo install -d -m 0755 "$runtime_root" "${runtime_root}/releases" "$shared_dir" "$(dirname "$apps_config")"

if ! sudo test -f "$apps_config"; then
    printf '%%s\n' '<?php return [];' | sudo tee "$apps_config" >/dev/null
    sudo chmod 0644 "$apps_config"
fi

current_hash="$(sudo cat "${release_dir}/.orbit-websocket-source-hash" 2>/dev/null || true)"

if [ "$current_hash" != "$expected_hash" ]; then
    sudo rm -rf "$release_dir"
    sudo install -d -m 0755 "$release_dir"
    base64 -d "$source_archive" | sudo tar -xf - -C "$release_dir"
    sudo find "$release_dir" -type d -exec chmod 0755 {} +
    sudo find "$release_dir" -type f -exec chmod 0644 {} +
    sudo chmod 0755 "${release_dir}/artisan"
fi

if ! sudo test -f "$shared_env"; then
    app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    printf 'APP_KEY=%%s\n' "$app_key" | sudo tee "$shared_env" >/dev/null
    sudo chmod 0600 "$shared_env"
elif ! sudo grep -q '^APP_KEY=' "$shared_env"; then
    app_key="base64:$(head -c 32 /dev/urandom | base64 | tr -d '\n')"
    printf 'APP_KEY=%%s\n' "$app_key" | sudo tee -a "$shared_env" >/dev/null
fi

sudo ln -sfn "$shared_env" "${release_dir}/.env"

if ! sudo test -f "${release_dir}/vendor/autoload.php"; then
    if ! command -v composer >/dev/null 2>&1; then
        printf 'WebSocket runtime dependencies require host composer.\n' >&2
        exit 1
    fi

    cd "$release_dir"
    sudo env COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress
fi

printf '%%s\n' "$expected_hash" | sudo tee "${release_dir}/.orbit-websocket-source-hash" >/dev/null
sudo ln -sfn "releases/${expected_hash}" %s
SH,
            escapeshellarg(self::RuntimeRoot),
            $sourceHash,
            escapeshellarg(self::AppsConfigPath),
            escapeshellarg($sourceHash),
            escapeshellarg(WebSocketRuntimeContainer::SourceHostPath),
        );
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
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
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
        $relativePath = substr($file->getPathname(), strlen($this->sourcePath) + 1);

        return str_replace(DIRECTORY_SEPARATOR, '/', $relativePath);
    }

    private function shouldSkip(string $relativePath): bool
    {
        return $relativePath === '.env'
            || $relativePath === 'vendor'
            || str_starts_with($relativePath, 'vendor/')
            || preg_match('#^bootstrap/cache/(?!\.gitignore$)#', $relativePath) === 1;
    }

    /**
     * @param  list<array{path: string, contents: string, executable: bool}>  $files
     */
    private function assertRequiredFiles(array $files): void
    {
        $paths = array_column($files, 'path');

        foreach (['artisan', 'bootstrap/app.php', 'composer.json', 'composer.lock', 'config/reverb.php'] as $requiredPath) {
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
}
