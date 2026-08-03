<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

afterEach(function (): void {
    E2ECurrentCheckout::flushCache();
});

it('hydrates reused vendor dependencies inside the current checkout instead of symlinking to the base checkout', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'reusePreparedVendorWithLocalAutoloadCommand');

    $command = $method->invoke(
        null,
        'apps/gateway',
        '/home/orbit/orbit-current-base-1234567890',
        "else echo 'missing vendor'; exit 127",
    );

    expect($command)
        ->toContain('cp -al "$path" "$target"/')
        ->toContain('cp -a --reflink=always "$path" "$target"/')
        ->not->toContain('ln -s');
});

it('uses the archive checkout path for prepared docker host-launcher instances', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'sourceMountedCheckoutPath');
    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-gateway');

    expect($method->invoke(null, $instance, 'orbit', true))->toBeNull();
});

it('keeps explicit source-mounted docker checkout paths', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'sourceMountedCheckoutPath');
    $instance = new DockerInstance(
        new DockerHost(E2EConfig::fromEnvironment()),
        'orbit-e2e-gateway',
        null,
        '/home/orbit/orbit',
    );

    expect($method->invoke(null, $instance, 'orbit', true))->toBe('/home/orbit/orbit');
});

it('prepares owner-local launchers for source-mounted retained topology checkouts', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'ownerLocalLauncherActivationCommand');

    $command = $method->invoke(null, '/home/orbit/orbit-run', 'orbit');

    // Final runtime checkout: real home-FS wrapper exec'ing apps/cli/orbit (not a
    // virtiofs/source symlink, and not a path that checkout.overlay may swap away).
    expect($command)
        ->toContain('/home/orbit/.local/bin')
        ->toContain('/home/orbit/.local/bin/orbit')
        ->toContain('/home/orbit/.config/orbit/install.json')
        ->toContain('/home/orbit/orbit-run/apps/cli/orbit')
        ->toMatch("/exec .*\/home\/orbit\/orbit-run\/apps\/cli\/orbit/")
        ->toContain('sudo rm -f ')
        ->toContain('printf %s ')
        ->toContain('test -f ')
        ->toContain('test ! -L ')
        ->toContain('test -x ')
        ->not->toContain('ln -sfn ')
        ->not->toContain('/home/orbit/orbit-run/bin/orbit')->toContain(
            '{"bin_path":"\/home\/orbit\/.local\/bin\/orbit"}',
        );
});

it('re-ensures agent runtime against the final post-overlay runtime checkout', function (): void {
    $command = E2ECurrentCheckout::agentRuntimeReadinessCommand('/home/orbit/orbit-run');

    expect($command)
        ->toContain("test -f '/home/orbit/.local/bin/orbit'")
        ->toContain("test ! -L '/home/orbit/.local/bin/orbit'")
        ->toContain("test -x '/home/orbit/.local/bin/orbit'")
        ->toContain("test -x '/home/orbit/orbit-run/apps/cli/orbit'")
        ->toContain("cd '/home/orbit/orbit-run/apps/cli'")
        ->toContain('LocalAgentUserEnsure')
        ->toContain('LocalAgentAclEnsure')
        ->toContain('LocalAgentRuntimeProbe')
        ->not->toContain('/home/orbit/orbit/apps/cli')
        ->not->toContain('/home/orbit/orbit/bin/orbit');
});

it('runs agent runtime readiness only after checkout roles reach their final paths', function (): void {
    $calls = [];
    E2ECurrentCheckout::useInstallerForTests(function (string $role) use (&$calls): string {
        $calls[] = "install:{$role}";

        return match ($role) {
            'agent' => '/home/orbit/orbit-run',
            default => "/home/orbit/orbit-run-{$role}",
        };
    });

    $agent = new class implements \App\E2E\Support\E2EInstance {
        /** @var list<string> */
        public array $commands = [];

        public function name(): string
        {
            return 'clone-agent';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): \Illuminate\Contracts\Process\ProcessResult
        {
            $this->commands[] = $command;

            return Process::result();
        }

        public function ssh(
            string $user,
            \App\E2E\Support\SshKeyPair $keyPair,
            string $command,
            ?int $timeoutSeconds = null,
        ): \Illuminate\Contracts\Process\ProcessResult {
            throw new RuntimeException('ssh not expected');
        }

        public function authorizeSsh(string $user, \App\E2E\Support\SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '127.0.0.1';
        }

        public function waitForSsh(string $user, \App\E2E\Support\SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    $operator = new class implements \App\E2E\Support\E2EInstance {
        public function name(): string
        {
            return 'clone-operator';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): \Illuminate\Contracts\Process\ProcessResult
        {
            throw new RuntimeException('operator exec not expected');
        }

        public function ssh(
            string $user,
            \App\E2E\Support\SshKeyPair $keyPair,
            string $command,
            ?int $timeoutSeconds = null,
        ): \Illuminate\Contracts\Process\ProcessResult {
            throw new RuntimeException('ssh not expected');
        }

        public function authorizeSsh(string $user, \App\E2E\Support\SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '127.0.0.1';
        }

        public function waitForSsh(string $user, \App\E2E\Support\SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    $topology = new \App\E2E\Support\E2ETopologyLease(
        kind: \App\E2E\Support\E2ETopologyKind::OperatorGatewayAgent,
        operator: $operator,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: new \App\E2E\Support\SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        rebuild: fn (): array => ['instances' => [], 'snapshotReset' => null],
        agent: $agent,
    );

    try {
        $paths = E2ECurrentCheckout::installOnTopology($topology, roles: ['agent']);

        expect($paths)
            ->toBe(['agent' => '/home/orbit/orbit-run'])
            ->and($calls)
            ->toBe(['install:agent'])
            ->and($agent->commands)
            ->toHaveCount(1)
            ->and($agent->commands[0])
            ->toContain("cd '/home/orbit/orbit-run/apps/cli'")
            ->toContain('LocalAgentAclEnsure')
            ->toContain("test -f '/home/orbit/.local/bin/orbit'")
            ->toContain("test -x '/home/orbit/orbit-run/apps/cli/orbit'");
    } finally {
        E2ECurrentCheckout::useInstallerForTests(null);
    }
});

it('passes Docker gateway state environment through the current checkout wrapper', function (): void {
    $script = E2ECurrentCheckout::orbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: true);

    expect($script)
        ->toContain("--env 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit'")
        ->toContain("--env 'DB_CONNECTION=sqlite'")
        ->toContain("--env 'DB_DATABASE=/home/orbit/.config/orbit/gateway.sqlite'")
        ->toContain("--env 'SESSION_DRIVER=file'");
});

it('writes direct CLI gateway config from a supplied public root CA without HTTP CA fetch', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'localGatewaySettingsCommand');
    $rootCaPem = "-----BEGIN CERTIFICATE-----\nTESTROOTCA\n-----END CERTIFICATE-----\n";
    $caTrust = new \App\E2E\Support\E2ECurrentCheckoutGatewayCaTrust(rootCaPem: $rootCaPem);

    $command = $method->invoke(null, '/home/orbit/orbit-current', '10.6.0.2', true, $caTrust);

    expect($command)
        ->toContain('TESTROOTCA')
        ->toContain('-----BEGIN CERTIFICATE-----')
        ->toContain('config.json')
        ->toContain('wireguard_https')
        ->toContain('exit(1)')
        ->not->toContain('/api/ca/root')
        ->not->toContain('file_get_contents("http://')
        ->not->toContain('gateway:add');
});

it('writes gateway-local CLI trust from on-node public root.crt without HTTP CA fetch', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'cliGatewayConfigCommand');
    $caTrust = \App\E2E\Support\E2ECurrentCheckoutGatewayCaTrust::localGatewayRootCertificate();

    $command = $method->invoke(null, '10.6.0.2', $caTrust);

    expect($command)
        ->toContain('/.config/orbit/ca/root.crt')
        ->toContain('config.json')
        ->toContain('wireguard_https')
        ->not->toContain('/api/ca/root')
        ->not->toContain('file_get_contents("http://');
});

it('rejects private key material when building CLI gateway config from a supplied CA', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'cliGatewayConfigCommand');
    $caTrust = new \App\E2E\Support\E2ECurrentCheckoutGatewayCaTrust(
        rootCaPem: "-----BEGIN CERTIFICATE-----\nCERT\n-----END CERTIFICATE-----\n-----BEGIN PRIVATE KEY-----\nSECRET\n-----END PRIVATE KEY-----\n",
    );

    expect(fn () => $method->invoke(null, '10.6.0.2', $caTrust))
        ->toThrow(RuntimeException::class, 'private key');
});

it('prunes stale checkout archive cache tarballs and locks', function (): void {
    $cacheDirectory = sys_get_temp_dir().'/orbit-checkout-cache-'.bin2hex(random_bytes(6));

    File::ensureDirectoryExists($cacheDirectory);

    try {
        $currentArchive = "{$cacheDirectory}/current-tree.tar.gz";
        $currentLock = "{$currentArchive}.lock";
        $staleArchive = "{$cacheDirectory}/stale-tree.tar.gz";
        $staleLock = "{$staleArchive}.lock";

        File::put($currentArchive, 'current archive');
        File::put($currentLock, 'current lock');
        File::put($staleArchive, 'stale archive');
        File::put($staleLock, 'stale lock');
        touch($staleArchive, time() - 90000);
        touch($staleLock, time() - 90000);

        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'pruneCheckoutArchiveCache');
        $method->invoke(null, $cacheDirectory, $currentArchive, $currentLock);

        expect($currentArchive)
            ->toBeFile()
            ->and($currentLock)
            ->toBeFile()
            ->and($staleArchive)
            ->not->toBeFile()->and($staleLock)
            ->not->toBeFile();
    } finally {
        File::deleteDirectory($cacheDirectory);
    }
});

it('ignores archive manifest paths that disappear before hashing', function (): void {
    $stablePath = repo_path('tmp-e2e-tree-hash-stable-'.bin2hex(random_bytes(4)).'.txt');
    $deletedPath = repo_path('tmp-e2e-tree-hash-deleted-'.bin2hex(random_bytes(4)).'.txt');
    $stableRelativePath = basename($stablePath);
    $deletedRelativePath = basename($deletedPath);

    File::put($stablePath, 'stable');
    File::put($deletedPath, 'deleted');
    File::delete($deletedPath);

    try {
        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'treeHashForManifest');

        expect($method->invoke(null, [$stableRelativePath, $deletedRelativePath]))
            ->toBe($method->invoke(null, [$stableRelativePath]));
    } finally {
        File::delete($stablePath);
        File::delete($deletedPath);
    }
});

it('prunes stale checkout archive artifacts while building temporary archives', function (): void {
    $previousCacheDirectory = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDirectory = sys_get_temp_dir().'/orbit-checkout-cache-'.bin2hex(random_bytes(6));
    $staleTemporaryArchive = sys_get_temp_dir().'/orbit-current-stale-prune-'.bin2hex(random_bytes(6)).'.tar.gz';
    $builtArchive = null;

    File::ensureDirectoryExists($cacheDirectory);

    try {
        putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDirectory}");

        $staleArchive = "{$cacheDirectory}/stale-tree.tar.gz";
        $staleLock = "{$staleArchive}.lock";

        File::put($staleArchive, 'stale archive');
        File::put($staleLock, 'stale lock');
        File::put($staleTemporaryArchive, 'stale temporary archive');
        touch($staleArchive, time() - 90000);
        touch($staleLock, time() - 90000);
        touch($staleTemporaryArchive, time() - 90000);

        Process::fake(function ($process) {
            $command = (string) $process->command;

            if (
                str_starts_with($command, 'COPYFILE_DISABLE=1 tar ')
                && preg_match("/ -czf '([^']+)' /", $command, $matches) === 1
            ) {
                File::put($matches[1], 'archive');
            }

            return Process::result();
        });

        $builtArchive = E2ECurrentCheckout::buildArchive();

        expect($builtArchive)
            ->toBeFile()
            ->and($staleArchive)
            ->not->toBeFile()->and($staleLock)
            ->not->toBeFile()->and($staleTemporaryArchive)
            ->not->toBeFile();
    } finally {
        if (is_string($builtArchive)) {
            File::delete($builtArchive);
        }

        $previousCacheDirectory === false
            ? putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR')
            : putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDirectory}");

        File::deleteDirectory($cacheDirectory);
        File::delete($staleTemporaryArchive);
    }
});

it('excludes generated versioned Orbit binaries from checkout archives', function (): void {
    $binary = repo_path('bin/orbit-binary-test');

    File::put($binary, 'generated binary');

    try {
        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'shouldIncludeArchivePath');

        expect($method->invoke(null, 'bin/orbit-binary-test'))->toBeFalse();
    } finally {
        File::delete($binary);
    }
});

it('excludes transient repo-root archive manifest fixtures from checkout archives', function (): void {
    $manifest = repo_path('tmp-e2e-archive-manifest-'.bin2hex(random_bytes(4)).'.txt');

    File::put($manifest, 'transient manifest fixture');

    try {
        $method = new ReflectionMethod(E2ECurrentCheckout::class, 'shouldIncludeArchivePath');

        expect($method->invoke(null, basename($manifest)))->toBeFalse();
    } finally {
        File::delete($manifest);
    }
});

it('installs gateway checkout before other roles when roles run concurrently', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'installTopologyRolesConcurrently');
    $source = file_get_contents((string) new ReflectionClass(E2ECurrentCheckout::class)->getFileName());

    expect($source)
        ->toContain("in_array('gateway', \$roles, true)")
        ->toContain('Install gateway first')
        ->toContain('readTopologyGatewayRootCaPem')
        ->and($method->getNumberOfParameters())
        ->toBeGreaterThanOrEqual(3);
});
