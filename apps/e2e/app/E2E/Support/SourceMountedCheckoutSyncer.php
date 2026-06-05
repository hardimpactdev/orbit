<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;

final readonly class SourceMountedCheckoutSyncer
{
    public const string VendorArchiveDirectory = '.orbit-e2e-vendor-archives';

    private const string DefaultRemoteRoot = '/tmp/orbit-e2e-sources';

    private const string RemoteComposerCache = '/tmp/orbit-e2e-composer-cache';

    private const string RemoteComposerHome = '/tmp/orbit-e2e-composer-home';

    private const string ContainerComposerCache = '/tmp/orbit-composer-cache';

    private const string ContainerComposerHome = '/tmp/orbit-composer-home';

    private const int SyncTimeoutSeconds = 1200;

    public function sync(string $host, string $provider, ?E2EPhaseTimer $timer = null): string
    {
        $targetPath = $this->sourcePath($host, $provider);

        if ($this->isLocalHost($host)) {
            return $targetPath;
        }

        $sync = function () use ($host, $targetPath): void {
            $this->prepareTargetAndAcquireLock($host, $targetPath);

            try {
                $this->mustRun($this->sshCommand($host, $this->ownershipRepairCommand($targetPath)), "Could not repair source checkout ownership on {$host}:{$targetPath}");
                $this->mustRun($this->rsyncCommand($host, $targetPath), "Could not rsync source checkout to {$host}:{$targetPath}");
                $this->mustRun($this->sshCommand($host, $this->staleMutableStateCleanupCommand($targetPath)), "Could not clear stale source checkout state on {$host}:{$targetPath}");
                $hydration = $this->dependencyHydrationSshCommand($host, $targetPath);
                $this->mustRun($hydration['command'], "Could not hydrate source checkout dependencies on {$host}:{$targetPath}", input: $hydration['input']);
                $this->mustRun($this->sshCommand($host, $this->vendorArchiveCommand($targetPath)), "Could not archive source checkout vendor dependencies on {$host}:{$targetPath}");
                $this->mustRun($this->sshCommand($host, $this->permissionNormalizationCommand($targetPath)), "Could not normalize source checkout permissions on {$host}:{$targetPath}");
            } finally {
                $this->releaseLock($host, $targetPath);
            }
        };

        if ($timer !== null) {
            $timer->measure('source-sync', $sync);
        } else {
            $sync();
        }

        return $targetPath;
    }

    public function sourcePath(string $host, string $provider): string
    {
        $configuredPath = $this->configuredSourcePath($host, $provider);

        if ($configuredPath !== null) {
            return $configuredPath;
        }

        if ($this->isLocalHost($host)) {
            return repo_path();
        }

        return self::DefaultRemoteRoot.'/'.$this->worktreeSlug($provider);
    }

    public static function vendorArchiveRelativePath(string $appPath): string
    {
        $slug = str_replace('/', '-', trim($appPath, '/'));

        return self::VendorArchiveDirectory."/{$slug}-vendor.tar";
    }

    /**
     * @return list<string>
     */
    public static function rsyncExcludePatterns(): array
    {
        $patterns = [
            './.orbit-e2e-source-sync.lock',
            ...E2ECurrentCheckout::archiveExcludePatterns(),
        ];

        return array_values(array_unique(array_map(
            fn (string $pattern): string => '/'.ltrim(self::normalizeExcludePattern($pattern), '/'),
            $patterns,
        )));
    }

    private function configuredSourcePath(string $host, string $provider): ?string
    {
        $prefix = match ($provider) {
            'docker' => 'ORBIT_E2E_DOCKER_SOURCE_PATH',
            'incus' => 'ORBIT_E2E_INCUS_SOURCE_PATH',
            default => throw new RuntimeException("Unsupported source-mounted checkout provider [{$provider}]."),
        };

        $hostSpecificPath = getenv($prefix.'_'.self::environmentSuffix($host));

        if (is_string($hostSpecificPath) && trim($hostSpecificPath) !== '') {
            return trim($hostSpecificPath);
        }

        $sourcePath = getenv($prefix);

        if (is_string($sourcePath) && trim($sourcePath) !== '') {
            return trim($sourcePath);
        }

        return null;
    }

    private static function environmentSuffix(string $host): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]+/', '_', $host));
    }

    private static function normalizeExcludePattern(string $pattern): string
    {
        $pattern = str_replace('\\', '/', $pattern);

        return str_starts_with($pattern, './') ? substr($pattern, 2) : $pattern;
    }

    private function worktreeSlug(string $provider): string
    {
        $base = basename(repo_path());
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $base));
        $slug = trim($slug, '-._');
        $slug = $slug !== '' ? $slug : 'orbit';
        $providerSlug = self::pathSlug($provider, 'provider');
        $workerToken = getenv('TEST_TOKEN');
        $workerSlug = is_string($workerToken) && trim($workerToken) !== ''
            ? '-worker-'.self::pathSlug($workerToken, 'test')
            : '';

        return "{$slug}-{$providerSlug}{$workerSlug}-".substr(sha1(repo_path()), 0, 12);
    }

    private static function pathSlug(string $value, string $fallback): string
    {
        $slug = strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $value));
        $slug = trim($slug, '-._');

        return $slug !== '' ? $slug : $fallback;
    }

    private function prepareTargetAndAcquireLock(string $host, string $targetPath): void
    {
        $lockPath = "{$targetPath}/.orbit-e2e-source-sync.lock";
        $command = implode("\n", [
            'target='.escapeshellarg($targetPath),
            'lock='.escapeshellarg($lockPath),
            'mkdir -p "$target"',
            'attempt=0',
            'while ! mkdir "$lock" 2>/dev/null; do',
            '  if [ -f "$lock/created_at" ]; then',
            '    created_at="$(cat "$lock/created_at" 2>/dev/null || printf 0)"',
            '    now="$(date +%s)"',
            '    if [ "$((now - created_at))" -gt 1800 ]; then rm -rf "$lock"; continue; fi',
            '  fi',
            '  attempt="$((attempt + 1))"',
            '  if [ "$attempt" -ge 900 ]; then echo "Timed out waiting for source sync lock $lock" >&2; exit 1; fi',
            '  sleep 1',
            'done',
            'date +%s > "$lock/created_at"',
        ]);

        $this->mustRun($this->sshCommand($host, $command), "Could not acquire source checkout sync lock on {$host}:{$targetPath}", timeoutSeconds: 1000);
    }

    private function releaseLock(string $host, string $targetPath): void
    {
        $this->run($this->sshCommand($host, 'rm -rf '.escapeshellarg("{$targetPath}/.orbit-e2e-source-sync.lock").' || true'), timeoutSeconds: 30);
    }

    private function rsyncCommand(string $host, string $targetPath): string
    {
        $excludes = implode(' ', array_map(
            fn (string $pattern): string => '--exclude '.escapeshellarg($pattern),
            self::rsyncExcludePatterns(),
        ));

        return sprintf(
            'rsync -az --delete %s %s %s',
            $excludes,
            escapeshellarg(repo_path().'/'),
            escapeshellarg("{$host}:{$targetPath}/"),
        );
    }

    private function ownershipRepairCommand(string $targetPath): string
    {
        $image = DockerTopologyBuilder::composerHelperImage();

        return implode("\n", [
            'target='.escapeshellarg($targetPath),
            'if [ ! -d "$target" ]; then exit 0; fi',
            'ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"',
            'ORBIT_E2E_SOURCE_SYNC_GID="$(id -g)"',
            'if command -v docker >/dev/null 2>&1; then',
            '  if ! docker image inspect '.escapeshellarg($image).' >/dev/null 2>&1; then docker pull '.escapeshellarg($image).'; fi',
            sprintf(
                '  docker run --rm --mount "type=bind,src=${target},dst=/work" --env "ORBIT_E2E_SOURCE_SYNC_UID=${ORBIT_E2E_SOURCE_SYNC_UID}" --env "ORBIT_E2E_SOURCE_SYNC_GID=${ORBIT_E2E_SOURCE_SYNC_GID}" %s sh -lc %s',
                escapeshellarg($image),
                escapeshellarg('chown -R "${ORBIT_E2E_SOURCE_SYNC_UID}:${ORBIT_E2E_SOURCE_SYNC_GID}" /work'),
            ),
            'elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then',
            '  sudo chown -R "${ORBIT_E2E_SOURCE_SYNC_UID}:${ORBIT_E2E_SOURCE_SYNC_GID}" "$target"',
            'fi',
        ]);
    }

    private function staleMutableStateCleanupCommand(string $targetPath): string
    {
        $files = [
            './.env',
            './.env.e2e',
            './auth.json',
            './apps/gateway/.env',
            './apps/gateway/.env.e2e',
            './apps/gateway/.env.local',
            './apps/cli/.env',
            './apps/cli/.env.e2e',
            './apps/cli/.env.local',
            './apps/gateway/public/hot',
        ];

        $paths = [
            './.phpunit.cache',
            './build',
            './node_modules',
            './apps/gateway/public/build',
            './apps/gateway/public/storage',
            './apps/gateway/storage/pail',
            './apps/gateway/tests/E2E/.docker-feature-tests',
            './apps/gateway/tests/E2E/.incus-feature-tests',
        ];

        $directories = [
            'apps/gateway/storage/framework/e2e',
            'apps/gateway/storage/app/orbit/ca',
            'apps/gateway/storage/app/orbit/certs',
            'apps/gateway/storage/app/orbit/keys',
            'apps/gateway/storage/framework/cache/data',
            'apps/gateway/storage/framework/sessions',
            'apps/gateway/storage/framework/ssh-known-hosts',
            'apps/gateway/storage/framework/testing',
            'apps/gateway/storage/framework/views',
            'apps/gateway/storage/logs',
        ];

        return implode("\n", [
            'cd '.escapeshellarg($targetPath),
            'rm -f '.implode(' ', array_map(escapeshellarg(...), $files)),
            'rm -rf '.implode(' ', array_map(escapeshellarg(...), $paths)),
            "if [ -d ./apps/gateway/database ]; then find ./apps/gateway/database -maxdepth 1 -type f \\( -name '*.sqlite' -o -name '*.sqlite-*' \\) -delete; fi",
            'for path in '.implode(' ', array_map(escapeshellarg(...), $directories)).'; do',
            '  if [ -d "$path" ]; then find "$path" -mindepth 1 -maxdepth 1 -exec rm -rf {} +; fi',
            'done',
        ]);
    }

    private function dependencyHydrationCommand(string $targetPath): string
    {
        return implode("\n", [
            'if command -v composer >/dev/null 2>&1; then',
            $this->hostDependencyHydrationCommand($targetPath),
            'else',
            $this->containerizedDependencyHydrationCommand($targetPath),
            'fi',
        ]);
    }

    private function hostDependencyHydrationCommand(string $targetPath): string
    {
        return implode("\n", [
            'cd '.escapeshellarg($targetPath),
            'export COMPOSER_ALLOW_SUPERUSER=1',
            'export COMPOSER_PROCESS_TIMEOUT=1200',
            'export COMPOSER_HOME='.escapeshellarg(self::RemoteComposerHome),
            'export COMPOSER_CACHE_DIR='.escapeshellarg(self::RemoteComposerCache),
            'mkdir -p "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR"',
            'trap \'rm -f "$COMPOSER_HOME/auth.json"\' EXIT',
            E2EGitHubAuth::composerAuthConfigCommand(self::RemoteComposerHome),
            $this->gitSafeDirectoryCommand(),
            $this->dependencyHydrationCommandForApp('apps/gateway'),
            $this->dependencyHydrationCommandForApp('apps/cli'),
        ]);
    }

    private function containerizedDependencyHydrationCommand(string $targetPath): string
    {
        $image = DockerTopologyBuilder::composerHelperImage();
        $innerCommand = implode("\n", [
            'set -eu',
            'cd /work',
            'mkdir -p "$COMPOSER_HOME" "$COMPOSER_CACHE_DIR"',
            'trap \'rm -f "$COMPOSER_HOME/auth.json"\' EXIT',
            E2EGitHubAuth::composerAuthConfigCommand(self::ContainerComposerHome),
            $this->gitSafeDirectoryCommand(),
            $this->dependencyHydrationCommandForApp('apps/gateway'),
            $this->dependencyHydrationCommandForApp('apps/cli'),
        ]);
        $githubEnvironment = implode(' ', E2EGitHubAuth::dockerEnvOptions());

        return implode("\n", [
            'if ! command -v docker >/dev/null 2>&1; then echo "Remote source sync requires composer or docker with '.escapeshellarg($image).'" >&2; exit 1; fi',
            'uid="$(id -u)"',
            'gid="$(id -g)"',
            'if ! docker image inspect '.escapeshellarg($image).' >/dev/null 2>&1; then docker pull '.escapeshellarg($image).'; fi',
            sprintf(
                'docker run --rm --mount %s --env "ORBIT_E2E_HOST_UID=${uid}" --env "ORBIT_E2E_HOST_GID=${gid}" %s sh -lc %s',
                escapeshellarg("type=bind,src={$targetPath},dst=/work"),
                escapeshellarg($image),
                escapeshellarg('chown -R "${ORBIT_E2E_HOST_UID}:${ORBIT_E2E_HOST_GID}" /work'),
            ),
            sprintf(
                'docker run --rm --user "${uid}:${gid}" --mount %s --workdir /work --env %s --env %s --env %s --env %s%s %s sh -lc %s',
                escapeshellarg("type=bind,src={$targetPath},dst=/work"),
                escapeshellarg('COMPOSER_ALLOW_SUPERUSER=1'),
                escapeshellarg('COMPOSER_PROCESS_TIMEOUT=1200'),
                escapeshellarg('COMPOSER_HOME='.self::ContainerComposerHome),
                escapeshellarg('COMPOSER_CACHE_DIR='.self::ContainerComposerCache),
                $githubEnvironment !== '' ? ' '.$githubEnvironment : '',
                escapeshellarg($image),
                escapeshellarg($innerCommand),
            ),
        ]);
    }

    private function permissionNormalizationCommand(string $targetPath): string
    {
        return implode("\n", [
            'cd '.escapeshellarg($targetPath),
            'find . -type d -exec chmod a+rx {} +',
            'find . -type f -exec chmod a+r {} +',
            'find . -type f -perm -u+x -exec chmod a+rx {} +',
            implode("\n", [
                'for path in apps/gateway/storage apps/gateway/bootstrap/cache apps/cli/storage apps/cli/bootstrap/cache; do',
                '  if [ -e "$path" ]; then chmod -R a+rwX "$path"; fi',
                'done',
            ]),
        ]);
    }

    private function vendorArchiveCommand(string $targetPath): string
    {
        return implode("\n", [
            'cd '.escapeshellarg($targetPath),
            'archive_dir='.escapeshellarg(self::VendorArchiveDirectory),
            'rm -rf "$archive_dir"',
            'mkdir -p "$archive_dir"',
            $this->vendorArchiveCommandForApp('apps/gateway'),
            $this->vendorArchiveCommandForApp('apps/cli'),
            'chmod a+rx "$archive_dir"',
            'find "$archive_dir" -type f -exec chmod a+r {} +',
        ]);
    }

    private function vendorArchiveCommandForApp(string $appPath): string
    {
        $app = escapeshellarg($appPath);
        $archive = escapeshellarg(self::vendorArchiveRelativePath($appPath));

        return implode(' ', [
            "if [ -f {$app}/vendor/autoload.php ]; then",
            "tar --warning=no-unknown-keyword -C {$app} -cf {$archive} vendor;",
            'fi',
        ]);
    }

    private function dependencyHydrationCommandForApp(string $appPath): string
    {
        $app = escapeshellarg($appPath);

        return implode(' ', [
            "if [ -f {$app}/composer.lock ]; then",
            "mkdir -p {$app}/vendor;",
            "lock_hash=\"$(sha256sum {$app}/composer.lock | awk '{print $1}')\";",
            "marker={$app}/vendor/.orbit-e2e-composer-lock;",
            "if [ -f {$app}/vendor/autoload.php ] && [ -f \"\$marker\" ] && [ \"$(cat \"\$marker\")\" = \"\$lock_hash\" ]; then",
            ':;',
            'else',
            "composer --working-dir={$app} install --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-cache;",
            "printf '%s' \"\$lock_hash\" > \"\$marker\";",
            'fi;',
            'fi',
        ]);
    }

    private function gitSafeDirectoryCommand(): string
    {
        return 'if command -v git >/dev/null 2>&1; then git config --global --add safe.directory '."'*'".' >/dev/null 2>&1 || true; fi';
    }

    private function sshCommand(string $host, string $command): string
    {
        $remoteCommand = sprintf(
            'bash -lc %s',
            escapeshellarg("set -euo pipefail\n{$command}"),
        );

        return sprintf(
            'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
            escapeshellarg($host),
            escapeshellarg($remoteCommand),
        );
    }

    /**
     * @return array{command: string, input: ?string}
     */
    private function dependencyHydrationSshCommand(string $host, string $targetPath): array
    {
        $command = $this->dependencyHydrationCommand($targetPath);
        $input = E2EGitHubAuth::shellInputScript($command);

        if ($input === null) {
            return [
                'command' => $this->sshCommand($host, $command),
                'input' => null,
            ];
        }

        return [
            'command' => sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg($host),
                escapeshellarg('bash -s'),
            ),
            'input' => $input,
        ];
    }

    private function isLocalHost(string $host): bool
    {
        return in_array(strtolower($host), ['local', '', 'localhost', '127.0.0.1', '::1'], true)
            || strtolower($host) === strtolower((string) gethostname());
    }

    private function mustRun(string $command, string $message, ?int $timeoutSeconds = null, ?string $input = null): ProcessResult
    {
        $result = $this->run($command, $timeoutSeconds, $input);

        if (! $result->successful()) {
            throw new RuntimeException("{$message}: ".trim($result->errorOutput().' '.$result->output()));
        }

        return $result;
    }

    private function run(string $command, ?int $timeoutSeconds = null, ?string $input = null): ProcessResult
    {
        $process = Process::timeout($timeoutSeconds ?? self::SyncTimeoutSeconds);

        if ($input !== null) {
            $process->input($input);
        }

        return $process->run($command);
    }
}
