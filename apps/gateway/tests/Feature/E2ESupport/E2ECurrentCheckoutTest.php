<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;
use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2EPhaseTimer;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    E2ECurrentCheckout::flushCache();
    E2ECurrentCheckout::useNowResolverForTests(null);
    m::close();
});

function currentCheckoutProcessResult(bool $successful = true): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

function currentCheckoutFakeInstance(array &$commands, string $name = 'fake-operator', ?array &$timeouts = null): E2EInstance
{
    return new class($commands, $name, $timeouts) implements E2EInstance
    {
        /**
         * @param  array<int, string>  $commands
         * @param  array<int, int|null>|null  $timeouts
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
            private ?array &$timeouts = null,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;
            $this->timeouts[] = $timeoutSeconds;

            return currentCheckoutProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;
            $this->timeouts[] = $timeoutSeconds;

            return currentCheckoutProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->commands[] = "copy {$sourcePath} {$targetPath}";
        }

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

it('reuses prepared vendor packages while rebuilding checkout local autoload files', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key);

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->toContain("cmp -s '/home/orbit/orbit/apps/gateway/composer.lock' apps/gateway/composer.lock")
        ->and($commandOutput)->toContain("[ -d '/home/orbit/orbit/apps/gateway/vendor/composer' ]")
        ->and($commandOutput)->toContain('rm -rf apps/gateway/vendor')
        ->and($commandOutput)->toContain("find '/home/orbit/orbit/apps/gateway/vendor' -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec ln -s {} apps/gateway/vendor/ \\;")
        ->and($commandOutput)->toContain("cp -a '/home/orbit/orbit/apps/gateway/vendor/composer' apps/gateway/vendor/composer")
        ->and($commandOutput)->toContain("cp '/home/orbit/orbit/apps/gateway/vendor/autoload.php' apps/gateway/vendor/autoload.php")
        ->and($commandOutput)->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->and($commandOutput)->toContain("cmp -s '/home/orbit/orbit/apps/cli/composer.lock' apps/cli/composer.lock")
        ->and($commandOutput)->toContain("[ -d '/home/orbit/orbit/apps/cli/vendor/composer' ]")
        ->and($commandOutput)->toContain('rm -rf apps/cli/vendor')
        ->and($commandOutput)->toContain("find '/home/orbit/orbit/apps/cli/vendor' -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec ln -s {} apps/cli/vendor/ \\;")
        ->and($commandOutput)->toContain("cp -a '/home/orbit/orbit/apps/cli/vendor/composer' apps/cli/vendor/composer")
        ->and($commandOutput)->toContain("cp '/home/orbit/orbit/apps/cli/vendor/autoload.php' apps/cli/vendor/autoload.php")
        ->and($commandOutput)->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->and($commandOutput)->not->toContain("ln -s '/home/orbit/orbit/vendor' vendor")
        ->and($commandOutput)->toContain('elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/gateway install --no-interaction --prefer-dist --optimize-autoloader')
        ->and($commandOutput)->toContain('Gateway Composer dependencies are not installed and prepared vendor dependencies could not be reused.')
        ->and($commandOutput)->toContain('elif command -v composer >/dev/null 2>&1; then composer --working-dir=apps/cli install --no-interaction --prefer-dist --optimize-autoloader')
        ->and($commandOutput)->toContain('CLI Composer dependencies are not installed and prepared vendor dependencies could not be reused.');
});

it('can seed the current checkout from prepared topology state', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env")
        ->and($commandOutput)->toContain("rm -f apps/gateway/database/database.sqlite apps/gateway/database/database.sqlite-* && cp '/home/orbit/orbit/apps/gateway/database/database.sqlite' apps/gateway/database/database.sqlite")
        ->and($commandOutput)->toContain("cp -a '/home/orbit/orbit/apps/gateway/storage/app' apps/gateway/storage/app");
});

it('records checkout phase timings while installing the current checkout', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    E2ECurrentCheckout::install($instance, 'orbit', $key, timer: $timer);

    expect(array_column($timer->events(), 'name'))->toBe([
        'checkout.archive',
        'checkout.copy',
        'checkout.extract',
        'checkout.vendor',
        'checkout.runtime-state',
        'checkout.migrate',
    ]);
});

it('does not record archive timing for shared archive cache hits', function (): void {
    $previousCache = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    $previousCacheDir = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDir = sys_get_temp_dir().'/orbit-checkout-archive-hit-test-'.bin2hex(random_bytes(4));
    $tarBuilds = 0;

    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');
    putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDir}");

    Process::fake(function ($process) use (&$tarBuilds) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            $tarBuilds++;

            if (preg_match("/ -czf '([^']+)' /", (string) $process->command, $matches) === 1) {
                file_put_contents($matches[1], 'archive');
            }
        }

        return Process::result();
    });

    $operatorCommands = [];
    $gatewayCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    try {
        E2ECurrentCheckout::install(currentCheckoutFakeInstance($operatorCommands, 'operator'), 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);
        E2ECurrentCheckout::install(currentCheckoutFakeInstance($gatewayCommands, 'gateway'), 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);

        expect($tarBuilds)->toBe(1)
            ->and(array_filter(array_column($timer->events(), 'name'), fn (string $name): bool => $name === 'checkout.archive'))->toHaveCount(1);
    } finally {
        E2ECurrentCheckout::flushCache();
        Process::run('rm -rf '.escapeshellarg($cacheDir));

        if ($previousCache === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previousCache}");
        }

        if ($previousCacheDir === false) {
            putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDir}");
        }
    }
});

it('makes the checkout archive readable before copying it into an instance', function (): void {
    Process::fake(function ($process) {
        if (preg_match("/ -czf '([^']+)' /", (string) $process->command, $matches) === 1) {
            file_put_contents($matches[1], 'archive');
            chmod($matches[1], 0600);
        }

        return Process::result();
    });

    $copiedMode = null;
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $instance = new class($copiedMode) implements E2EInstance
    {
        public function __construct(private ?string &$copiedMode) {}

        public function name(): string
        {
            return 'fake-operator';
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return currentCheckoutProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return currentCheckoutProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void
        {
            $this->copiedMode = decoct(fileperms($sourcePath) & 0777);
        }

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };

    E2ECurrentCheckout::install($instance, 'orbit', $key);

    expect($copiedMode)->toBe('644');
});

it('excludes persisted orbit certificate material from checkout archive manifests', function (): void {
    $certificatePath = repo_path('apps/gateway/storage/app/orbit/ca/e2e-test-certificate.pem');
    $manifestEntries = [];

    if (! is_dir(dirname($certificatePath))) {
        mkdir(dirname($certificatePath), 0777, true);
    }

    file_put_contents($certificatePath, 'secret');

    Process::fake(function ($process) use (&$manifestEntries) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            if (preg_match("/ -T '([^']+)'/", (string) $process->command, $matches) === 1) {
                $manifestEntries = array_values(array_filter(
                    explode("\0", (string) file_get_contents($matches[1])),
                    fn (string $path): bool => $path !== '',
                ));
            }
        }

        return Process::result();
    });

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key);

        expect($manifestEntries)->not->toContain('apps/gateway/storage/app/orbit/ca/e2e-test-certificate.pem');
    } finally {
        @unlink($certificatePath);
    }
});

it('builds checkout archives from tracked and unignored files only', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $includedPath = repo_path("tmp-e2e-archive-manifest-{$suffix}.txt");
    $ignoredPath = repo_path("tmp-e2e-archive-manifest-{$suffix}.log");
    $manifestEntries = [];

    file_put_contents($includedPath, 'included');
    file_put_contents($ignoredPath, 'ignored');

    Process::fake(function ($process) use (&$manifestEntries) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            if (preg_match("/ -T '([^']+)'/", (string) $process->command, $matches) === 1) {
                $manifestEntries = array_values(array_filter(
                    explode("\0", (string) file_get_contents($matches[1])),
                    fn (string $path): bool => $path !== '',
                ));
            }
        }

        return Process::result();
    });

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key);

        expect($manifestEntries)
            ->toContain(basename($includedPath))
            ->toContain('apps/gateway/.env.example')
            ->not->toContain(basename($ignoredPath));
    } finally {
        @unlink($includedPath);
        @unlink($ignoredPath);
    }
});

it('publishes the checkout archive excludes for tarball construction', function (): void {
    expect(E2ECurrentCheckout::archiveExcludePatterns())
        ->toContain(
            './.git',
            './.worktrees',
            './.env',
            './build',
            './apps/gateway/.env',
            './apps/gateway/public/build',
            './apps/gateway/public/hot',
            './apps/gateway/public/storage',
            './apps/gateway/storage/app/orbit/ca/*',
            './apps/gateway/storage/app/orbit/certs/*',
            './apps/gateway/storage/app/orbit/keys/*',
            './apps/gateway/storage/framework/e2e/*',
            './apps/gateway/database/*.sqlite',
            './apps/gateway/database/*.sqlite-*',
            './apps/gateway/tests/E2E/.docker-feature-tests/*',
            './apps/gateway/tests/E2E/.incus-feature-tests/*',
            './apps/gateway/vendor',
        );
});

it('includes untracked path identity in the checkout tree hash', function (): void {
    $directory = repo_path('tmp-e2e-tree-hash-'.bin2hex(random_bytes(4)));

    mkdir($directory, 0777, true);
    file_put_contents("{$directory}/alpha.txt", 'same-content');
    file_put_contents("{$directory}/beta.txt", 'same-content');

    try {
        $hashWithTwoPaths = E2ECurrentCheckout::treeHash();

        unlink("{$directory}/beta.txt");

        $hashAfterRemovingPath = E2ECurrentCheckout::treeHash();

        expect($hashAfterRemovingPath)->not->toBe($hashWithTwoPaths);
    } finally {
        @unlink("{$directory}/alpha.txt");
        @unlink("{$directory}/beta.txt");
        @rmdir($directory);
    }
});

it('runs the runtime-state phase from inside the remote checkout directory', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    expect($commands[3])->toStartWith("cd '/home/orbit/orbit-current' && ")
        ->and($commands[3])->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env");
});

it('marks Docker topology checkout env files with the Docker provider', function (): void {
    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run123-operator', 'orbit-e2e-run123');
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    $nodeInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-operator'"),
    )));

    expect($nodeInstallCommands)
        ->toContain('ORBIT_E2E_TOPOLOGY_PROVIDER')
        ->toContain('ORBIT_E2E_TOPOLOGY_PROVIDER=docker');
});

it('regenerates empty app keys while preparing remote checkout env files', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    expect($commands[3])
        ->toContain("grep -q '^APP_KEY=' apps/gateway/.env || printf '%s\\n' 'APP_KEY=' >> apps/gateway/.env")
        ->toContain("grep -Eq '^APP_KEY=base64:.+' apps/gateway/.env || php apps/gateway/artisan key:generate --force --no-interaction --ansi")
        ->toContain("grep -Eq '^APP_KEY=base64:.+' apps/gateway/.env");
});

it('installs the current checkout on Docker topology nodes through the runtime container', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run123-operator', 'orbit-e2e-run123');
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

    $nodeInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-operator'"),
    )));
    $wrapperCopyCommands = array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, 'docker cp ')
            && str_contains($command, "'orbit-e2e-run123-operator:/usr/local/bin/orbit'"),
    ));

    expect($nodeInstallCommands)
        ->toContain('sudo docker exec --env')
        ->toContain('orbit-e2e-run123-operator-orbit-runtime')
        ->toContain('key:generate --force --no-interaction --ansi')
        ->toContain('/home/orbit/orbit-current/apps/gateway/artisan')
        ->toContain('apps/gateway/.env')
        ->toContain('apps/gateway/storage/framework/views')
        ->toContain('migrate --force --ansi')
        ->toContain('/home/orbit/orbit/apps/cli/vendor')
        ->toContain('rm -rf apps/cli/vendor')
        ->toContain('ln -s')
        ->toContain('COMPOSER_CACHE_DIR=/tmp/orbit-composer-cache')
        ->toContain('composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->toContain('/home/orbit/orbit/apps/cli/.env')
        ->toContain('apps/cli/.env')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('http://gateway/api/ca/root')
        ->toContain('https://gateway')
        ->toContain('ca_sha256')
        ->toContain('ca_pem_path')
        ->not->toContain('orbit list --raw 2>/dev/null | grep -qx')
        ->not->toContain('php artisan')
        ->not->toContain('nohup php')
        ->not->toContain('php -S')
        ->and($wrapperCopyCommands)->toHaveCount(1);
});

it('bootstraps gateway app state for Docker host launcher checkout nodes without root composer installs', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run123-dev', 'orbit-e2e-run123');
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install(
        $instance,
        'orbit',
        $key,
        seedFrom: '/home/orbit/orbit',
        executorNodeIdentity: 'app-dev-1',
        hostLauncher: true,
    );

    $nodeInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-dev'"),
    )));

    expect($nodeInstallCommands)
        ->toContain('/home/orbit/orbit/apps/gateway/vendor/autoload.php')
        ->toContain('/home/orbit/orbit/apps/gateway/vendor')
        ->toContain('rm -rf apps/gateway/vendor')
        ->toContain('php apps/gateway/artisan key:generate --force --no-interaction --ansi')
        ->toContain('php apps/gateway/artisan migrate --force --ansi')
        ->toContain('php apps/gateway/artisan tinker --execute')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('/home/orbit/orbit/apps/cli/vendor')
        ->toContain('rm -rf apps/cli/vendor')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->toContain('apps/cli/.env')
        ->not->toContain('orbit-e2e-run123-dev-orbit-runtime')
        ->not->toContain('sudo docker exec --env')
        ->not->toContain('composer install');
});

it('uses the host launcher for Docker operator and app checkouts while the gateway uses the runtime wrapper', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $host = new DockerHost(E2EConfig::fromEnvironment());
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdev,
        operator: new DockerInstance($host, 'orbit-e2e-run123-operator', 'orbit-e2e-run123'),
        gateway: new DockerInstance($host, 'orbit-e2e-run123-gateway', 'orbit-e2e-run123'),
        dev: new DockerInstance($host, 'orbit-e2e-run123-dev', 'orbit-e2e-run123'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    E2ECurrentCheckout::installOnTopology($topology, roles: ['operator', 'gateway', 'dev']);

    $operatorInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-operator'"),
    )));
    $gatewayInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-gateway'"),
    )));
    $devInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-dev'"),
    )));

    expect($operatorInstallCommands)
        ->toContain('php apps/gateway/artisan key:generate')
        ->toContain('ORBIT_OPERATION_TOKEN_SECRET=%s')
        ->toContain('ORBIT_EXECUTOR_SECRET=%s')
        ->toContain('orbit-e2e-current-checkout-operation-token-secret')
        ->toContain('php apps/gateway/artisan migrate --force')
        ->toContain('php apps/gateway/artisan tinker --execute')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('/home/orbit/orbit/apps/gateway/vendor/autoload.php')
        ->toContain('rm -rf apps/gateway/vendor')
        ->toContain('/home/orbit/orbit/apps/cli/vendor')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->not->toContain('orbit-e2e-run123-operator-orbit-runtime')
        ->and($gatewayInstallCommands)
        ->toContain('orbit-e2e-run123-gateway-orbit-runtime')
        ->toContain('ORBIT_OPERATION_TOKEN_SECRET=%s')
        ->toContain('ORBIT_EXECUTOR_SECRET=%s')
        ->toContain('orbit-e2e-current-checkout-operation-token-secret')
        ->toContain('key:generate')
        ->toContain('migrate --force')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->and($devInstallCommands)
        ->toContain('php apps/gateway/artisan key:generate')
        ->toContain('ORBIT_OPERATION_TOKEN_SECRET=%s')
        ->toContain('ORBIT_EXECUTOR_SECRET=%s')
        ->toContain('orbit-e2e-current-checkout-operation-token-secret')
        ->toContain('php apps/gateway/artisan migrate --force')
        ->toContain('php apps/gateway/artisan tinker --execute')
        ->toContain('LocalGatewaySettings::current()')
        ->toContain('/home/orbit/orbit/apps/gateway/vendor/autoload.php')
        ->toContain('rm -rf apps/gateway/vendor')
        ->toContain('/home/orbit/orbit/apps/cli/vendor')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->not->toContain('orbit-e2e-run123-dev-orbit-runtime');
});

it('refreshes Docker gateway checkout host keys through the runtime container', function (): void {
    $commands = [];

    Process::fake(function ($process) use (&$commands) {
        $commands[] = (string) $process->command;

        return Process::result();
    });

    $host = new DockerHost(E2EConfig::fromEnvironment());
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGateway,
        operator: new DockerInstance($host, 'orbit-e2e-run123-operator', 'orbit-e2e-run123'),
        gateway: new DockerInstance($host, 'orbit-e2e-run123-gateway', 'orbit-e2e-run123'),
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    E2ECurrentCheckout::installOnTopology($topology, roles: ['gateway']);

    $gatewayInstallCommands = implode("\n", array_values(array_filter(
        $commands,
        fn (string $command): bool => str_starts_with($command, "docker exec --user 'orbit' 'orbit-e2e-run123-gateway'"),
    )));
    $wrapperCopyCommandIndex = array_find_key(
        $commands,
        fn (string $command): bool => str_starts_with($command, 'docker cp ')
            && str_contains($command, "'orbit-e2e-run123-gateway:/usr/local/bin/orbit'"),
    );
    $hostKeyRefreshCommandIndex = array_find_key(
        $commands,
        fn (string $command): bool => str_contains($command, 'orbit:internal:pin-node-host-keys --json'),
    );

    expect($gatewayInstallCommands)
        ->toContain('sudo docker exec --env')
        ->toContain('orbit-e2e-run123-gateway-orbit-runtime')
        ->toContain('orbit:internal:pin-node-host-keys --json')
        ->toContain('/home/orbit/orbit-current/apps/gateway/artisan')
        ->not->toContain('orbit orbit:internal:pin-node-host-keys --json')
        ->not->toContain('php artisan orbit:internal:pin-node-host-keys --json')
        ->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
        ->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
        ->and($wrapperCopyCommandIndex)->toBeInt()
        ->and($hostKeyRefreshCommandIndex)->toBeInt()
        ->and($wrapperCopyCommandIndex)->toBeLessThan($hostKeyRefreshCommandIndex);
});

it('shares one 600 second timeout budget across split install phases', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $timeouts = [];
    $instance = currentCheckoutFakeInstance($commands, timeouts: $timeouts);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $times = [1000.0, 1000.0, 1120.0, 1300.0, 1450.0];

    E2ECurrentCheckout::useNowResolverForTests(function () use (&$times): float {
        return array_shift($times) ?? 1450.0;
    });

    E2ECurrentCheckout::install($instance, 'orbit', $key);

    expect($timeouts)->toBe([
        600,
        480,
        300,
        150,
    ]);
});

it('can cache the checkout install and clone isolated runtime paths', function (): void {
    $previous = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');

    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        $firstPath = E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');
        $secondPath = E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

        $commandOutput = implode("\n", $commands);

        expect($firstPath)->toStartWith('/home/orbit/orbit-current-')
            ->and($secondPath)->toStartWith('/home/orbit/orbit-current-')
            ->and($secondPath)->not->toBe($firstPath)
            ->and(substr_count($commandOutput, 'tar --warning=no-unknown-keyword'))->toBe(1)
            ->and($commandOutput)->toContain('! -name .env -exec sh -c')
            ->and($commandOutput)->toContain('cp -al "$path" "$target"/')
            ->and($commandOutput)->toContain('dest="$target/$(basename "$path")"')
            ->and($commandOutput)->toContain('rm -rf "$dest"; cp -a --reflink=always')
            ->and($commandOutput)->toContain('rm -rf "$dest"; cp -a "$path" "$target"/')
            ->and($commandOutput)->toContain('cp -a --reflink=always')
            ->and($commandOutput)->toContain("cd '{$firstPath}' && if [ -f '/home/orbit/orbit-current-base-")
            ->and($commandOutput)->toContain("cd '{$secondPath}' && if [ -f '/home/orbit/orbit-current-base-")
            ->and($commandOutput)->not->toContain("/apps/gateway/vendor' '{$firstPath}/apps/gateway/vendor")
            ->and($commandOutput)->not->toContain("/apps/gateway/vendor' '{$secondPath}/apps/gateway/vendor")
            ->and($commandOutput)->not->toContain('chmod -R a-w')
            ->and($commandOutput)->toContain('! -name composer ! -name autoload.php -exec ln -s {} apps/gateway/vendor/')
            ->and($commandOutput)->toContain('composer --working-dir=apps/gateway dump-autoload --no-interaction --optimize')
            ->and($commandOutput)->toContain('composer --working-dir=apps/cli dump-autoload --no-interaction --optimize')
            ->and(substr_count($commandOutput, 'copy '))->toBe(1)
            ->and($commandOutput)->not->toMatch("/cp -al '\\/home\\/orbit\\/orbit-current-base-[0-9a-f]+' '\\/home\\/orbit\\/orbit-current-[^']+'/")
            ->and($commandOutput)->toContain("/apps/gateway/database/database.sqlite '{$firstPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->toContain("rm -f '{$firstPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->toContain("/apps/gateway/database/database.sqlite '{$secondPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->toContain("rm -f '{$secondPath}'/apps/gateway/database/database.sqlite")
            ->and($commandOutput)->not->toContain("/storage' '{$firstPath}/storage")
            ->and($commandOutput)->not->toContain("/storage' '{$secondPath}/storage")
            ->and($commandOutput)->toContain("/apps/gateway/storage/app' '{$firstPath}/apps/gateway/storage/app")
            ->and($commandOutput)->toContain("/apps/gateway/storage/app' '{$secondPath}/apps/gateway/storage/app");
    } finally {
        if ($previous === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previous}");
        }
    }
});

it('reuses the shared checkout archive after flushing in-process checkout state', function (): void {
    $previousCache = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    $previousCacheDir = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDir = sys_get_temp_dir().'/orbit-checkout-archive-test-'.bin2hex(random_bytes(4));
    $tarBuilds = 0;

    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');
    putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDir}");

    Process::fake(function ($process) use (&$tarBuilds) {
        if (str_starts_with((string) $process->command, 'COPYFILE_DISABLE=1 tar ')) {
            $tarBuilds++;

            if (preg_match("/ -czf '([^']+)' /", (string) $process->command, $matches) === 1) {
                file_put_contents($matches[1], 'archive');
            }
        }

        return Process::result();
    });

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');
        E2ECurrentCheckout::flushCache();
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit');

        expect($tarBuilds)->toBe(1)
            ->and(substr_count(implode("\n", $commands), 'copy '))->toBe(2);
    } finally {
        E2ECurrentCheckout::flushCache();
        Process::run('rm -rf '.escapeshellarg($cacheDir));

        if ($previousCache === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previousCache}");
        }

        if ($previousCacheDir === false) {
            putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDir}");
        }
    }
});

it('only records clone timing when reusing a cached base checkout', function (): void {
    $previousCache = getenv('ORBIT_E2E_CHECKOUT_CACHE');
    $previousCacheDir = getenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
    $cacheDir = sys_get_temp_dir().'/orbit-e2e-checkout-timing-'.bin2hex(random_bytes(4));

    putenv('ORBIT_E2E_CHECKOUT_CACHE=process');
    putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$cacheDir}");
    E2ECurrentCheckout::flushCache();

    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    try {
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);
        E2ECurrentCheckout::install($instance, 'orbit', $key, seedFrom: '/home/orbit/orbit', timer: $timer);

        expect(array_column($timer->events(), 'name'))->toBe([
            'checkout.archive',
            'checkout.copy',
            'checkout.extract',
            'checkout.vendor',
            'checkout.runtime-state',
            'checkout.migrate',
            'checkout.cache-clone',
        ]);
    } finally {
        E2ECurrentCheckout::flushCache();
        File::deleteDirectory($cacheDir);

        if ($previousCache === false) {
            putenv('ORBIT_E2E_CHECKOUT_CACHE');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_CACHE={$previousCache}");
        }

        if ($previousCacheDir === false) {
            putenv('ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR');
        } else {
            putenv("ORBIT_E2E_CHECKOUT_ARCHIVE_CACHE_DIR={$previousCacheDir}");
        }
    }
});

it('can install the current checkout on selected topology roles', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $operatorCommands = [];
    $gatewayCommands = [];
    $devCommands = [];
    $prodCommands = [];
    $agentCommands = [];

    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::OperatorGatewayAppdevAppprod,
        operator: currentCheckoutFakeInstance($operatorCommands, 'operator'),
        gateway: currentCheckoutFakeInstance($gatewayCommands, 'gateway'),
        dev: currentCheckoutFakeInstance($devCommands, 'dev'),
        prod: currentCheckoutFakeInstance($prodCommands, 'prod'),
        agent: currentCheckoutFakeInstance($agentCommands, 'agent'),
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    $paths = E2ECurrentCheckout::installOnTopology($topology, roles: ['operator', 'gateway', 'dev', 'prod', 'agent']);

    expect($paths)->toBe([
        'operator' => '/home/orbit/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
        'dev' => '/home/orbit/orbit-current',
        'prod' => '/home/orbit/orbit-current',
        'agent' => '/home/orbit/orbit-current',
    ]);

    expect(implode("\n", $operatorCommands))->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env");
    expect(implode("\n", $operatorCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $operatorCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $gatewayCommands))->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env");
    expect(implode("\n", $gatewayCommands))->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $gatewayCommands))->not->toContain('checkout.gateway-settings');
    expect(implode("\n", $devCommands))->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env");
    expect(implode("\n", $devCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $devCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $devCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $prodCommands))->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env");
    expect(implode("\n", $prodCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $prodCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $prodCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $agentCommands))->toContain("cp '/home/orbit/orbit/apps/gateway/.env' apps/gateway/.env");
    expect(implode("\n", $agentCommands))->toContain('LocalGatewaySettings::current()');
    expect(implode("\n", $agentCommands))->toContain('https://10.6.0.2');
    expect(implode("\n", $agentCommands))->not->toContain('php apps/gateway/artisan orbit:internal:pin-node-host-keys --json');
});

it('passes checkout timing through topology installation', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $operatorCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: currentCheckoutFakeInstance($operatorCommands, 'operator'),
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );
    $timer = new E2EPhaseTimer;

    E2ECurrentCheckout::installOnTopology($topology, roles: ['operator'], timer: $timer);

    expect(array_column($timer->events(), 'name'))->toContain('checkout.archive', 'checkout.migrate');
});

it('streams checkout timings from the topology harness timer child', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $operatorCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::Operator,
        operator: currentCheckoutFakeInstance($operatorCommands, 'operator'),
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );
    $lines = [];
    $timer = new E2EPhaseTimer(
        stream: true,
        writer: function (string $line) use (&$lines): void {
            $lines[] = $line;
        },
    );

    (new E2ETopologyHarness($topology))
        ->setTimer($timer)
        ->withCurrentCheckout(roles: ['operator']);

    expect($lines[0])->toBe('[orbit-e2e] checkout checkout.archive started')
        ->and(implode("\n", $lines))->toContain('checkout checkout.migrate done ');
});
