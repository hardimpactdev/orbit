<?php

declare(strict_types=1);

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;
use Tests\E2E\Support\E2EInstance;
use Tests\E2E\Support\E2ETopologyCache;
use Tests\E2E\Support\E2ETopologyHarness;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\E2ETopologyLease;
use Tests\E2E\Support\SshKeyPair;

afterEach(function (): void {
    E2ETopologyCache::flushForTests(cleanup: false);
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

        public function waitForIpv4(): string
        {
            return '10.201.0.10';
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

function e2ePestProcessResult(): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn('');
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}
