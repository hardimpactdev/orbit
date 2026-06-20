<?php

declare(strict_types=1);

use App\Services\Dns\LocalResolver;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Symfony\Component\Process\Process as SymfonyProcess;

describe(LocalResolver::class, function (): void {
    beforeEach(function (): void {
        $this->originalHome = getenv('HOME');
        $this->tempHome = sys_get_temp_dir().'/orbit-resolver-test-'.bin2hex(random_bytes(4));
        $this->tempPrefix = sys_get_temp_dir().'/orbit-brew-prefix-test-'.bin2hex(random_bytes(4));
        mkdir($this->tempHome, 0o700, true);
        mkdir($this->tempPrefix, 0o700, true);
        putenv("HOME={$this->tempHome}");

        Process::preventStrayProcesses();
    });

    afterEach(function (): void {
        putenv('HOME='.($this->originalHome === false ? '' : $this->originalHome));
        if (is_dir($this->tempHome)) {
            File::deleteDirectory($this->tempHome);
        }
        if (is_dir($this->tempPrefix)) {
            File::deleteDirectory($this->tempPrefix);
        }
    });

    it('resolves configDir under the host-writable Orbit config home', function (): void {
        $resolver = new LocalResolver;

        expect($resolver->configDir())->toBe("{$this->tempHome}/.config/orbit/dnsmasq.d");
    });

    it('configDir does not use storage_path', function (): void {
        $resolver = new LocalResolver;

        expect($resolver->configDir())->not->toContain(storage_path());
    });

    it('throws when HOME is not set', function (): void {
        putenv('HOME');

        $resolver = new LocalResolver;

        expect(fn () => $resolver->configDir())->toThrow(RuntimeException::class, 'HOME environment variable is not set.');
    });

    it('detects Homebrew dnsmasq under sbin when it is not on PATH', function (): void {
        File::ensureDirectoryExists("{$this->tempPrefix}/sbin");
        File::put("{$this->tempPrefix}/sbin/dnsmasq", '#!/bin/sh');
        chmod("{$this->tempPrefix}/sbin/dnsmasq", 0o755);

        Process::fake(function (PendingProcess $process) {
            if ($process->command === 'which dnsmasq') {
                return Process::result('', 'dnsmasq not found', 1);
            }

            if ($process->command === 'brew --prefix') {
                return Process::result($this->tempPrefix, '', 0);
            }

            return Process::result('', "Unexpected command: {$process->command}", 1);
        });

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->isDnsmasqInstalled())->toBeTrue();
    });

    it('resolve writes the dnsmasq conf under the host-writable Orbit config home', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '10.6.0.1');

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $resolver->resolve('test', '10.6.0.1');

        $expectedConf = "{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf";

        expect(File::exists($expectedConf))->toBeTrue()
            ->and(File::get($expectedConf))->toBe("address=/test/10.6.0.1\n");
    });

    it('writes the master dnsmasq conf under the faked Homebrew prefix', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '10.6.0.1');

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $resolver->resolve('test', '10.6.0.1');

        $masterConfig = "{$this->tempPrefix}/etc/dnsmasq.conf";

        expect(File::exists($masterConfig))->toBeTrue()
            ->and(File::get($masterConfig))->toContain("conf-dir={$this->tempHome}/.config/orbit/dnsmasq.d/,*.conf");
    });

    it('reads the dnsmasq address format that resolve writes', function (): void {
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;

        expect($resolver->existingTarget('test'))->toBe('192.168.1.150');
    });

    it('lists overrides written in the resolver address format', function (): void {
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;

        expect($resolver->listOverrides())->toBe([
            [
                'tld' => 'test',
                'target' => '192.168.1.150',
                'source' => 'local_resolver',
                'resolver_backend' => 'dnsmasq',
                'status' => 'active',
            ],
        ]);
    });

    it('reports already resolved when the requested mapping already exists and is served locally', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '192.168.1.150');
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->resolve('test', '192.168.1.150'))->toBe([
            'status' => 'already_resolved',
            'changed' => false,
        ]);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'dig @127.0.0.1 orbit-local-resolver-health.test +short');
        Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === 'sudo brew services restart dnsmasq');
    });

    it('repairs the macOS resolver file when an existing mapping points at the requested target', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '192.168.1.150', resolverContents: "nameserver 10.6.0.1\n");
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->resolve('test', '192.168.1.150'))->toBe([
            'status' => 'resolved',
            'changed' => true,
        ]);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'cat \'/etc/resolver/test\'');
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'sudo -n mkdir -p /etc/resolver && echo \'nameserver 127.0.0.1\' | sudo -n tee \'/etc/resolver/test\' > /dev/null');
        Process::assertNotRan(fn (PendingProcess $process): bool => $process->command === 'sudo brew services restart dnsmasq');
    });

    it('refreshes dnsmasq when the requested mapping exists but is not served locally', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: ['', "192.168.1.150\n"]);
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->resolve('test', '192.168.1.150'))->toBe([
            'status' => 'already_resolved',
            'changed' => false,
        ]);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'sudo brew services restart dnsmasq');
        Process::assertRanTimes(fn (PendingProcess $process): bool => $process->command === 'dig @127.0.0.1 orbit-local-resolver-health.test +short', 2);
    });

    it('flushes the macOS resolver cache when an existing mapping target changes', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '10.6.0.7');
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->resolve('test', '10.6.0.7'))->toBe([
            'status' => 'resolved',
            'changed' => true,
        ]);

        expect(File::get("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf"))
            ->toBe("address=/test/10.6.0.7\n");

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'dscacheutil -flushcache');
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'sudo killall -HUP mDNSResponder');
    });

    it('returns refresh_failed when an existing mapping cannot be served after refresh', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: ['', '']);
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = $resolver->resolve('test', '192.168.1.150');

        expect($result['status'])->toBe('refresh_failed')
            ->and($result['changed'])->toBeFalse()
            ->and($result['error'])->toContain('dnsmasq did not return 192.168.1.150')
            ->and($result['error'])->toContain('Running: false');
    });

    it('removes stale Orbit dnsmasq master config entries before refreshing an existing mapping', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '192.168.1.150');
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::ensureDirectoryExists("{$this->tempPrefix}/etc");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");
        File::put("{$this->tempPrefix}/etc/dnsmasq.conf", implode("\n", [
            'address=/.test/127.0.0.1',
            'conf-dir=/tmp/orbit-resolver-test-old/.config/orbit/dnsmasq.d/,*.conf',
            'conf-dir=/Users/nckrtl/orbit/storage/app/orbit/dnsmasq.d/,*.conf',
            'conf-dir=/Users/nckrtl/orbit-worktrees/todo-398/storage/framework/testing/dns/parallel-7/app/orbit/dnsmasq.d/,*.conf',
            'conf-dir=/opt/homebrew/etc/custom-dnsmasq.d,*.conf',
            '',
        ]));

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->resolve('test', '192.168.1.150'))->toBe([
            'status' => 'resolved',
            'changed' => true,
        ]);

        $masterConfig = File::get("{$this->tempPrefix}/etc/dnsmasq.conf");

        expect($masterConfig)
            ->toContain("conf-dir={$this->tempHome}/.config/orbit/dnsmasq.d/,*.conf")
            ->toContain('conf-dir=/opt/homebrew/etc/custom-dnsmasq.d,*.conf')
            ->not->toContain('address=/.test/127.0.0.1')
            ->not->toContain('orbit-resolver-test-old')
            ->not->toContain('/storage/app/orbit/dnsmasq.d/')
            ->not->toContain('/app/orbit/dnsmasq.d/');
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'sudo brew services restart dnsmasq');
    });

    it('flushes the macOS resolver cache when resetting an existing mapping', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '127.0.0.1');
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->reset('test'))->toBe([
            'status' => 'reset',
            'changed' => true,
        ]);

        expect(File::exists("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf"))->toBeFalse();

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'sudo -n rm \'/etc/resolver/test\'');
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'dscacheutil -flushcache');
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'sudo killall -HUP mDNSResponder');
    });

    it('refreshes macOS dnsmasq as a root Homebrew service and verifies the target is served locally', function (): void {
        fakeSuccessfulLocalResolverProcesses($this, '192.168.1.150');

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        expect($resolver->resolve('test', '192.168.1.150'))->toBe([
            'status' => 'resolved',
            'changed' => true,
        ]);

        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'sudo brew services restart dnsmasq');
        Process::assertRan(fn (PendingProcess $process): bool => $process->command === 'dig @127.0.0.1 orbit-local-resolver-health.test +short');
    });

    it('returns refresh_failed when dnsmasq does not serve the configured target after restart', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: "192.168.1.151\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = $resolver->resolve('test', '192.168.1.150');

        expect($result['status'])->toBe('refresh_failed')
            ->and($result['changed'])->toBeTrue()
            ->and($result['error'])->toContain('dnsmasq did not return 192.168.1.150')
            ->and($result['error'])->toContain('192.168.1.151')
            ->and($result['error'])->toContain('Running: false');
    });

    it('returns refresh_failed when dnsmasq verification times out after restart', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '', healthErrorOutput: 'operation timed out', healthExitCode: 1);

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = $resolver->resolve('test', '192.168.1.150');

        expect($result['status'])->toBe('refresh_failed')
            ->and($result['changed'])->toBeTrue()
            ->and($result['error'])->toContain('operation timed out')
            ->and($result['error'])->toContain('Running: false');
    });

    it('returns refresh_failed when DNS verification exceeds the process timeout', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '', healthThrowsTimeout: true);

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = $resolver->resolve('test', '192.168.1.150');

        expect($result['status'])->toBe('refresh_failed')
            ->and($result['changed'])->toBeTrue()
            ->and($result['error'])->toContain('dnsmasq did not return 192.168.1.150')
            ->and($result['error'])->toContain('exceeded the timeout')
            ->and($result['error'])->toContain('Running: false');
    });

    it('returns write_failed when syncing the macOS system resolver exceeds the process timeout', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '192.168.1.150', resolverContents: "nameserver 10.6.0.1\n", resolverWriteThrowsTimeout: true);
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = $resolver->resolve('test', '192.168.1.150');

        expect($result['status'])->toBe('write_failed')
            ->and($result['changed'])->toBeFalse()
            ->and($result['error'])->toContain('exceeded the timeout')
            ->and($result['error'])->toContain('/etc/resolver/test');
    });

    it('returns write_failed when writing the macOS system resolver exceeds the process timeout', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '192.168.1.150', resolverWriteThrowsTimeout: true);

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = null;

        expect(function () use ($resolver, &$result): void {
            $result = $resolver->resolve('test', '192.168.1.150');
        })->not->toThrow(ProcessTimedOutException::class);

        expect($result['status'])->toBe('write_failed')
            ->and($result['changed'])->toBeFalse()
            ->and($result['error'])->toContain('Process timed out while running')
            ->and($result['error'])->toContain('exceeded the timeout')
            ->and($result['error'])->toContain('/etc/resolver/test');
    });

    it('returns write_failed when removing the macOS system resolver exceeds the process timeout', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '127.0.0.1', resolverRemoveThrowsTimeout: true);
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = null;

        expect(function () use ($resolver, &$result): void {
            $result = $resolver->reset('test');
        })->not->toThrow(ProcessTimedOutException::class);

        expect($result['status'])->toBe('write_failed')
            ->and($result['changed'])->toBeTrue()
            ->and($result['error'])->toContain('Process timed out while running')
            ->and($result['error'])->toContain('exceeded the timeout')
            ->and($result['error'])->toContain('/etc/resolver/test');
    });

    it('returns write_failed when syncing the macOS system resolver lacks cached sudo credentials', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '192.168.1.150', resolverContents: "nameserver 10.6.0.1\n", resolverWriteFailsNoninteractive: true);
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = null;

        expect(function () use ($resolver, &$result): void {
            $result = $resolver->resolve('test', '192.168.1.150');
        })->not->toThrow(ProcessTimedOutException::class);

        expect($result['status'])->toBe('write_failed')
            ->and($result['changed'])->toBeFalse()
            ->and($result['error'])->toContain('sudo: a password is required');
    });

    it('returns write_failed when writing the macOS system resolver lacks cached sudo credentials', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '192.168.1.150', resolverWriteFailsNoninteractive: true);

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = null;

        expect(function () use ($resolver, &$result): void {
            $result = $resolver->resolve('test', '192.168.1.150');
        })->not->toThrow(ProcessTimedOutException::class);

        expect($result['status'])->toBe('write_failed')
            ->and($result['changed'])->toBeFalse()
            ->and($result['error'])->toContain('sudo: a password is required');
    });

    it('returns write_failed when removing the macOS system resolver lacks cached sudo credentials', function (): void {
        fakeLocalResolverProcesses($this, healthOutput: '127.0.0.1', resolverRemoveFailsNoninteractive: true);
        writeCurrentMasterDnsmasqConfig($this);
        File::ensureDirectoryExists("{$this->tempHome}/.config/orbit/dnsmasq.d");
        File::put("{$this->tempHome}/.config/orbit/dnsmasq.d/test.conf", "address=/test/192.168.1.150\n");

        $resolver = new LocalResolver;
        $resolver->setPlatform('macos');

        $result = null;

        expect(function () use ($resolver, &$result): void {
            $result = $resolver->reset('test');
        })->not->toThrow(ProcessTimedOutException::class);

        expect($result['status'])->toBe('write_failed')
            ->and($result['changed'])->toBeTrue()
            ->and($result['error'])->toContain('sudo: a password is required');
    });
});

function fakeSuccessfulLocalResolverProcesses(object $test, string $target, ?string $resolverContents = "nameserver 127.0.0.1\n"): void
{
    fakeLocalResolverProcesses($test, healthOutput: "{$target}\n", resolverContents: $resolverContents);
}

function writeCurrentMasterDnsmasqConfig(object $test): void
{
    File::ensureDirectoryExists("{$test->tempPrefix}/etc");
    File::put(
        "{$test->tempPrefix}/etc/dnsmasq.conf",
        "conf-dir={$test->tempHome}/.config/orbit/dnsmasq.d/,*.conf\n",
    );
}

/**
 * @param  string|array<int, string>  $healthOutput
 */
function fakeLocalResolverProcesses(
    object $test,
    string|array $healthOutput,
    string $healthErrorOutput = '',
    int $healthExitCode = 0,
    bool $healthThrowsTimeout = false,
    ?string $resolverContents = "nameserver 127.0.0.1\n",
    bool $resolverWriteThrowsTimeout = false,
    bool $resolverRemoveThrowsTimeout = false,
    bool $resolverWriteFailsNoninteractive = false,
    bool $resolverRemoveFailsNoninteractive = false,
): void {
    $healthOutputs = is_array($healthOutput) ? array_values($healthOutput) : [$healthOutput];

    Process::fake(function (PendingProcess $process) use ($test, &$healthOutputs, $healthErrorOutput, $healthExitCode, $healthThrowsTimeout, $resolverContents, $resolverWriteThrowsTimeout, $resolverRemoveThrowsTimeout, $resolverWriteFailsNoninteractive, $resolverRemoveFailsNoninteractive) {
        $command = $process->command;

        if ($command === 'brew --prefix') {
            return Process::result($test->tempPrefix, '', 0);
        }

        if ($command === 'cat \'/etc/resolver/test\'') {
            if ($resolverContents === null) {
                return Process::result('', 'No such file or directory', 1);
            }

            return Process::result($resolverContents, '', 0);
        }

        if ($command === 'test -f \'/etc/resolver/test\'') {
            return Process::result('', '', 0);
        }

        if ($command === 'sudo -n mkdir -p /etc/resolver && echo \'nameserver 127.0.0.1\' | sudo -n tee \'/etc/resolver/test\' > /dev/null') {
            if ($resolverWriteThrowsTimeout) {
                throw fakeProcessTimedOutException($command);
            }

            if ($resolverWriteFailsNoninteractive) {
                return Process::result('', 'sudo: a password is required', 1);
            }

            return Process::result('', '', 0);
        }

        if ($command === 'sudo -n rm \'/etc/resolver/test\'') {
            if ($resolverRemoveThrowsTimeout) {
                throw fakeProcessTimedOutException($command);
            }

            if ($resolverRemoveFailsNoninteractive) {
                return Process::result('', 'sudo: a password is required', 1);
            }

            return Process::result('', '', 0);
        }

        if ($command === 'dscacheutil -flushcache') {
            return Process::result('', '', 0);
        }

        if ($command === 'sudo killall -HUP mDNSResponder') {
            return Process::result('', '', 0);
        }

        if (in_array($command, ['brew services restart dnsmasq', 'sudo brew services restart dnsmasq'], true)) {
            return Process::result('', '', 0);
        }

        if ($command === 'sudo brew services info dnsmasq') {
            return Process::result("dnsmasq (homebrew.mxcl.dnsmasq)\nRunning: false\nLoaded: true\nSchedulable: false\n", '', 0);
        }

        if ($command === 'dig @127.0.0.1 orbit-local-resolver-health.test +short') {
            if ($healthThrowsTimeout) {
                throw fakeProcessTimedOutException($command);
            }

            $healthOutput = count($healthOutputs) > 1
                ? array_shift($healthOutputs)
                : $healthOutputs[0];

            return Process::result($healthOutput, $healthErrorOutput, $healthExitCode);
        }

        if ($command === 'dig @127.0.0.1 localhost +short') {
            return Process::result("127.0.0.1\n", '', 0);
        }

        return Process::result('', "Unexpected command: {$command}", 1);
    });
}

function fakeProcessTimedOutException(string $command): ProcessTimedOutException
{
    $process = SymfonyProcess::fromShellCommandline($command);
    $exception = new SymfonyProcessTimedOutException($process, SymfonyProcessTimedOutException::TYPE_GENERAL);

    return new ProcessTimedOutException($exception, Process::result('', '', 1));
}
