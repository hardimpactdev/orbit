<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
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

it('builds provider aware current checkout orbit wrappers', function (): void {
    $docker = e2eOrbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: true, executorNodeIdentity: 'app-dev-1', hostLauncher: true);
    $incus = e2eOrbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: false);

    expect($docker)
        ->toContain('sudo docker exec')
        ->toContain("if [[ '1' == '1' ]]")
        ->toContain('ORBIT_REPO="${checkout}"')
        ->toContain('ORBIT_NODE_IDENTITY="${ORBIT_NODE_IDENTITY:-app-dev-1}"')
        ->toContain('exec "${checkout}/bin/orbit" "$@"')
        ->toContain('ORBIT_SOURCE_PATH=/home/orbit/orbit-current')
        ->toContain('ORBIT_IS_GATEWAY=${ORBIT_IS_GATEWAY}')
        ->toContain('runtime_workdir="${ORBIT_HOST_CWD:-$PWD}"')
        ->toContain('--workdir "${runtime_workdir}"')
        ->not->toContain('exec php')
        ->toContain("php '/home/orbit/orbit-current/artisan' \"\$@\"")
        ->and($incus)
        ->toContain("exec php '/home/orbit/orbit-current/artisan'")
        ->not->toContain('sudo docker exec');
});

it('runs provider aware runtime commands through Docker runtime siblings', function (): void {
    Process::fake(['*' => Process::result()]);
    Process::preventStrayProcesses();

    $commands = [];
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGateway,
        control: e2ePestFakeInstance($commands, 'control'),
        gateway: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-gateway', 'orbit-e2e-run'),
        dev: null,
        prod: null,
        sshKeyPair: new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'),
        rebuild: fn () => throw new RuntimeException('not expected'),
    ));

    e2eRunInRoleRuntime($harness, 'gateway', e2ePhpServerCommand(
        port: 48123,
        routerPath: '/tmp/router.php',
        logPath: '/tmp/router.log',
        pidPath: '/tmp/router.pid',
    ));

    Process::assertRan(fn ($process): bool => is_string($process->command)
        && str_contains($process->command, "docker exec 'orbit-e2e-run-gateway' sh -lc")
        && str_contains($process->command, 'orbit-e2e-run-gateway-orbit-runtime')
        && str_contains($process->command, 'php -S 127.0.0.1:48123'));
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

it('seeds current-checkout gateway settings for control callers', function (): void {
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
    $harness->setCheckouts(['control' => '/home/control/orbit-current']);

    try {
        e2eConfigureCurrentCheckoutGatewaySettings($harness);

        expect($commands)->toHaveCount(1)
            ->and($commands[0])->toContain("cd '/home/control/orbit-current' && orbit tinker --execute=")
            ->and($commands[0])->toContain('LocalGatewaySettings::current()')
            ->and($commands[0])->toContain('gateway_url')
            ->and($commands[0])->toContain('https://10.61.0.2')
            ->and($commands[0])->toContain('gateway_wg_ip')
            ->and($commands[0])->toContain('10.61.0.2')
            ->and($commands[0])->not->toContain("cd '/home/control/orbit' &&");
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE')
            : putenv("ORBIT_E2E_DOCKER_TOPOLOGY_MODE={$previous}");
    }
});

it('seeds docker app current-checkout gateway settings through the cli env', function (): void {
    $previous = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias');

    $dockerCommands = [];
    Process::fake(function ($process) use (&$dockerCommands): ProcessResult {
        $dockerCommands[] = (string) $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDev,
        control: e2ePestFakeInstance($commands, 'control'),
        gateway: e2ePestFakeInstance($commands, 'gateway'),
        dev: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-dev', 'orbit-e2e-run'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts(['dev' => '/home/orbit/orbit-current']);

    try {
        e2eConfigureCurrentCheckoutGatewaySettings($harness, 'dev');

        expect($dockerCommands)->toHaveCount(1)
            ->and($dockerCommands[0])->toContain("docker exec --user 'orbit' 'orbit-e2e-run-dev' sh -lc")
            ->and($dockerCommands[0])->toContain('/home/orbit/orbit-current/apps/cli')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_URL')
            ->and($dockerCommands[0])->toContain('ORBIT_GATEWAY_IDENTITY')
            ->and($dockerCommands[0])->toContain('http://gateway')
            ->and($dockerCommands[0])->not->toContain('orbit tinker --execute')
            ->and($dockerCommands[0])->not->toContain('LocalGatewaySettings::current()');
    } finally {
        $previous === false
            ? putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE')
            : putenv("ORBIT_E2E_DOCKER_TOPOLOGY_MODE={$previous}");
    }
});

it('seeds docker control current-checkout gateway settings through the root app', function (): void {
    $previous = getenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE');
    putenv('ORBIT_E2E_DOCKER_TOPOLOGY_MODE=dns-alias');

    $dockerCommands = [];
    Process::fake(function ($process) use (&$dockerCommands): ProcessResult {
        $dockerCommands[] = (string) $process->command;

        return Process::result();
    });
    Process::preventStrayProcesses();

    $commands = [];
    $key = new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub');
    $harness = new E2ETopologyHarness(new E2ETopologyLease(
        kind: E2ETopologyKind::ControlGatewayDev,
        control: new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-run-control', 'orbit-e2e-run'),
        gateway: e2ePestFakeInstance($commands, 'gateway'),
        dev: e2ePestFakeInstance($commands, 'dev'),
        prod: null,
        sshKeyPair: $key,
        rebuild: fn () => throw new RuntimeException('not expected'),
        gatewayApiIp: '10.61.0.2',
    ));
    $harness->setCheckouts([
        'control' => '/home/control/orbit-current',
        'gateway' => '/home/orbit/orbit-current',
    ]);

    try {
        e2eConfigureCurrentCheckoutGatewaySettings($harness);

        $dockerCommandOutput = implode("\n", $dockerCommands);

        expect($dockerCommands)->toHaveCount(2)
            ->and($commands)->toContain("ssh:orbit:cat '/home/orbit/orbit-current/storage/app/orbit/ca/root.crt'")
            ->and($dockerCommandOutput)->toContain("docker exec --user 'control' 'orbit-e2e-run-control' sh -lc")
            ->and($dockerCommandOutput)->toContain('/home/control/orbit-current/storage/app/orbit/ca/root.crt')
            ->and($dockerCommandOutput)->toContain('orbit tinker --execute')
            ->and($dockerCommandOutput)->toContain('LocalGatewaySettings::current()')
            ->and($dockerCommandOutput)->toContain('https://gateway')
            ->and($dockerCommandOutput)->toContain('ca_pem_path')
            ->and($dockerCommandOutput)->not->toContain('/apps/cli');
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
