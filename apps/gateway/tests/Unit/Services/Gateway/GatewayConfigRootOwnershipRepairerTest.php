<?php

declare(strict_types=1);

use App\Services\Gateway\GatewayConfigRootOwnershipRepairer;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/orbit-config-root-owner-'.bin2hex(random_bytes(6));
    File::ensureDirectoryExists($this->root);
    putenv('ORBIT_HOST_PATH_PREFIX');
});

afterEach(function (): void {
    putenv('ORBIT_HOST_PATH_PREFIX');

    if (isset($this->root)) {
        new SymfonyProcess(['rm', '-rf', $this->root])->run();
    }
});

it('resolves no owner when no host path prefix is present', function (): void {
    $configRoot = "{$this->root}/home/orbit/.config/orbit";
    File::ensureDirectoryExists($configRoot);

    expect(new GatewayConfigRootOwnershipRepairer()->resolveOwner($configRoot))->toBeNull();
});

it('resolves the host home owner for a bind-mounted config root', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists("{$hostPrefix}/home/orbit");
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    $expected = fileowner("{$hostPrefix}/home/orbit").':'.filegroup("{$hostPrefix}/home/orbit");

    expect(new GatewayConfigRootOwnershipRepairer()->resolveOwner('/home/orbit/.config/orbit'))
        ->toBe($expected);
});

it('derives the host home from the parent directory for a non-standard config root', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists("{$hostPrefix}/srv/orbit");
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    $expected = fileowner("{$hostPrefix}/srv/orbit").':'.filegroup("{$hostPrefix}/srv/orbit");

    expect(new GatewayConfigRootOwnershipRepairer()->resolveOwner('/srv/orbit/config'))
        ->toBe($expected);
});

it('fails closed when a host path prefix is set but the host home view is missing', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists($hostPrefix);
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    expect(fn (): ?string => new GatewayConfigRootOwnershipRepairer()->resolveOwner('/home/orbit/.config/orbit'))
        ->toThrow(RuntimeException::class, 'host ownership');
});

it('rejects a traversing host path prefix', function (): void {
    putenv("ORBIT_HOST_PATH_PREFIX={$this->root}/mnt/../mnt/orbit-host");

    expect(fn (): ?string => new GatewayConfigRootOwnershipRepairer()->resolveOwner('/home/orbit/.config/orbit'))
        ->toThrow(RuntimeException::class, 'invalid');
});

it('rejects a relative host path prefix', function (): void {
    putenv('ORBIT_HOST_PATH_PREFIX=mnt/orbit-host');

    expect(fn (): ?string => new GatewayConfigRootOwnershipRepairer()->resolveOwner('/home/orbit/.config/orbit'))
        ->toThrow(RuntimeException::class, 'invalid');
});

it('never assigns the image-internal account when a host prefix is present', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists("{$hostPrefix}/home/orbit");
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    $owner = new GatewayConfigRootOwnershipRepairer()->resolveOwner('/home/orbit/.config/orbit');

    expect($owner)->not->toBeNull();

    [$uid] = explode(':', (string) $owner);

    // The documented failure mode: image uid 999 maps to an unrelated host
    // account. Ownership must come from the host home view, never the image.
    expect((int) $uid)->toBe(fileowner("{$hostPrefix}/home/orbit"));
});

it('repairs ownership across the config root tree', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists("{$hostPrefix}/home/orbit");
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    $configRoot = "{$this->root}/config";
    File::ensureDirectoryExists("{$configRoot}/certs");
    File::put("{$configRoot}/.env", 'APP_ENV=production');

    $commands = [];
    Process::fake(function ($process) use (&$commands) {
        $commands[] = is_array($process->command)
            ? implode(' ', $process->command)
            : (string) $process->command;

        return Process::result();
    });

    new GatewayConfigRootOwnershipRepairer()->repair($configRoot, '/home/orbit/.config/orbit');

    $expectedOwner = fileowner("{$hostPrefix}/home/orbit").':'.filegroup("{$hostPrefix}/home/orbit");

    expect($commands)->toBe(["chown -R {$expectedOwner} {$configRoot}"]);
});

it('surfaces a failed ownership repair instead of reporting success', function (): void {
    $hostPrefix = "{$this->root}/mnt/orbit-host";
    File::ensureDirectoryExists("{$hostPrefix}/home/orbit");
    putenv("ORBIT_HOST_PATH_PREFIX={$hostPrefix}");

    $configRoot = "{$this->root}/config";
    File::ensureDirectoryExists($configRoot);

    Process::fake(fn () => Process::result(output: '', errorOutput: 'chown: not permitted', exitCode: 1));

    expect(fn (): null => new GatewayConfigRootOwnershipRepairer()->repair($configRoot, '/home/orbit/.config/orbit'))
        ->toThrow(RuntimeException::class, 'Failed to repair gateway config root ownership');
});

it('leaves ownership untouched when no host path prefix is present', function (): void {
    $configRoot = "{$this->root}/config";
    File::ensureDirectoryExists($configRoot);

    Process::fake();

    new GatewayConfigRootOwnershipRepairer()->repair($configRoot, '/home/orbit/.config/orbit');

    Process::assertNothingRan();
});
