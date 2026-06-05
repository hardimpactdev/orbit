<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\E2EWireGuardMesh;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyProvider;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

beforeEach(function (): void {
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

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

it('waits for gateway host-key reachability before incus bake commands pin host keys', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    $devWait = strpos($source, '$this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::DevWireGuardIp);');
    $devBake = strpos($source, 'orbit:internal:bake-app-node app-dev-1');
    $ingressWait = strpos($source, '$this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::IngressWireGuardIp);');
    $ingressBake = strpos($source, 'orbit:internal:bake-ingress-node edge-1');
    $prodWait = strpos($source, '$this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::ProdWireGuardIp);');
    $prodBake = strpos($source, 'orbit:internal:bake-app-node app-prod-1');
    $agentWait = strpos($source, '$this->waitForGatewayHostKeyScan($gateway, $sshKeyPair, self::AgentWireGuardIp);');
    $agentBake = strpos($source, 'orbit:internal:bake-agent-node agent-1');
    $websocketBake = strpos($source, 'orbit:internal:bake-websocket-node app-dev-1');

    expect($source)->toContain('private function waitForGatewayHostKeyScan')
        ->and($source)->toContain('private function waitForHostKeyScan')
        ->and($source)->toContain('ssh-keyscan -T 5 -t ed25519,ecdsa,rsa')
        ->and($devWait)->toBeInt()
        ->and($devBake)->toBeInt()
        ->and($ingressWait)->toBeInt()
        ->and($ingressBake)->toBeInt()
        ->and($prodWait)->toBeInt()
        ->and($prodBake)->toBeInt()
        ->and($agentWait)->toBeInt()
        ->and($agentBake)->toBeInt()
        ->and($websocketBake)->toBeInt()
        ->and([
            'dev' => $devWait < $devBake,
            'ingress' => $ingressWait < $ingressBake,
            'prod' => $prodWait < $prodBake,
            'agent' => $agentWait < $agentBake,
            'websocket' => $devWait < $websocketBake,
        ])->toBe([
            'dev' => true,
            'ingress' => true,
            'prod' => true,
            'agent' => true,
            'websocket' => true,
        ]);
});

it('waits for stable gateway ssh reachability after prepared incus retargeting', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    $networkReady = strpos($source, '$timer->measure(\'network-ready\', fn () => $this->waitForPeerRoutes($instances, $config));');
    $gatewaySshWait = strpos($source, '$this->waitForGatewaySsh($gateway, $wireGuardIp);');
    $operatorScan = strpos($source, '$this->waitForOperatorHostKeyScan($operator, $config, $wireGuardIp);');

    expect($source)->toContain('private function waitForGatewaySsh')
        ->and($source)->toContain('StrictHostKeyChecking=accept-new')
        ->and($source)->toContain('successes=0')
        ->and($source)->toContain('[ "$successes" -ge 3 ]')
        ->and($source)->toContain('ConnectTimeout=10')
        ->and($source)->toContain('ServerAliveInterval=30')
        ->and($source)->toContain('ServerAliveCountMax=10')
        ->and($networkReady)->toBeInt()
        ->and($gatewaySshWait)->toBeInt()
        ->and($operatorScan)->toBeInt()
        ->and($gatewaySshWait)->toBeLessThan($operatorScan);
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
        ->and($joined)->toContain('systemctl restart ssh || systemctl restart sshd || systemctl start ssh || systemctl start sshd')
        ->and($joined)->toContain('ss -ltn')
        ->and($joined)->not->toContain('systemctl start ssh || systemctl start sshd || true')
        ->and($joined)->not->toContain("incus exec 'operator' -- sh -lc");
});

it('seeds gateway ssh access before prepared incus retargeting can converge runtime remotely', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    $initialSeed = strpos($source, "\$timer->measure('gateway-ssh-access'");
    $initialRetarget = strpos($source, "\$timer->measure('retarget'");
    $resetSeed = strpos($source, "\$cycleTimer->measure('reset.gateway-ssh-access'");
    $resetRetarget = strpos($source, "\$cycleTimer->measure('reset.retarget'");

    expect($initialSeed)->toBeInt()
        ->and($initialRetarget)->toBeInt()
        ->and($resetSeed)->toBeInt()
        ->and($resetRetarget)->toBeInt()
        ->and([
            'initial' => $initialSeed < $initialRetarget,
            'reset' => $resetSeed < $resetRetarget,
        ])->toBe([
            'initial' => true,
            'reset' => true,
        ])
        ->and($source)->toContain('orbit:internal:bake-websocket-node app-dev-1')
        ->and($source)->toContain('--converge-runtime');
});

it('uses the fixed WireGuard mesh while keeping app retargeting in the lease path', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));
    $wireguard = strpos($source, "\$timer->measure('wireguard'");
    $retarget = strpos($source, "\$timer->measure('retarget'");

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'meshFor');
    $method->setAccessible(true);

    $mesh = $method->invoke($provider, [], '10.231.0.11');

    expect($mesh->peerConfig('dev'))->toContain('PrivateKey = '.E2EWireGuardMesh::FIXED_KEYS['dev']['private_key'])
        ->and($mesh->peerConfig('dev'))->toContain('PublicKey = '.E2EWireGuardMesh::FIXED_KEYS['wg-easy']['public_key'])
        ->and($source)->toContain('E2EWireGuardMesh::fixed($gatewayProviderIp)')
        ->and($source)->toContain('private function retargetTopology')
        ->and($source)->toContain('orbit:internal:bake-websocket-node app-dev-1')
        ->and($wireguard)->toBeInt()
        ->and($retarget)->toBeInt()
        ->and($wireguard)->toBeLessThan($retarget);
});

it('allocates the first free provider subnet instead of reusing an occupied hash bucket', function (): void {
    $commands = [];
    $config = incusTopologyProviderTestConfig();
    $host = new class($config, $commands) extends IncusHost
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

            if ($command === 'incus network list --format json') {
                return incusTopologyProviderTestProcessResult(json_encode([
                    ['name' => 'ob-live-a', 'config' => ['ipv4.address' => '10.240.42.1/24']],
                    ['name' => 'ob-live-b', 'config' => ['ipv4.address' => '10.240.43.1/24']],
                    ['name' => 'docker0', 'config' => ['ipv4.address' => '172.17.0.1/16']],
                ], JSON_THROW_ON_ERROR));
            }

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider($config);
    $method = new ReflectionMethod($provider, 'allocateProviderNetwork');
    $method->setAccessible(true);

    $allocation = $method->invoke($provider, $host, 'run-colliding-with-42', preferredSubnetByte: 42);

    expect($allocation)->toBe([
        'name' => 'ob-'.substr(md5('run-colliding-with-42'), 0, 12),
        'subnet_prefix' => '10.240.44',
    ])
        ->and($commands)->toContain('incus network list --format json')
        ->and(implode("\n", $commands))->toContain("incus network create {$allocation['name']} ipv4.address=10.240.44.1/24 ipv4.nat=true ipv6.address=none raw.dnsmasq='port=0")
        ->and(implode("\n", $commands))->toContain('dhcp-option=6,10.6.0.1')
        ->and(implode("\n", $commands))->toContain("sudo iptables -I FORWARD 1 -i '{$allocation['name']}' -j ACCEPT")
        ->and(implode("\n", $commands))->toContain("sudo iptables -I FORWARD 1 -o '{$allocation['name']}' -j ACCEPT")
        ->and(implode("\n", $commands))->not->toContain('10.240.42.1/24 ipv4.nat=true')
        ->and(implode("\n", $commands))->not->toContain('10.240.43.1/24 ipv4.nat=true');
});

it('retries provider subnet allocation when dnsmasq reports an address collision', function (): void {
    $commands = [];
    $config = incusTopologyProviderTestConfig();
    $host = new class($config, $commands) extends IncusHost
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

            if ($command === 'incus network list --format json') {
                return incusTopologyProviderTestProcessResult('[]');
            }

            if (str_contains($command, '10.240.50.1/24')) {
                return incusTopologyProviderTestProcessResult('', exitCode: 1, errorOutput: 'dnsmasq: failed to create listening socket for 10.240.50.1: Address already in use');
            }

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider($config);
    $method = new ReflectionMethod($provider, 'allocateProviderNetwork');
    $method->setAccessible(true);

    $allocation = $method->invoke($provider, $host, 'run-with-host-address-collision', preferredSubnetByte: 50);

    expect($allocation['subnet_prefix'])->toBe('10.240.51')
        ->and(implode("\n", $commands))->toContain('10.240.50.1/24')
        ->and(implode("\n", $commands))->toContain('10.240.51.1/24');
});

it('uses the allocated provider network for clone, rebuild, and strict lease teardown', function (): void {
    $source = file_get_contents(repo_path('apps/e2e/app/E2E/Support/IncusTopologyProvider.php'));

    expect($source)->toContain("\$providerNetwork = \$timer->measure('incus.network.create'")
        ->and($source)->toContain('$instances = IncusTopologyTemplate::clone($host, $kind, $runId, $timer, sourceMounted: $options->sourceMountedCheckout, readonlySourceMount: $options->readonlySourceMount, networkName: $providerNetwork[\'name\'], subnetPrefix: $providerNetwork[\'subnet_prefix\']);')
        ->and($source)->toContain('$newInstances = IncusTopologyTemplate::clone($host, $kind, $runId, $cycleTimer, sourceMounted: $options->sourceMountedCheckout, readonlySourceMount: $options->readonlySourceMount, networkName: $providerNetwork[\'name\'], subnetPrefix: $providerNetwork[\'subnet_prefix\']);')
        ->and($source)->toContain('teardown: $teardown')
        ->and($source)->toContain('private function deleteProviderNetwork(IncusHost $host, string $networkName): void')
        ->and($source)->toContain('Could not delete Incus network')
        ->and($source)->toContain('private function allowProviderNetworkForwarding(IncusHost $host, string $networkName): void')
        ->and($source)->toContain('private function removeProviderNetworkForwarding(IncusHost $host, string $networkName): void');
});

it('removes provider network forwarding rules after deleting the incus network', function (): void {
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

            return incusTopologyProviderTestProcessResult();
        }
    };

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'deleteProviderNetwork');
    $method->setAccessible(true);

    $method->invoke($provider, $host, 'ob-test123');

    expect($commands)->toHaveCount(2)
        ->and($commands[0])->toBe('incus network delete ob-test123')
        ->and($commands[1])->toContain("sudo iptables -D FORWARD -i 'ob-test123' -j ACCEPT")
        ->and($commands[1])->toContain("sudo iptables -D FORWARD -o 'ob-test123' -j ACCEPT");
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
