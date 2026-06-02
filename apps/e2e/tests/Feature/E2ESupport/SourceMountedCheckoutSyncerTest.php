<?php

declare(strict_types=1);

use App\E2E\Support\SourceMountedCheckoutSyncer;
use Illuminate\Support\Facades\Process;

it('syncs the initiating worktree to a generated remote path without dependency directories', function (): void {
    $previousTestToken = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    try {
        $path = (new SourceMountedCheckoutSyncer)->sync('beast', 'docker');
        $commandsOutput = implode("\n", $commands);
        $ownershipRepairOffset = strpos($commandsOutput, 'ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"');
        $rsyncOffset = strpos($commandsOutput, 'rsync -az --delete');
        $cleanupOffset = strpos($commandsOutput, 'rm -f');
        $hydrationOffset = strpos($commandsOutput, 'if command -v composer >/dev/null 2>&1; then');
        $worktreeSlug = trim(strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(repo_path()))), '-._');
        $expectedPathPrefix = '/tmp/orbit-e2e-sources/'.($worktreeSlug !== '' ? $worktreeSlug : 'orbit').'-docker-';

        expect($path)
            ->toStartWith($expectedPathPrefix)
            ->and($commandsOutput)
            ->toContain('ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"')
            ->toContain('chown -R "${ORBIT_E2E_SOURCE_SYNC_UID}:${ORBIT_E2E_SOURCE_SYNC_GID}" /work')
            ->toContain('rsync -az --delete')
            ->toContain(escapeshellarg(repo_path().'/'))
            ->toContain("'beast:{$path}/'")
            ->toContain("--exclude '/apps/gateway/vendor'")
            ->toContain("--exclude '/apps/cli/.env'")
            ->toContain("--exclude '/apps/cli/vendor'")
            ->toContain("--exclude '/apps/docs/vendor'")
            ->toContain("--exclude '/node_modules'")
            ->toContain('rm -f')
            ->toContain('./.env')
            ->toContain('./apps/cli/.env')
            ->toContain('find ./apps/gateway/database -maxdepth 1 -type f')
            ->toContain('*.sqlite')
            ->toContain('*.sqlite-*')
            ->toContain('rm -rf')
            ->toContain('./node_modules')
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
            ->toContain('find . -type d -exec chmod a+rx {} +')
            ->toContain('find . -type f -exec chmod a+r {} +')
            ->toContain('find . -type f -perm -u+x -exec chmod a+rx {} +')
            ->toContain('chmod -R a+rwX "$path"')
            ->toContain('apps/gateway/storage')
            ->toContain('apps/gateway/bootstrap/cache')
            ->toContain('.orbit-e2e-source-sync.lock')
            ->and($ownershipRepairOffset)->not->toBeFalse()
            ->and($cleanupOffset)->not->toBeFalse()
            ->and($rsyncOffset)->not->toBeFalse()
            ->and($hydrationOffset)->not->toBeFalse()
            ->and($ownershipRepairOffset)->toBeLessThan($rsyncOffset)
            ->and($rsyncOffset)->toBeLessThan($cleanupOffset)
            ->and($cleanupOffset)->toBeLessThan($hydrationOffset);
    } finally {
        if (is_string($previousTestToken)) {
            putenv("TEST_TOKEN={$previousTestToken}");
        } else {
            putenv('TEST_TOKEN');
        }
    }
});

it('isolates generated remote source paths by provider and parallel worker', function (): void {
    $previousTestToken = getenv('TEST_TOKEN');

    Process::fake(fn () => Process::result());

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

it('uses explicit provider source paths as the sync target', function (): void {
    Process::fake(fn () => Process::result());

    withE2EConfigEnvironment([
        'ORBIT_E2E_DOCKER_SOURCE_PATH' => '/srv/global-orbit-source',
        'ORBIT_E2E_DOCKER_SOURCE_PATH_BEAST' => '/srv/beast-orbit-source',
    ], function (): void {
        expect((new SourceMountedCheckoutSyncer)->sync('beast', 'docker'))
            ->toBe('/srv/beast-orbit-source');
    });
});

it('uses the local worktree directly for local source mounts', function (): void {
    Process::fake(fn () => Process::result());

    expect((new SourceMountedCheckoutSyncer)->sync('local', 'docker'))->toBe(repo_path());

    Process::assertNothingRan();
});
