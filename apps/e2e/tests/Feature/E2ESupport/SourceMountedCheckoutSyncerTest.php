<?php

declare(strict_types=1);

use App\E2E\Support\SourceMountedCheckoutMutationFence;
use App\E2E\Support\SourceMountedCheckoutSyncer;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

function source_mounted_sync_process_result(PendingProcess $process, string $output = ''): ProcessResult
{
    if (str_contains((string) $process->command, 'flock -w 30 9')) {
        return Process::result(implode("\n", [
            '__ORBIT_SOURCE_SYNC_LOCK_READY__',
            '__ORBIT_SOURCE_SYNC_LOCK_RELEASED__',
        ]));
    }

    return Process::result($output);
}

it('syncs the initiating worktree to a generated remote path without dependency directories', function (): void {
    $previousTestToken = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $command = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], callback: 'is_string'));
        $commands[] = $command;

        if (str_contains($command, 'rsync -az --delete')) {
            return source_mounted_sync_process_result(
                process: $process,
                output: ">f+++++++++ apps/gateway/app/Example.php\n",
            );
        }

        return source_mounted_sync_process_result($process);
    });

    try {
        $path = new SourceMountedCheckoutSyncer()->sync('beast', 'docker');
        $commandsOutput = implode("\n", $commands);
        $ownershipRepairOffset = strpos($commandsOutput, 'ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"');
        $guardInstallationOffset = strpos(haystack: $commandsOutput, needle: 'expected_guard_hash=');
        $rsyncOffset = strpos($commandsOutput, 'rsync -az --delete');
        $cleanupOffset = is_int($rsyncOffset) ? strpos($commandsOutput, 'rm -f', $rsyncOffset) : false;
        $hydrationOffset = strpos($commandsOutput, 'if command -v composer >/dev/null 2>&1; then');
        $vendorArchiveOffset = strpos($commandsOutput, 'archive_dir=');
        $permissionOffset = strpos($commandsOutput, 'find . -type d -exec chmod a+rx {} +');
        $worktreeSlug = trim(
            strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(repo_path()))),
            '-._',
        );
        $expectedPathPrefix = '/tmp/orbit-e2e-sources/'.($worktreeSlug !== '' ? $worktreeSlug : 'orbit').'-docker-';

        if (
            ! is_int($ownershipRepairOffset)
            || ! is_int($guardInstallationOffset)
            || ! is_int($rsyncOffset)
            || ! is_int($cleanupOffset)
            || ! is_int($hydrationOffset)
            || ! is_int($vendorArchiveOffset)
            || ! is_int($permissionOffset)
        ) {
            throw new LogicException('The source sync commands did not contain every expected lifecycle phase.');
        }

        expect($path)
            ->toStartWith($expectedPathPrefix)
            ->and($commandsOutput)
            ->toContain('ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"')
            ->toContain('chown -R "${ORBIT_E2E_SOURCE_SYNC_UID}:${ORBIT_E2E_SOURCE_SYNC_GID}" /work')
            ->toContain('/tmp/orbit-e2e-source-locks/helpers/rsync-guard-')
            ->toContain('expected_guard_hash=')
            ->toContain('rsync -az --delete')
            ->toContain(escapeshellarg(repo_path().'/'))
            ->toContain("'beast:{$path}/'")
            ->toContain("--exclude '/apps/gateway/vendor'")
            ->toContain("--exclude '/apps/cli/.env'")
            ->toContain("--exclude '/apps/cli/vendor'")
            ->toContain("--exclude '/apps/docs/vendor'")
            ->toContain("--exclude '/apps/agent/target'")
            ->toContain("--exclude '/apps/macos/target'")
            ->toContain("--exclude '/node_modules'")
            ->toContain("--exclude '/tmp-e2e-tree-hash-*'")
            ->toContain('rm -f')
            ->toContain('./.env')
            ->toContain('./apps/cli/.env')
            ->toContain('find ./apps/gateway/database -maxdepth 1 -type f')
            ->toContain('*.sqlite')
            ->toContain('*.sqlite-*')
            ->toContain('rm -rf')
            ->toContain('./node_modules')
            ->toContain('./apps/agent/target')
            ->toContain('./apps/macos/target')
            ->toContain('apps/gateway/storage/logs')
            ->toContain('find "$path" -mindepth 1 -maxdepth 1 -exec rm -rf {} +')
            ->toContain('if command -v composer >/dev/null 2>&1; then')
            ->toContain('composer --working-dir=')
            ->toContain('docker image inspect')
            ->toContain('composer:2')
            ->toContain('docker pull')
            ->toContain('uid="$(id -u)"')
            ->toContain('gid="$(id -g)"')
            ->toContain('ORBIT_E2E_HOST_UID=${uid}')
            ->toContain('chown -R "${ORBIT_E2E_HOST_UID}:${ORBIT_E2E_HOST_GID}" /work')
            ->toContain('docker run --rm --user "${uid}:${gid}" --mount')
            ->toContain("type=bind,src={$path},dst=/work")
            ->toContain('--workdir /work')
            ->toContain('COMPOSER_ALLOW_SUPERUSER=1')
            ->toContain('COMPOSER_HOME=/tmp/orbit-composer-home')
            ->toContain('apps/gateway')
            ->toContain('apps/cli')
            ->toContain('install --no-interaction --prefer-dist --optimize-autoloader --no-progress --no-cache')
            ->toContain(SourceMountedCheckoutSyncer::VendorArchiveDirectory)
            ->toContain(SourceMountedCheckoutSyncer::VendorArchiveFingerprintFile)
            ->toContain(SourceMountedCheckoutSyncer::vendorArchiveRelativePath('apps/gateway'))
            ->toContain(SourceMountedCheckoutSyncer::vendorArchiveRelativePath('apps/cli'))
            ->toContain('apps/gateway/composer.json')
            ->toContain('apps/gateway/composer.lock')
            ->toContain('apps/cli/composer.json')
            ->toContain('apps/cli/composer.lock')
            ->toContain('fingerprint_input="$(mktemp)"')
            ->toContain('sha256sum "$path" >> "$fingerprint_input"')
            ->toContain('fingerprint="$(sha256sum "$fingerprint_input" | cut -d " " -f 1)"')
            ->toContain('apps-gateway-vendor.tar')
            ->toContain('apps-cli-vendor.tar')
            ->toContain('tar --warning=no-unknown-keyword -C')
            ->toContain('-vendor.tar')
            ->toContain('"$fingerprint" > "$fingerprint_file"')
            ->toContain('find "$archive_dir" -type f -exec chmod a+r {} +')
            ->toContain('find . -type d -exec chmod a+rx {} +')
            ->toContain('find . -type f -exec chmod a+r {} +')
            ->toContain('find . -type f -perm -u+x -exec chmod a+rx {} +')
            ->toContain('chmod -R a+rwX "$path"')
            ->toContain('apps/gateway/storage')
            ->toContain('apps/gateway/bootstrap/cache')
            ->toContain('.orbit-e2e-source-sync.lock');

        expect($ownershipRepairOffset)->toBeLessThan($guardInstallationOffset);
        expect($guardInstallationOffset)->toBeLessThan($rsyncOffset);
        expect($rsyncOffset)->toBeLessThan($cleanupOffset);
        expect($cleanupOffset)->toBeLessThan($hydrationOffset);
        expect($hydrationOffset)->toBeLessThan($vendorArchiveOffset);
        expect($vendorArchiveOffset)->toBeLessThan($permissionOffset);
    } finally {
        if (is_string($previousTestToken)) {
            putenv("TEST_TOKEN={$previousTestToken}");
        } else {
            putenv('TEST_TOKEN');
        }
    }
});

it('passes GitHub auth to remote dependency hydration through SSH input', function (): void {
    $commands = [];
    $inputs = [];

    Process::fake(function ($process) use (&$commands, &$inputs) {
        $commands[] = (string) $process->command;
        $inputs[] = is_string($process->input) ? $process->input : null;

        return source_mounted_sync_process_result($process);
    });

    withE2EConfigEnvironment([
        'GH_TOKEN' => 'ghp_source_sync_secret',
    ], function (): void {
        new SourceMountedCheckoutSyncer()->sync('sidecar1', 'docker');
    });

    $commandsOutput = implode("\n", $commands);
    $inputsOutput = implode("\n", array_filter($inputs, 'is_string'));

    expect($commandsOutput)
        ->toContain("ssh -o BatchMode=yes -o ConnectTimeout=10 'sidecar1' 'bash -s'")
        ->not
        ->toContain('ghp_source_sync_secret')
        ->and($inputsOutput)
        ->toContain("export GH_TOKEN='ghp_source_sync_secret'")
        ->toContain("export GITHUB_TOKEN='ghp_source_sync_secret'")
        ->toContain('composer config --global github-oauth.github.com "${GH_TOKEN:-${GITHUB_TOKEN:-}}"')
        ->toMatch('/--env .*GH_TOKEN/')
        ->toMatch('/--env .*GITHUB_TOKEN/')
        ->toContain('/tmp/orbit-e2e-composer-home')
        ->toContain('/tmp/orbit-e2e-composer-cache')
        ->toContain('/tmp/orbit-composer-home')
        ->toContain('/tmp/orbit-composer-cache');
});

it('isolates generated remote source paths by provider and parallel worker', function (): void {
    $previousTestToken = getenv('TEST_TOKEN');

    Process::fake(fn (PendingProcess $process) => source_mounted_sync_process_result($process));

    try {
        putenv('TEST_TOKEN=3');

        $syncer = new SourceMountedCheckoutSyncer;

        expect($syncer->sync('beast', 'docker'))
            ->toContain('-docker-worker-3-')
            ->and($syncer->sync('beast', 'incus'))
            ->toContain('-incus-worker-3-')
            ->not->toBe($syncer->sync('beast', 'docker'));
    } finally {
        if (is_string($previousTestToken)) {
            putenv("TEST_TOKEN={$previousTestToken}");
        } else {
            putenv('TEST_TOKEN');
        }
    }
});

it('isolates retained source paths by topology id', function (): void {
    $syncer = new SourceMountedCheckoutSyncer;
    $first = $syncer->sourcePath('beast', 'incus', 'dev-aaa111');
    $second = $syncer->sourcePath('beast', 'incus', 'dev-bbb222');

    expect($first)
        ->toEndWith('/retained/dev-aaa111')
        ->not
        ->toBe($second)
        ->and($second)
        ->toEndWith('/retained/dev-bbb222');
});

it('uses explicit provider source paths as the sync target', function (): void {
    Process::fake(fn (PendingProcess $process) => source_mounted_sync_process_result($process));

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/global-orbit-source',
        'ORBIT_E2E_DOCKER_SOURCE_PATH_BEAST' => '/srv/beast-orbit-source',
    ], function (): void {
        expect(new SourceMountedCheckoutSyncer()->sync('beast', 'docker'))
            ->toBe('/srv/beast-orbit-source');
    });
});

it('treats an explicit provider source path as the base for retained scopes', function (): void {
    withE2EConfigEnvironment([
        'ORBIT_E2E_INCUS_SOURCE_PATH_BEAST' => '/srv/beast-orbit-source',
    ], function (): void {
        expect(new SourceMountedCheckoutSyncer()->sourcePath('beast', 'incus', 'dev-abc123'))
            ->toBe('/srv/beast-orbit-source/retained/dev-abc123');
    });
});

it('rejects a recorded scoped path that differs from the current safe path', function (): void {
    Process::fake();

    expect(fn () => new SourceMountedCheckoutSyncer()->withSyncLock(
        host: 'beast',
        provider: 'incus',
        criticalSection: static fn (Closure $syncSource): string => $syncSource(),
        scope: 'dev-abc123',
        sourcePath: '/tmp/unrelated/retained/dev-abc123',
    ))
        ->toThrow(RuntimeException::class, 'does not match expected scoped path');

    Process::assertNothingRan();
});

it('allows the locked source sync operation to run only once', function (): void {
    Process::fake(fn (PendingProcess $process) => source_mounted_sync_process_result($process));

    expect(fn () => new SourceMountedCheckoutSyncer()->withSyncLock(
        host: 'beast',
        provider: 'incus',
        criticalSection: static function (Closure $syncSource): string {
            $syncSource();

            return $syncSource();
        },
        scope: 'dev-abc123',
    ))
        ->toThrow(LogicException::class, 'may only be invoked once');
});

it('uses the local worktree directly for local source mounts', function (): void {
    Process::fake(fn (PendingProcess $process) => source_mounted_sync_process_result($process));

    expect(new SourceMountedCheckoutSyncer()->sync('local', 'docker'))->toBe(repo_path());

    Process::assertNothingRan();
});

it('guards the ownership repair chown behind a foreign-owner probe', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], 'is_string'));

        return source_mounted_sync_process_result($process);
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    $commandsOutput = implode("\n", $commands);

    expect($commandsOutput)
        ->toContain('! -user "$(id -un)"')
        ->toContain('-print -quit')
        ->and(strpos($commandsOutput, '! -user "$(id -un)"'))
        ->toBeLessThan(strpos(
            $commandsOutput,
            'chown -R "${ORBIT_E2E_SOURCE_SYNC_UID}:${ORBIT_E2E_SOURCE_SYNC_GID}" /work',
        ));
});

it('holds a live remote flock for the complete source sync', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return source_mounted_sync_process_result($process);
    });

    $sourcePath = new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    $commandsOutput = implode("\n", $commands);
    $expectedLockPath =
        '/tmp/orbit-e2e-source-locks/'.hash('sha256', rtrim(string: $sourcePath, characters: '/')).'.lock';
    $expectedMutationLockPath =
        '/tmp/orbit-e2e-source-locks/'.hash('sha256', rtrim(string: $sourcePath, characters: '/')).'.mutation.lock';

    expect($commandsOutput)
        ->toContain('/tmp/orbit-e2e-source-locks')
        ->toContain($expectedLockPath)
        ->toContain($expectedMutationLockPath)
        ->toContain('exec 9>"$lock"')
        ->toContain('flock -w 30 9')
        ->toContain('Timed out waiting 30s for source sync lock $lock')
        ->toContain('legacy_lock="$target/.orbit-e2e-source-sync.lock"')
        ->toContain('while ! mkdir "$legacy_lock" 2>/dev/null')
        ->toContain('legacy_stale_after=86400')
        ->toContain('hostname > "$legacy_lock/host"')
        ->toContain('activate_generation()')
        ->toContain('invalidate_generation()')
        ->toContain('generation_file=')
        ->toContain('actual_generation=')
        ->toContain('Source lifecycle generation changed before mutation; refusing stale mutation.')
        ->toContain('--rsync-path')
        ->toContain('flock -w 1200 -x')
        ->toContain('__ORBIT_SOURCE_SYNC_LOCK_READY__')
        ->toContain('cat >/dev/null')
        ->toContain('__ORBIT_SOURCE_SYNC_LOCK_RELEASED__')
        ->not->toContain('lock='.escapeshellarg("{$sourcePath}/.orbit-e2e-source-sync.lock"))
        ->not->toContain('"$$" > "$lock/pid"')->toContain('kill -0 "$owner_pid"');
});

it('keeps the rsync remote guard transport-safe for legacy rsync clients', function (): void {
    $mutationFence = new SourceMountedCheckoutMutationFence(
        '/tmp/orbit-e2e-sources/example/retained/dev-example',
        '0123456789abcdef0123456789abcdef',
    );
    $rsyncGuard = $mutationFence->rsyncGuard();
    $remotePath = $rsyncGuard->remotePath();
    $installer = $rsyncGuard->installationScript();

    expect($remotePath)
        ->toMatch(
            '#\\A/tmp/orbit-e2e-source-locks/helpers/rsync-guard-[a-f0-9]{64} '
            .'/tmp/orbit-e2e-source-locks/[a-f0-9]{64}\\.mutation\\.lock '
            .'/tmp/orbit-e2e-source-locks/[a-f0-9]{64}\\.generation '
            .'[a-f0-9]{32}\\z#D',
        )
        ->not->toContain("\n")
        ->not->toContain("'")
        ->not->toContain('"')
        ->not->toContain('\\')
        ->not->toContain('$')
        ->not->toContain(';');

    expect(explode(' ', $remotePath))->toHaveCount(4);
    expect($installer)
        ->toContain('#!/usr/bin/env bash')
        ->toContain('flock -w 1200 -x 8')
        ->toContain('shift 3')
        ->toContain('exec flock -n -x 8 rsync "$@"')
        ->toContain('sha256sum "$guard_temp"')
        ->toContain('ln "$guard_temp" "$guard_path"')
        ->toContain('[ ! -x "$guard_path" ]');
});

it('passes the production guard through the local rsync remote-shell transport', function (): void {
    if (! is_executable('/usr/bin/rsync')) {
        test()->markTestSkipped('/usr/bin/rsync is required to exercise its remote-shell parser.');
    }

    $files = new Filesystem;
    $temporaryPath = sys_get_temp_dir().'/orbit-rsync-guard-'.bin2hex(random_bytes(8));
    $sourcePath = $temporaryPath.'/source';
    $destinationPath = $temporaryPath.'/destination';
    $fakeBinPath = $temporaryPath.'/bin';
    $generation = bin2hex(random_bytes(16));
    $mutationFence = new SourceMountedCheckoutMutationFence($destinationPath, $generation);

    $files->ensureDirectoryExists($sourcePath);
    $files->ensureDirectoryExists($destinationPath);
    $files->ensureDirectoryExists($fakeBinPath);
    $files->ensureDirectoryExists(
        path: SourceMountedCheckoutMutationFence::LOCK_DIRECTORY,
        mode: 0o700,
    );
    $files->put($sourcePath.'/transport-proof.txt', "legacy rsync transport proof\n");
    $files->put($mutationFence->generationFilePath(), $generation);
    $files->put($fakeBinPath.'/ssh', <<<'SHELL'
        #!/bin/sh
        set -eu
        shift
        exec /bin/sh -c "$*"
        SHELL);
    $files->put($fakeBinPath.'/flock', <<<'SHELL'
        #!/bin/sh
        set -eu
        while [ "$#" -gt 0 ]; do
            case "$1" in
                -w) shift 2 ;;
                -n|-x) shift ;;
                [0-9]*) shift; break ;;
                *) break ;;
            esac
        done
        if [ "$#" -eq 0 ]; then exit 0; fi
        exec "$@"
        SHELL);
    $files->put($fakeBinPath.'/sha256sum', <<<'SHELL'
        #!/bin/sh
        set -eu
        if [ -x /usr/bin/sha256sum ]; then exec /usr/bin/sha256sum "$@"; fi
        exec /usr/bin/shasum -a 256 "$@"
        SHELL);
    chmod(filename: $fakeBinPath.'/ssh', permissions: 0o700);
    chmod(filename: $fakeBinPath.'/flock', permissions: 0o700);
    chmod(filename: $fakeBinPath.'/sha256sum', permissions: 0o700);

    $environment = ['PATH' => $fakeBinPath.':'.(string) getenv('PATH')];

    try {
        $installer = new SymfonyProcess(
            command: ['/bin/bash', '-s'],
            env: $environment,
        );
        $rsyncGuard = $mutationFence->rsyncGuard();
        $installer->setInput($mutationFence->guardedScript($rsyncGuard->installationScript()));
        $installer->mustRun();

        $rsync = new SymfonyProcess(
            command: [
                '/usr/bin/rsync',
                '-az',
                '--rsync-path',
                $rsyncGuard->remotePath(),
                '-e',
                $fakeBinPath.'/ssh',
                $sourcePath.'/',
                'probe:'.$destinationPath.'/',
            ],
            env: $environment,
        );
        $rsync->mustRun();

        expect($files->get($destinationPath.'/transport-proof.txt'))
            ->toBe("legacy rsync transport proof\n");
    } finally {
        $files->delete($mutationFence->lockPath());
        $files->delete($mutationFence->generationFilePath());
        $files->deleteDirectory($temporaryPath);
    }
});

it('hands the mutation fence into daemon-owned source helper containers', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], callback: 'is_string'));

        return source_mounted_sync_process_result($process);
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    $commandsOutput = implode("\n", $commands);
    $lockMount =
        'type=bind,src='
        .SourceMountedCheckoutMutationFence::LOCK_DIRECTORY
        .',dst='
        .SourceMountedCheckoutMutationFence::LOCK_DIRECTORY;
    $dockerRunLines = array_values(array_filter(
        explode("\n", $commandsOutput),
        static fn (string $line): bool => str_contains($line, 'docker run --rm'),
    ));
    $helperPreflightLines = array_values(array_filter(
        $dockerRunLines,
        static fn (string $line): bool => str_contains($line, 'command -v flock') && ! str_contains($line, 'dst=/work'),
    ));
    $sourceMutationLines = array_values(array_filter(
        $dockerRunLines,
        static fn (string $line): bool => str_contains($line, 'dst=/work'),
    ));

    expect($helperPreflightLines)
        ->toHaveCount(2)
        ->and($sourceMutationLines)
        ->toHaveCount(3)
        ->and(preg_match_all('/flock -u 8\s+exec 8>&-/', $commandsOutput))
        ->toBe(2);

    foreach ($helperPreflightLines as $helperPreflightLine) {
        expect($helperPreflightLine)->toContain('timeout 2 flock');
    }

    foreach ($sourceMutationLines as $sourceMutationLine) {
        expect($sourceMutationLine)
            ->toContain($lockMount)
            ->toContain('sh -lc')
            ->toContain('mutation_lock=')
            ->toContain('timeout 1200 flock')
            ->not->toContain('flock -w');
    }
});

it('itemizes rsync changes so unchanged syncs can skip maintenance work', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return source_mounted_sync_process_result($process);
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    expect(implode("\n", $commands))->toContain('rsync -az --delete --itemize-changes');
});

it('normalizes staged source modes even when rsync reports no content changes', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], callback: 'is_string'));

        return source_mounted_sync_process_result($process);
    });

    $path = new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    expect($path)
        ->not->toBe(repo_path())
        ->and(implode("\n", $commands))
        ->toContain('find . -type d -exec chmod a+rx {} +')
        ->toContain('find . -type f -exec chmod a+r {} +');
});

it('normalizes permissions when rsync reports changed files', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $command = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], callback: 'is_string'));
        $commands[] = $command;

        if (str_contains($command, 'rsync -az --delete')) {
            return source_mounted_sync_process_result(
                process: $process,
                output: ">f+++++++++ apps/gateway/app/Example.php\n",
            );
        }

        return source_mounted_sync_process_result($process);
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    expect(implode("\n", $commands))
        ->toContain('find . -type d -exec chmod a+rx {} +')
        ->toContain('find . -type f -exec chmod a+r {} +');
});
