<?php

declare(strict_types=1);

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

function currentCheckoutFakeInstance(array &$commands, string $name = 'fake-control', ?array &$timeouts = null): E2EInstance
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

    E2ECurrentCheckout::install($instance, 'control', $key);

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->toContain("cmp -s '/home/control/orbit/composer.lock' composer.lock")
        ->and($commandOutput)->toContain("[ -d '/home/control/orbit/vendor/laravel/boost' ]")
        ->and($commandOutput)->toContain("[ -d '/home/control/orbit/vendor/composer' ]")
        ->and($commandOutput)->toContain('rm -rf vendor')
        ->and($commandOutput)->toContain("find '/home/control/orbit/vendor' -mindepth 1 -maxdepth 1 ! -name composer ! -name autoload.php -exec ln -s {} vendor/ \\;")
        ->and($commandOutput)->toContain("cp -a '/home/control/orbit/vendor/composer' vendor/composer")
        ->and($commandOutput)->toContain("cp '/home/control/orbit/vendor/autoload.php' vendor/autoload.php")
        ->and($commandOutput)->toContain('composer dump-autoload --no-interaction --optimize')
        ->and($commandOutput)->not->toContain("ln -s '/home/control/orbit/vendor' vendor")
        ->and($commandOutput)->toContain('composer install --no-interaction --prefer-dist --optimize-autoloader');
});

it('can seed the current checkout from prepared topology state', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

    $commandOutput = implode("\n", $commands);

    expect($commandOutput)->toContain("cp '/home/control/orbit/.env' .env")
        ->and($commandOutput)->toContain("cp '/home/control/orbit/database/database.sqlite' database/database.sqlite")
        ->and($commandOutput)->toContain("cp -a '/home/control/orbit/storage/app' storage/app");
});

it('records checkout phase timings while installing the current checkout', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    E2ECurrentCheckout::install($instance, 'control', $key, timer: $timer);

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

    $controlCommands = [];
    $gatewayCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $timer = new E2EPhaseTimer;

    try {
        E2ECurrentCheckout::install(currentCheckoutFakeInstance($controlCommands, 'control'), 'control', $key, seedFrom: '/home/control/orbit', timer: $timer);
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
            return 'fake-control';
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

    E2ECurrentCheckout::install($instance, 'control', $key);

    expect($copiedMode)->toBe('644');
});

it('excludes persisted orbit certificate material from checkout archive manifests', function (): void {
    $certificatePath = base_path('storage/app/orbit/ca/e2e-test-certificate.pem');
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
        E2ECurrentCheckout::install($instance, 'control', $key);

        expect($manifestEntries)->not->toContain('storage/app/orbit/ca/e2e-test-certificate.pem');
    } finally {
        @unlink($certificatePath);
    }
});

it('builds checkout archives from tracked and unignored files only', function (): void {
    $suffix = bin2hex(random_bytes(4));
    $includedPath = base_path("tmp-e2e-archive-manifest-{$suffix}.txt");
    $ignoredPath = base_path("tmp-e2e-archive-manifest-{$suffix}.log");
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
        E2ECurrentCheckout::install($instance, 'control', $key);

        expect($manifestEntries)
            ->toContain(basename($includedPath))
            ->toContain('.env.example')
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
            './storage/app/orbit/ca/*',
            './storage/app/orbit/certs/*',
            './storage/app/orbit/keys/*',
            './storage/framework/e2e/*',
            './tests/E2E/.docker-feature-tests/*',
            './vendor',
        );
});

it('includes untracked path identity in the checkout tree hash', function (): void {
    $directory = base_path('tmp-e2e-tree-hash-'.bin2hex(random_bytes(4)));

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

    E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

    expect($commands[3])->toStartWith("cd '/home/control/orbit-current' && ")
        ->and($commands[3])->toContain("cp '/home/control/orbit/.env' .env");
});

it('persists dns-alias topology mode into remote checkout env files', function (): void {
    $previous = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias');

    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    try {
        E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

        expect($commands[3])
            ->toContain("grep -Ev '^(ORBIT_E2E_DOCKER_TOPOLOGY_MODE)=' .env")
            ->toContain('ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE')
            : putenv("ORBIT_E2E_DOCKER_TOPOLOGY_MODE={$previous}");
    }
});

it('regenerates empty app keys while preparing remote checkout env files', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $instance = currentCheckoutFakeInstance($commands);
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

    expect($commands[3])
        ->toContain("grep -q '^APP_KEY=' .env || printf '%s\\n' 'APP_KEY=' >> .env")
        ->toContain("grep -Eq '^APP_KEY=base64:.+' .env || php artisan key:generate --force --no-interaction --ansi")
        ->toContain("grep -Eq '^APP_KEY=base64:.+' .env");
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

    E2ECurrentCheckout::install($instance, 'control', $key);

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
        $firstPath = E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');
        $secondPath = E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

        $commandOutput = implode("\n", $commands);

        expect($firstPath)->toStartWith('/home/control/orbit-current-')
            ->and($secondPath)->toStartWith('/home/control/orbit-current-')
            ->and($secondPath)->not->toBe($firstPath)
            ->and(substr_count($commandOutput, 'tar --warning=no-unknown-keyword'))->toBe(1)
            ->and($commandOutput)->toContain('! -name vendor ! -name database ! -name storage ! -name .env -exec sh -c')
            ->and($commandOutput)->toContain('cp -al "$path" "$target"/')
            ->and($commandOutput)->toContain('cp -a --reflink=always')
            ->and($commandOutput)->toContain("ln -s '/home/control/orbit-current-base-")
            ->and($commandOutput)->toContain("/vendor' '{$firstPath}/vendor")
            ->and($commandOutput)->toContain("/vendor' '{$secondPath}/vendor")
            ->and($commandOutput)->not->toContain('chmod -R a-w')
            ->and($commandOutput)->toContain('! -name composer ! -name autoload.php -exec ln -s {} vendor/')
            ->and(substr_count($commandOutput, 'copy '))->toBe(1)
            ->and($commandOutput)->not->toMatch("/cp -al '\\/home\\/control\\/orbit-current-base-[0-9a-f]+' '\\/home\\/control\\/orbit-current-[^']+'/")
            ->and($commandOutput)->not->toContain("/storage' '{$firstPath}/storage")
            ->and($commandOutput)->not->toContain("/storage' '{$secondPath}/storage")
            ->and($commandOutput)->toContain("/storage/app' '{$firstPath}/storage/app")
            ->and($commandOutput)->toContain("/storage/app' '{$secondPath}/storage/app");
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
        E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');
        E2ECurrentCheckout::flushCache();
        E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit');

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
        E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit', timer: $timer);
        E2ECurrentCheckout::install($instance, 'control', $key, seedFrom: '/home/control/orbit', timer: $timer);

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

    $controlCommands = [];
    $gatewayCommands = [];
    $devCommands = [];
    $prodCommands = [];
    $agentCommands = [];

    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDevProd,
        control: currentCheckoutFakeInstance($controlCommands, 'control'),
        gateway: currentCheckoutFakeInstance($gatewayCommands, 'gateway'),
        dev: currentCheckoutFakeInstance($devCommands, 'dev'),
        prod: currentCheckoutFakeInstance($prodCommands, 'prod'),
        agent: currentCheckoutFakeInstance($agentCommands, 'agent'),
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );

    $paths = E2ECurrentCheckout::installOnTopology($topology, roles: ['control', 'gateway', 'dev', 'prod', 'agent']);

    expect($paths)->toBe([
        'control' => '/home/control/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
        'dev' => '/home/orbit/orbit-current',
        'prod' => '/home/orbit/orbit-current',
        'agent' => '/home/orbit/orbit-current',
    ]);

    expect(implode("\n", $controlCommands))->toContain("cp '/home/control/orbit/.env' .env");
    expect(implode("\n", $gatewayCommands))->toContain("cp '/home/orbit/orbit/.env' .env");
    expect(implode("\n", $gatewayCommands))->toContain('php artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $devCommands))->toContain("cp '/home/orbit/orbit/.env' .env");
    expect(implode("\n", $devCommands))->not->toContain('php artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $prodCommands))->toContain("cp '/home/orbit/orbit/.env' .env");
    expect(implode("\n", $prodCommands))->not->toContain('php artisan orbit:internal:pin-node-host-keys --json');
    expect(implode("\n", $agentCommands))->toContain("cp '/home/orbit/orbit/.env' .env");
    expect(implode("\n", $agentCommands))->not->toContain('php artisan orbit:internal:pin-node-host-keys --json');
});

it('passes checkout timing through topology installation', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $controlCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: currentCheckoutFakeInstance($controlCommands, 'control'),
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    );
    $timer = new E2EPhaseTimer;

    E2ECurrentCheckout::installOnTopology($topology, roles: ['control'], timer: $timer);

    expect(array_column($timer->events(), 'name'))->toContain('checkout.archive', 'checkout.migrate');
});

it('streams checkout timings from the topology harness timer child', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $controlCommands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $topology = new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: currentCheckoutFakeInstance($controlCommands, 'control'),
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
        ->withCurrentCheckout(roles: ['control']);

    expect($lines[0])->toBe('[orbit-e2e] checkout checkout.archive started')
        ->and(implode("\n", $lines))->toContain('checkout checkout.migrate done ');
});
