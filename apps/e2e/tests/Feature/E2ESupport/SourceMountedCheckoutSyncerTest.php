<?php

declare(strict_types=1);

use App\E2E\Support\SourceMountedCheckoutSyncer;
use Illuminate\Support\Facades\Process;

it('syncs the initiating worktree to a generated remote path without dependency directories', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $path = (new SourceMountedCheckoutSyncer)->sync('beast', 'docker');
    $commandsOutput = implode("\n", $commands);
    $ownershipRepairOffset = strpos($commandsOutput, 'ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"');
    $rsyncOffset = strpos($commandsOutput, 'rsync -az --delete');
    $cleanupOffset = strpos($commandsOutput, 'rm -f');
    $hydrationOffset = strpos($commandsOutput, 'if command -v composer >/dev/null 2>&1; then');

    expect($path)
        ->toStartWith('/tmp/orbit-e2e-sources/source-mounted-live-topologies-')
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
