<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

it('waits for operator host-key scan reachability before checkout pinning runs', function (): void {
    $providerSource = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));
    $checkoutSource = file_get_contents(repo_path('apps/e2e/app/E2E/Support/E2ECurrentCheckout.php'));

    expect($providerSource)->toContain('waitForOperatorHostKeyScan')
        ->and($providerSource)->toContain('ssh-keyscan -T 5 -t ed25519,ecdsa,rsa')
        ->and($providerSource)->toContain('$this->waitForOperatorHostKeyScan($operator, $config, $wireGuardIp);')
        ->and($checkoutSource)->toContain("self::artisanCommand('orbit:internal:pin-node-host-keys --json'");
});

it('seeds the gateway ssh key into prepared incus downstream clones', function (): void {
    $commands = [];
    $host = new class(incusTopologyProviderTestConfig(), $commands) extends IncusHost
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(E2EConfig $config, private array &$commands)
        {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'ssh-keygen -y -f ~/.ssh/id_ed25519')) {
                return incusTopologyProviderTestProcessResult("ssh-ed25519 gateway-key orbit-e2e-gateway\n");
            }

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'seedGatewaySshAccess');
    $method->setAccessible(true);

    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'gateway', commandTransport: true),
        'dev' => new IncusInstance($host, 'dev', commandTransport: true),
        'prod' => new IncusInstance($host, 'prod', commandTransport: true),
        'agent' => new IncusInstance($host, 'agent', commandTransport: true),
    ]);

    $joined = implode("\n", $commands);

    expect($joined)->toContain('cat ~/.ssh/id_ed25519.pub')
        ->and($joined)->toContain('ssh-keygen -y -f ~/.ssh/id_ed25519 > ~/.ssh/id_ed25519.pub')
        ->and($joined)->toContain("incus exec 'dev' -- sh -lc")
        ->and($joined)->toContain("incus exec 'prod' -- sh -lc")
        ->and($joined)->toContain("incus exec 'agent' -- sh -lc")
        ->and($joined)->toContain('ssh-ed25519 gateway-key orbit-e2e-gateway')
        ->and($joined)->toContain('/home/orbit/.ssh/authorized_keys')
        ->and($joined)->not->toContain("incus exec 'operator' -- sh -lc");
});

it('keeps incus retarget scripts on node_role assignments instead of legacy node columns', function (): void {
    $providerSource = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));
    $builderSource = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyBuilder.php'));

    expect($providerSource)->not->toContain("'environment' => null")
        ->and($providerSource)->not->toContain("'role' => 'gateway',\n        'environment' => null")
        ->and($providerSource)->toContain('\\\\App\\\\Models\\\\NodeRoleAssignment::query()->updateOrCreate');

    expect($builderSource)->not->toContain("'environment' => null")
        ->and($builderSource)->not->toContain("'role' => 'gateway',\n        'environment' => null")
        ->and($builderSource)->toContain('INSERT INTO node_role')
        ->and($builderSource)->toContain('ON CONFLICT(node_id, role) DO UPDATE SET');
});

it('prepares gateway state before source-mounted incus retarget bootstrap', function (): void {
    $commands = [];
    $host = new class(incusTopologyProviderTestConfig(), $commands) extends IncusHost
    {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(E2EConfig $config, private array &$commands)
        {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            if (str_contains($command, 'incus query')) {
                return incusTopologyProviderTestProcessResult('{"network":{"eth0":{"addresses":[{"family":"inet","scope":"global","address":"10.231.7.84"}]}}}');
            }

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'retargetTopology');
    $method->setAccessible(true);

    $method->invoke($provider, [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true, sourceMountedCheckout: true),
        'gateway' => new IncusInstance($host, 'gateway', commandTransport: true, sourceMountedCheckout: true),
    ], incusTopologyProviderTestConfig(), new SshKeyPair('/tmp/id_ed25519', '/tmp/id_ed25519.pub'), E2ETopologyKind::OperatorGateway, true);

    $commandOutput = implode("\n", $commands);
    $stateBootstrap = strpos($commandOutput, '/home/orbit/.config/orbit/gateway.sqlite');
    $migration = strpos($commandOutput, 'php apps/gateway/artisan migrate --force --no-interaction --ansi');
    $gatewayBootstrap = strpos($commandOutput, 'php apps/gateway/artisan orbit:internal:bootstrap-gateway-local gateway');

    expect($stateBootstrap)->toBeInt()
        ->and($migration)->toBeInt()
        ->and($gatewayBootstrap)->toBeInt()
        ->and($stateBootstrap)->toBeLessThan($gatewayBootstrap)
        ->and($migration)->toBeLessThan($gatewayBootstrap)
        ->and($commandOutput)->toContain('ORBIT_CONFIG_ROOT')
        ->and($commandOutput)->toContain('/home/orbit/.config/orbit/gateway.sqlite')
        ->and($commandOutput)->toContain('/home/operator/.config/orbit/config.json')
        ->and($commandOutput)->toContain('"active_gateway":"default"')
        ->and($commandOutput)->not->toContain('LocalGatewaySettings::current')
        ->and($commandOutput)->not->toContain('/home/operator/orbit/apps/cli')
        ->not->toContain('/home/orbit/orbit/apps/gateway/database/database.sqlite');
});

function incusTopologyProviderTestConfig(): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: '',
        baseImage: '',
        bootstrapUser: 'provisioner',
        operatorUser: 'operator',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 60,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        dockerHosts: ['local'],
        keep: false,
        incusHostVmCaps: ['beast' => 4],
    );
}

function incusTopologyProviderTestProcessResult(string $output = '', int $exitCode = 0, string $errorOutput = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($exitCode === 0);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);

    return $result;
}
