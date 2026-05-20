<?php

declare(strict_types=1);

use App\E2E\Support\E2EInstance;
use App\E2E\Support\E2ETopologyCache;
use App\E2E\Support\E2ETopologyHarness;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2ETopologyLease;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;

afterEach(function (): void {
    E2ETopologyCache::flushForTests(cleanup: false);
    putenv('ORBIT_E2E_TIMINGS');
    m::close();
});

it('loads e2e pest helper functions from the pest bootstrap', function (): void {
    expect(function_exists('e2eTopology'))->toBeTrue()
        ->and(function_exists('e2eCheckout'))->toBeTrue();
});

it('wraps a topology lease with checkout and ssh helpers', function (): void {
    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $control = e2ePestFakeInstance($commands, 'control');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: $control,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    $harness->setCheckouts(['control' => '/home/control/orbit-current']);

    $result = $harness->ssh('control', 'php artisan node:list --json');

    expect($result->successful())->toBeTrue();
    expect($commands)->toContain('ssh:control:php artisan node:list --json');
    expect($harness->checkout('control'))->toBe('/home/control/orbit-current');
});

it('can expose checkout paths through the e2eCheckout helper', function (): void {
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $control = e2ePestFakeInstance($commands, 'control');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: $control,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    expect(e2eCheckout($harness, roles: ['control']))->toBe(['control' => '/home/control/orbit-current']);
});

it('clears checkout paths when the harness resets', function (): void {
    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $control = e2ePestFakeInstance($commands, 'control');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: $control,
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => [
            'instances' => ['control' => $control],
            'snapshotReset' => null,
        ],
    ));

    $harness->setCheckouts(['control' => '/home/control/orbit-current']);
    $harness->reset();

    expect(fn () => $harness->checkout('control'))
        ->toThrow(RuntimeException::class, 'Current checkout has not been installed');
});

it('fails clearly when a helper role is unavailable', function (): void {
    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::Control,
        control: e2ePestFakeInstance($commands, 'control'),
        gateway: null,
        dev: null,
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    expect(fn () => $harness->instance('gateway'))
        ->toThrow(RuntimeException::class, 'Topology does not include role [gateway]');
});

it('can share cached topologies across helper calls in one process', function (): void {
    $previousCache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');
    $previousStrategy = getenv('ORBIT_E2E_TOPOLOGY_STRATEGY');

    putenv('ORBIT_E2E_TOPOLOGY_CACHE=process');
    putenv('ORBIT_E2E_TOPOLOGY_STRATEGY=superset');

    $created = 0;
    $deleted = 0;

    E2ETopologyCache::fakeResolver(function () use (&$created, &$deleted): E2ETopologyLease {
        $created++;
        $control = e2ePestDeletableFakeInstance($deleted, 'control');
        $gatewayCommands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::ControlGatewayDevProd,
            control: $control,
            gateway: e2ePestFakeInstance($gatewayCommands, 'gateway'),
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    try {
        $first = e2eTopology(E2ETopologyKind::Control);
        $second = e2eTopology(E2ETopologyKind::ControlGatewayDevProd);

        expect($first->lease())->toBe($second->lease())
            ->and($created)->toBe(1);

        $first->cleanup();
        $second->cleanup();

        expect($deleted)->toBe(0);

        E2ETopologyCache::cleanup();

        expect($deleted)->toBe(1);
    } finally {
        if ($previousCache === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE={$previousCache}");
        }

        if ($previousStrategy === false) {
            putenv('ORBIT_E2E_TOPOLOGY_STRATEGY');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_STRATEGY={$previousStrategy}");
        }
    }
});

it('does not eagerly attach a timer to harnesses returned by the e2eTopology helper cache path', function (): void {
    $previousCache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');
    putenv('ORBIT_E2E_TOPOLOGY_CACHE=process');

    E2ETopologyCache::fakeResolver(function (): E2ETopologyLease {
        $commands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::Control,
            control: e2ePestFakeInstance($commands, 'control'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    try {
        $harness = e2eTopology(E2ETopologyKind::Control);

        expect(e2ePestHarnessTimer($harness))->toBeNull();
    } finally {
        if ($previousCache === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE={$previousCache}");
        }
    }
});

it('does not eagerly attach a timer to cached topology harnesses returned directly from the cache', function (): void {
    E2ETopologyCache::fakeResolver(function (): E2ETopologyLease {
        $commands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::Control,
            control: e2ePestFakeInstance($commands, 'control'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    $harness = E2ETopologyCache::acquire(E2ETopologyKind::Control);

    expect(e2ePestHarnessTimer($harness))->toBeNull();
});

it('creates and uses a checkout timer lazily when ORBIT_E2E_TIMINGS is enabled', function (): void {
    $previousCache = getenv('ORBIT_E2E_TOPOLOGY_CACHE');
    $previousTimings = getenv('ORBIT_E2E_TIMINGS');
    putenv('ORBIT_E2E_TOPOLOGY_CACHE=process');
    putenv('ORBIT_E2E_TIMINGS=1');
    Process::fake([
        'COPYFILE_DISABLE=1 tar *' => Process::result(),
    ]);

    E2ETopologyCache::fakeResolver(function (): E2ETopologyLease {
        $commands = [];

        return new E2ETopologyLease(
            kind: E2ETopologyKind::Control,
            control: e2ePestFakeInstance($commands, 'control'),
            gateway: null,
            dev: null,
            prod: null,
            sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
            rebuild: fn () => throw new RuntimeException('not expected'),
        );
    });

    try {
        $harness = e2eTopology(E2ETopologyKind::Control);

        expect(e2ePestHarnessTimer($harness))->toBeNull();

        $harness->withCurrentCheckout(['control']);

        $timer = e2ePestHarnessTimer($harness);

        expect($timer)->not->toBeNull()
            ->and($harness->checkouts())->toBe(['control' => '/home/control/orbit-current']);
    } finally {
        if ($previousCache === false) {
            putenv('ORBIT_E2E_TOPOLOGY_CACHE');
        } else {
            putenv("ORBIT_E2E_TOPOLOGY_CACHE={$previousCache}");
        }

        if ($previousTimings === false) {
            putenv('ORBIT_E2E_TIMINGS');
        } else {
            putenv("ORBIT_E2E_TIMINGS={$previousTimings}");
        }
    }
});

it('restarts dns alias gateway api with canonical peer identity mapping', function (): void {
    $previous = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');

    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDev,
        control: e2ePestFakeInstanceWithIp($commands, 'control', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: e2ePestFakeInstanceWithIp($commands, 'dev', '10.61.0.4'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts(['gateway' => '/home/orbit/orbit-current']);

    try {
        e2eRestartGatewayApi($harness, 'dns-alias-restart');

        expect(implode("\n", $commands))
            ->toContain('$peerIdentityMap = array')
            ->toContain('10.61.0.2')
            ->toContain('10.6.0.2')
            ->toContain('10.61.0.3')
            ->toContain('10.6.0.3')
            ->toContain('10.61.0.4')
            ->toContain('10.6.0.4');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE')
            : putenv("ORBIT_E2E_DOCKER_TOPOLOGY_MODE={$previous}");
    }
});

it('uses gateway dns identity for docker dns-alias gateway settings', function (): void {
    $previous = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDev,
        control: e2ePestFakeInstanceWithIp($commands, 'control', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: e2ePestFakeInstanceWithIp($commands, 'dev', '10.61.0.4'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));

    try {
        expect(e2eGatewayApiUrl($harness))->toBe('https://gateway')
            ->and(e2eGatewayWireGuardIp($harness))->toBe('10.6.0.2');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE')
            : putenv("ORBIT_E2E_DOCKER_TOPOLOGY_MODE={$previous}");
    }
});

it('uses lease gateway ip for non dns-alias gateway settings', function (): void {
    $previous = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDev,
        control: e2ePestFakeInstanceWithIp($commands, 'control', '10.61.0.3'),
        gateway: e2ePestFakeInstanceWithIp($commands, 'gateway', '10.61.0.2'),
        dev: e2ePestFakeInstanceWithIp($commands, 'dev', '10.61.0.4'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));

    try {
        expect(e2eGatewayApiUrl($harness))->toBe('https://10.61.0.2')
            ->and(e2eGatewayWireGuardIp($harness))->toBe('10.61.0.2');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE')
            : putenv("ORBIT_E2E_DOCKER_TOPOLOGY_MODE={$previous}");
    }
});

/**
 * @param  array<int, string>  $commands
 */
function e2ePestFakeInstance(array &$commands, string $name): E2EInstance
{
    return new class($commands, $name) implements E2EInstance
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "exec:{$this->name}:{$command}";

            return e2ePestProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "ssh:{$user}:{$command}";

            return e2ePestProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

/**
 * @param  array<int, string>  $commands
 */
function e2ePestFakeInstanceWithIp(array &$commands, string $name, string $ip): E2EInstance
{
    return new class($commands, $name, $ip) implements E2EInstance
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            private array &$commands,
            private readonly string $name,
            private readonly string $ip,
        ) {}

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "exec:{$this->name}:{$command}";

            return e2ePestProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = "ssh:{$user}:{$command}";

            return e2ePestProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return $this->ip;
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void {}
    };
}

function e2ePestDeletableFakeInstance(int &$deleted, string $name): E2EInstance
{
    return new class($deleted, $name) implements E2EInstance
    {
        private int $deleted;

        public function __construct(int &$deleted, private readonly string $name)
        {
            $this->deleted = &$deleted;
        }

        public function name(): string
        {
            return $this->name;
        }

        public function exec(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return e2ePestProcessResult();
        }

        public function ssh(string $user, SshKeyPair $keyPair, string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            return e2ePestProcessResult();
        }

        public function authorizeSsh(string $user, SshKeyPair $keyPair): void {}

        public function copyFileToInstance(string $sourcePath, string $targetPath): void {}

        public function waitForAgent(): void {}

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
        }

        public function waitForSsh(string $user, SshKeyPair $keyPair): void {}

        public function delete(): void
        {
            $this->deleted++;
        }
    };
}

function e2ePestHarnessTimer(E2ETopologyHarness $harness): mixed
{
    $property = new ReflectionProperty($harness, 'timer');

    return $property->getValue($harness);
}

function e2ePestProcessResult(): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}
