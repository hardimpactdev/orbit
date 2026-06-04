<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyProvider;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

beforeEach(function (): void {
    putenv('GH_TOKEN');
    putenv('GITHUB_TOKEN');
});

afterEach(function (): void {
    m::close();
});

it('retargets real WireGuard configuration across roles', function (): void {
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

    $instances = [
        'operator' => new IncusInstance($host, 'operator', commandTransport: true),
        'gateway' => new IncusInstance($host, 'gateway', commandTransport: true),
        'dev' => new IncusInstance($host, 'dev', commandTransport: true),
    ];

    $provider = new IncusTopologyProvider(incusTopologyProviderTestConfig());
    $method = new ReflectionMethod($provider, 'retargetRealWireGuard');
    $method->setAccessible(true);

    $method->invoke($provider, $instances);

    $joined = implode("\n", $commands);

    expect($joined)->toContain('incus exec')
        ->and($joined)->toContain('wg set wg-orbit peer')
        ->and($joined)->toContain('docker exec wg-easy');
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
