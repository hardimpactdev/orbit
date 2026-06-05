<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use App\Services\E2E\IncusImageDistributor;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;

beforeEach(function (): void {
    Process::fake([
        'scp *' => Process::result(),
        'rm -rf *' => Process::result(),
    ]);
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

function incusImageDistributorConfig(string $host): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: $host,
        sourceImage: 'images:ubuntu/26.04',
        baseImage: 'orbit-base-ubuntu-26.04-runtime',
        bootstrapUser: 'provisioner',
        operatorUser: 'operator',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 600,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: 'orbit-e2e',
        dockerHosts: ['local'],
        keep: false,
    );
}

function incusImageDistributorResult(string $output = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

function incusImageDistributorHost(string $host, object $recorder): IncusHost
{
    return new class(incusImageDistributorConfig($host), $recorder) extends IncusHost
    {
        public function __construct(E2EConfig $config, private readonly object $recorder)
        {
            parent::__construct($config);
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->recorder->commands[] = [
                'host' => $this->config->host,
                'command' => $command,
            ];

            if (str_contains($command, 'mktemp -d') && str_contains($command, 'orbit-e2e-image-export')) {
                return incusImageDistributorResult("/tmp/{$this->config->host}-export\n");
            }

            if (str_contains($command, 'mktemp -d') && str_contains($command, 'orbit-e2e-image-import')) {
                return incusImageDistributorResult("/tmp/{$this->config->host}-import\n");
            }

            return incusImageDistributorResult();
        }
    };
}

it('exports from the build host and imports on target hosts', function (): void {
    $recorder = (object) ['commands' => []];
    $source = incusImageDistributorHost('beast', $recorder);
    $sidecar1 = incusImageDistributorHost('sidecar1', $recorder);
    $sidecar2 = incusImageDistributorHost('sidecar2', $recorder);

    $distributor = new IncusImageDistributor($source);

    $result = $distributor->distribute('orbit-base-ubuntu-26.04-runtime', [$sidecar1, $sidecar2]);

    expect($result)->toBe([
        [
            'host' => 'sidecar1',
            'role' => 'base',
            'alias' => 'orbit-base-ubuntu-26.04-runtime',
            'action' => 'imported',
        ],
        [
            'host' => 'sidecar2',
            'role' => 'base',
            'alias' => 'orbit-base-ubuntu-26.04-runtime',
            'action' => 'imported',
        ],
    ]);

    $commands = collect($recorder->commands);

    expect($commands->contains(fn (array $command): bool => $command['host'] === 'beast' && str_contains($command['command'], 'incus image export')))->toBeTrue()
        ->and($commands->contains(fn (array $command): bool => $command['host'] === 'sidecar1' && str_contains($command['command'], 'incus image import')))->toBeTrue()
        ->and($commands->contains(fn (array $command): bool => $command['host'] === 'sidecar2' && str_contains($command['command'], 'incus image import')))->toBeTrue();

    Process::assertRan(fn ($process): bool => str_contains($process->command, 'beast') && str_contains($process->command, 'image-export.tar.gz'));
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'sidecar1') && str_contains($process->command, 'image-export.tar.gz'));
    Process::assertRan(fn ($process): bool => str_contains($process->command, 'sidecar2') && str_contains($process->command, 'image-export.tar.gz'));
});

it('does not distribute to the source host', function (): void {
    $recorder = (object) ['commands' => []];
    $source = incusImageDistributorHost('beast', $recorder);

    $distributor = new IncusImageDistributor($source);

    expect($distributor->distribute('orbit-base-ubuntu-26.04-runtime', [$source]))->toBe([]);

    Process::assertRanTimes(fn (): bool => true, 0);
});
