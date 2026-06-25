<?php

declare(strict_types=1);

use App\E2E\Support\SourceMountedCheckoutSyncer;
use Illuminate\Support\Facades\Process;

it('syncs the initiating worktree to a generated remote path without dependency directories', function (): void {
    $previousTestToken = getenv('TEST_TOKEN');
    putenv('TEST_TOKEN');

    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $command = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], 'is_string'));
        $commands[] = $command;

        if (str_contains($command, 'rsync -az --delete')) {
            return Process::result(">f+++++++++ apps/gateway/app/Example.php\n");
        }

        return Process::result();
    });

    try {
        $path = new SourceMountedCheckoutSyncer()->sync('beast', 'docker');
        $commandsOutput = implode("\n", $commands);
        $ownershipRepairOffset = strpos($commandsOutput, 'ORBIT_E2E_SOURCE_SYNC_UID="$(id -u)"');
        $rsyncOffset = strpos($commandsOutput, 'rsync -az --delete');
        $cleanupOffset = strpos($commandsOutput, 'rm -f');
        $hydrationOffset = strpos($commandsOutput, 'if command -v composer >/dev/null 2>&1; then');
        $vendorArchiveOffset = strpos($commandsOutput, 'archive_dir=');
        $permissionOffset = strpos($commandsOutput, 'find . -type d -exec chmod a+rx {} +');
        $worktreeSlug = trim(
            strtolower((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', basename(repo_path()))),
            '-._',
        );
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
            ->toContain("--exclude '/tmp-e2e-tree-hash-*'")
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
            ->toContain('.orbit-e2e-source-sync.lock')
            ->and($ownershipRepairOffset)
            ->not->toBeFalse()->and($cleanupOffset)
            ->not->toBeFalse()->and($rsyncOffset)
            ->not->toBeFalse()->and($hydrationOffset)
            ->not->toBeFalse()->and($vendorArchiveOffset)
            ->not->toBeFalse()->and($permissionOffset)
            ->not->toBeFalse()->and($ownershipRepairOffset)->toBeLessThan($rsyncOffset)->and(
                $rsyncOffset,
            )->toBeLessThan($cleanupOffset)->and($cleanupOffset)->toBeLessThan($hydrationOffset)->and(
                $hydrationOffset,
            )->toBeLessThan($vendorArchiveOffset)->and($vendorArchiveOffset)->toBeLessThan($permissionOffset);
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

        return Process::result();
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
        expect(new SourceMountedCheckoutSyncer()->sync('beast', 'docker'))
            ->toBe('/srv/beast-orbit-source');
    });
});

it('uses the local worktree directly for local source mounts', function (): void {
    Process::fake(fn () => Process::result());

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

        return Process::result();
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

it('bounds source sync lock waits and records the remote lock owner', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    $commandsOutput = implode("\n", $commands);

    expect($commandsOutput)
        ->toContain('max_wait=30')
        ->toContain('legacy_stale_after=60')
        ->toContain('owner_pid="$(cat "$lock/pid"')
        ->toContain('kill -0 "$owner_pid"')
        ->toContain('lock_age="$((now - created_at))"')
        ->toContain('! kill -0 "$owner_pid" 2>/dev/null && [ "$lock_age" -gt "$legacy_stale_after" ]')
        ->toContain('"$$" > "$lock/pid"')
        ->toContain('hostname > "$lock/host"')
        ->toContain('Timed out waiting ${max_wait}s for source sync lock $lock')
        ->not->toContain('-ge 900')
        ->not->toContain('-gt 1800');
});

it('itemizes rsync changes so unchanged syncs can skip maintenance work', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    expect(implode("\n", $commands))->toContain('rsync -az --delete --itemize-changes');
});

it('skips permission normalization when rsync reports no changes', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], 'is_string'));

        return Process::result();
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    expect(implode("\n", $commands))
        ->not->toContain('find . -type d -exec chmod a+rx {} +')
        ->not->toContain('find . -type f -exec chmod a+r {} +');
});

it('normalizes permissions when rsync reports changed files', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $command = implode("\n", array_filter([
            (string) $process->command,
            is_string($process->input) ? $process->input : null,
        ], 'is_string'));
        $commands[] = $command;

        if (str_contains($command, 'rsync -az --delete')) {
            return Process::result(">f+++++++++ apps/gateway/app/Example.php\n");
        }

        return Process::result();
    });

    new SourceMountedCheckoutSyncer()->sync('beast', 'incus');

    expect(implode("\n", $commands))
        ->toContain('find . -type d -exec chmod a+rx {} +')
        ->toContain('find . -type f -exec chmod a+r {} +');
});
