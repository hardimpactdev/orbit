<?php

declare(strict_types=1);

namespace App\E2E\Support;

use Closure;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LogicException;
use RuntimeException;

final readonly class SourceMountedCheckoutSyncer
{
    public const string VendorArchiveDirectory = '.orbit-e2e-vendor-archives';

    public const string VendorArchiveFingerprintFile = 'dependency-fingerprint.sha256';

    private const string DefaultRemoteRoot = '/tmp/orbit-e2e-sources';

    private const string RemoteComposerCache = '/tmp/orbit-e2e-composer-cache';

    private const string RemoteComposerHome = '/tmp/orbit-e2e-composer-home';

    private const string ContainerComposerCache = '/tmp/orbit-composer-cache';

    private const string ContainerComposerHome = '/tmp/orbit-composer-home';

    private const int SyncTimeoutSeconds = 1200;

    public function sync(
        string $host,
        string $provider,
        ?E2EPhaseTimer $timer = null,
        ?string $scope = null,
    ): string {
        $operation = fn (): string => self::stringResult($this->withSyncLock(
            host: $host,
            provider: $provider,
            criticalSection: static fn (Closure $syncSource): string => self::stringResult($syncSource()),
            scope: $scope,
        ));

        return self::stringResult(
            $timer !== null
                ? $timer->measure('source-sync', $operation)
                : $operation(),
        );
    }

    /**
     * @template TResult
     * @param  Closure(Closure(): string): TResult  $criticalSection
     * @return TResult
     */
    public function withSyncLock(
        string $host,
        string $provider,
        Closure $criticalSection,
        ?string $scope = null,
        ?string $sourcePath = null,
    ): mixed {
        $expectedPath = $this->sourcePath($host, $provider, $scope);

        if ($sourcePath !== null && ! hash_equals($expectedPath, $sourcePath)) {
            throw new RuntimeException(
                "Recorded source path [{$sourcePath}] does not match expected scoped path [{$expectedPath}].",
            );
        }

        return $this->runLockedOperation(
            $host,
            $sourcePath ?? $expectedPath,
            $criticalSection,
        );
    }

    public function sourcePath(string $host, string $provider, ?string $scope = null): string
    {
        $configuredPath = $this->configuredSourcePath($host, $provider);

        $path = match (true) {
            $configuredPath !== null => $configuredPath,
            $this->isLocalHost($host) => repo_path(),
            default => self::DefaultRemoteRoot.'/'.$this->worktreeSlug($provider),
        };

        if ($scope === null || trim($scope) === '' || $this->isLocalHost($host)) {
            return $path;
        }

        return rtrim($path, '/').'/retained/'.self::pathSlug($scope, 'topology');
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
            './tmp-e2e-tree-hash-*',
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
        $pattern = str_replace(search: '\\', replace: '/', subject: $pattern);

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

    private static function stringResult(mixed $result): string
    {
        if (! is_string($result)) {
            throw new LogicException('The source sync operation must return its source path.');
        }

        return $result;
    }

    /**
     * @template TResult
     * @param  Closure(Closure(): string): TResult  $criticalSection
     * @return TResult
     */
    private function runLockedOperation(string $host, string $targetPath, Closure $criticalSection): mixed
    {
        $syncInvoked = false;
        $operation = function (?SourceMountedCheckoutMutationFence $mutationFence = null) use (
            $host,
            $targetPath,
            $criticalSection,
            &$syncInvoked,
        ): mixed {
            $syncSource = function () use ($host, $targetPath, $mutationFence, &$syncInvoked): string {
                if ($syncInvoked) {
                    throw new LogicException('The locked source sync operation may only be invoked once.');
                }

                $syncInvoked = true;

                if (! $this->isLocalHost($host)) {
                    if ($mutationFence === null) {
                        throw new LogicException('Remote source sync requires an active mutation generation.');
                    }

                    $this->syncRemoteSource($host, $targetPath, $mutationFence);
                }

                return $targetPath;
            };

            $result = $criticalSection($syncSource);

            if (! $syncInvoked) {
                throw new LogicException('The locked source sync operation was not invoked.');
            }

            return $result;
        };

        return $this->isLocalHost($host)
            ? $operation()
            : new SourceMountedCheckoutLifecycleLock($host, $targetPath)->run($operation);
    }

    private function syncRemoteSource(
        string $host,
        string $targetPath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): void {
        $this->mustRun(
            $this->sourceMutationSshCommand(
                $host,
                $mutationFence,
                $this->ownershipRepairCommand($targetPath, $mutationFence),
            ),
            "Could not repair source checkout ownership on {$host}:{$targetPath}",
        );
        $rsync = $this->mustRun(
            $this->rsyncCommand($host, $targetPath, $mutationFence),
            "Could not rsync source checkout to {$host}:{$targetPath}",
        );
        $changed = trim($rsync->output()) !== '';
        $this->mustRun(
            $this->sourceMutationSshCommand($host, $mutationFence, $this->staleMutableStateCleanupCommand($targetPath)),
            "Could not clear stale source checkout state on {$host}:{$targetPath}",
        );
        $hydration = $this->dependencyHydrationSshCommand($host, $targetPath, $mutationFence);
        $this->mustRun(
            $hydration['command'],
            "Could not hydrate source checkout dependencies on {$host}:{$targetPath}",
            input: $hydration['input'],
        );
        $vendorArchive = $this->vendorArchiveSshCommand($host, $targetPath, $mutationFence);
        $this->mustRun(
            $vendorArchive['command'],
            "Could not archive source checkout vendor dependencies on {$host}:{$targetPath}",
            input: $vendorArchive['input'],
        );

        if ($changed) {
            $this->mustRun(
                $this->sourceMutationSshCommand(
                    $host,
                    $mutationFence,
                    $this->permissionNormalizationCommand($targetPath),
                ),
                "Could not normalize source checkout permissions on {$host}:{$targetPath}",
            );
        }
    }

    private function rsyncCommand(
        string $host,
        string $targetPath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): string {
        $excludes = implode(' ', array_map(
            fn (string $pattern): string => '--exclude '.escapeshellarg($pattern),
            self::rsyncExcludePatterns(),
        ));

        return sprintf(
            'rsync -az --delete --itemize-changes %s --rsync-path %s %s %s',
            $excludes,
            escapeshellarg($mutationFence->rsyncRemotePath()),
            escapeshellarg(repo_path().'/'),
            escapeshellarg("{$host}:{$targetPath}/"),
        );
    }

    private function ownershipRepairCommand(
        string $targetPath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): string {
        $image = DockerTopologyBuilder::composerHelperImage();
        $guardedChown = $mutationFence->dockerGuardedScript(implode("\n", [
            'set -eu',
            'chown -R "${ORBIT_E2E_SOURCE_SYNC_UID}:${ORBIT_E2E_SOURCE_SYNC_GID}" /work',
        ]));

        return implode("\n", [
            'target='.escapeshellarg($targetPath),
            'mkdir -p "$target"',
            'foreign=""',
            'if [ -d "$target" ]; then',
            '  foreign="$(find "$target" ! -user "$(id -un)" -print -quit 2>/dev/null || true)"',
            'fi',
            'if [ -n "$foreign" ]; then',
            '  ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"',
            '  ORBIT_E2E_SOURCE_SYNC_GID="$(id -g)"',
            '  if command -v docker >/dev/null 2>&1; then',
            '    if ! docker image inspect '
                .escapeshellarg($image)
                .' >/dev/null 2>&1; then docker pull '
                .escapeshellarg($image)
                .'; fi',
            '    '.$this->dockerMutationFencePreflightCommand($image),
            '    '
                .str_replace(
                    search: "\n",
                    replace: "\n    ",
                    subject: $mutationFence->releaseGuardScript(),
                ),
            sprintf(
                '    docker run --rm --mount "type=bind,src=${target},dst=/work" --mount %s --env "ORBIT_E2E_SOURCE_SYNC_UID=${ORBIT_E2E_SOURCE_SYNC_UID}" --env "ORBIT_E2E_SOURCE_SYNC_GID=${ORBIT_E2E_SOURCE_SYNC_GID}" %s sh -lc %s',
                escapeshellarg($mutationFence->dockerLockMount()),
                escapeshellarg($image),
                escapeshellarg($guardedChown),
            ),
            '  elif command -v sudo >/dev/null 2>&1 && sudo -n true >/dev/null 2>&1; then',
            '    sudo chown -R "${ORBIT_E2E_SOURCE_SYNC_UID}:${ORBIT_E2E_SOURCE_SYNC_GID}" "$target"',
            '  fi',
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
            './apps/agent/target',
            './apps/macos/target',
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

    private function dependencyHydrationCommand(
        string $targetPath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): string {
        return implode("\n", [
            'if command -v composer >/dev/null 2>&1; then',
            $this->hostDependencyHydrationCommand($targetPath),
            'else',
            $this->containerizedDependencyHydrationCommand($targetPath, $mutationFence),
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

    private function containerizedDependencyHydrationCommand(
        string $targetPath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): string {
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
        $guardedOwnershipRepair = $mutationFence->dockerGuardedScript(implode("\n", [
            'set -eu',
            'chown -R "${ORBIT_E2E_HOST_UID}:${ORBIT_E2E_HOST_GID}" /work',
        ]));
        $guardedHydration = $mutationFence->dockerGuardedScript($innerCommand);

        return implode("\n", [
            'if ! command -v docker >/dev/null 2>&1; then echo "Remote source sync requires composer or docker with '
                .escapeshellarg($image)
                .'" >&2; exit 1; fi',
            'uid="$(id -u)"',
            'gid="$(id -g)"',
            'if ! docker image inspect '
                .escapeshellarg($image)
                .' >/dev/null 2>&1; then docker pull '
                .escapeshellarg($image)
                .'; fi',
            $this->dockerMutationFencePreflightCommand($image),
            $mutationFence->releaseGuardScript(),
            sprintf(
                'docker run --rm --mount %s --mount %s --env "ORBIT_E2E_HOST_UID=${uid}" --env "ORBIT_E2E_HOST_GID=${gid}" %s sh -lc %s',
                escapeshellarg("type=bind,src={$targetPath},dst=/work"),
                escapeshellarg($mutationFence->dockerLockMount()),
                escapeshellarg($image),
                escapeshellarg($guardedOwnershipRepair),
            ),
            sprintf(
                'docker run --rm --user "${uid}:${gid}" --mount %s --mount %s --workdir /work --env %s --env %s --env %s --env %s%s %s sh -lc %s',
                escapeshellarg("type=bind,src={$targetPath},dst=/work"),
                escapeshellarg($mutationFence->dockerLockMount()),
                escapeshellarg('COMPOSER_ALLOW_SUPERUSER=1'),
                escapeshellarg('COMPOSER_PROCESS_TIMEOUT=1200'),
                escapeshellarg('COMPOSER_HOME='.self::ContainerComposerHome),
                escapeshellarg('COMPOSER_CACHE_DIR='.self::ContainerComposerCache),
                $githubEnvironment !== '' ? ' '.$githubEnvironment : '',
                escapeshellarg($image),
                escapeshellarg($guardedHydration),
            ),
        ]);
    }

    private function dockerMutationFencePreflightCommand(string $image): string
    {
        $message = "Source helper image [{$image}] must provide compatible timeout and flock commands.";
        $preflight = implode(' && ', [
            'command -v flock >/dev/null 2>&1',
            'command -v timeout >/dev/null 2>&1',
            'timeout 2 flock /tmp/orbit-e2e-flock-preflight.lock sh -lc true',
        ]);

        return sprintf(
            'if ! docker run --rm %s sh -lc %s; then echo %s >&2; exit 1; fi',
            escapeshellarg($image),
            escapeshellarg($preflight),
            escapeshellarg($message),
        );
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
        $archivePaths = [
            self::vendorArchiveRelativePath('apps/gateway'),
            self::vendorArchiveRelativePath('apps/cli'),
        ];

        return implode("\n", [
            'cd '.escapeshellarg($targetPath),
            'archive_dir='.escapeshellarg(self::VendorArchiveDirectory),
            'fingerprint_file="$archive_dir/'.self::VendorArchiveFingerprintFile.'"',
            'fingerprint_input="$(mktemp)"',
            'trap \'rm -f "$fingerprint_input"\' EXIT',
            $this->vendorArchiveFingerprintCommand(),
            'fingerprint="$(sha256sum "$fingerprint_input" | cut -d " " -f 1)"',
            'if [ -f "$fingerprint_file" ] && [ "$(cat "$fingerprint_file")" = "$fingerprint" ] && '
                .implode(' && ', array_map(
                    fn (string $path): string => '[ -f '.escapeshellarg($path).' ]',
                    $archivePaths,
                ))
                .'; then',
            '  chmod a+rx "$archive_dir"',
            '  find "$archive_dir" -type f -exec chmod a+r {} +',
            '  exit 0',
            'fi',
            'rm -rf "$archive_dir"',
            'mkdir -p "$archive_dir"',
            $this->vendorArchiveCommandForApp('apps/gateway'),
            $this->vendorArchiveCommandForApp('apps/cli'),
            'printf \'%s\' "$fingerprint" > "$fingerprint_file"',
            'chmod a+rx "$archive_dir"',
            'find "$archive_dir" -type f -exec chmod a+r {} +',
        ]);
    }

    private function vendorArchiveFingerprintCommand(): string
    {
        return implode("\n", [
            'for path in apps/gateway/composer.json apps/gateway/composer.lock apps/cli/composer.json apps/cli/composer.lock; do',
            '  if [ -f "$path" ]; then sha256sum "$path" >> "$fingerprint_input"; else printf \'missing  %s\n\' "$path" >> "$fingerprint_input"; fi',
            'done',
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

    /**
     * @return array{command: string, input: string}
     */
    private function vendorArchiveSshCommand(
        string $host,
        string $targetPath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): array {
        return [
            'command' => sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg($host),
                escapeshellarg('bash -s'),
            ),
            'input' => $mutationFence->guardedScript(
                "set -euo pipefail\n".$this->vendorArchiveCommand($targetPath),
            ),
        ];
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
        return (
            'if command -v git >/dev/null 2>&1; then git config --global --add safe.directory '
            ."'*'"
            .' >/dev/null 2>&1 || true; fi'
        );
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

    private function sourceMutationSshCommand(
        string $host,
        SourceMountedCheckoutMutationFence $mutationFence,
        string $command,
    ): string {
        return $this->sshCommand($host, $mutationFence->guardedScript($command));
    }

    /**
     * @return array{command: string, input: ?string}
     */
    private function dependencyHydrationSshCommand(
        string $host,
        string $targetPath,
        SourceMountedCheckoutMutationFence $mutationFence,
    ): array {
        $command = $this->dependencyHydrationCommand($targetPath, $mutationFence);
        $input = E2EGitHubAuth::shellInputScript($command);

        if ($input === null) {
            return [
                'command' => $this->sourceMutationSshCommand($host, $mutationFence, $command),
                'input' => null,
            ];
        }

        return [
            'command' => sprintf(
                'ssh -o BatchMode=yes -o ConnectTimeout=10 %s %s',
                escapeshellarg($host),
                escapeshellarg('bash -s'),
            ),
            'input' => $mutationFence->guardedScript($input),
        ];
    }

    private function isLocalHost(string $host): bool
    {
        return (
            in_array(strtolower($host), ['local', '', 'localhost', '127.0.0.1', '::1'], true)
            || strtolower($host) === strtolower((string) gethostname())
        );
    }

    private function mustRun(
        string $command,
        string $message,
        ?int $timeoutSeconds = null,
        ?string $input = null,
    ): ProcessResult {
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
