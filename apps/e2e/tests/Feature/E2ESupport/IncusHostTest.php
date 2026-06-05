<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\IncusHost;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function incusHostConfig(): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        topologyProviderNames: ['incus'],
        host: 'beast',
        sourceImage: 'images:ubuntu/26.04/cloud',
        baseImage: 'orbit-base-ubuntu-26.04',
        bootstrapUser: 'provisioner',
        operatorUser: 'orbit',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 600,
        cpus: '2',
        memory: '2GiB',
        topologyCpus: '1',
        topologyMemory: '2GiB',
        topologyRootSize: '16GiB',
        topologyStateSize: '4GiB',
        incusStoragePool: '',
        dockerHosts: ['local'],
        keep: false,
    );
}

function incusHostProcessResult(
    bool $successful = true,
    string $output = '',
    string $errorOutput = '',
    int $exitCode = 0,
): ProcessResult {
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn($errorOutput);
    $result->shouldReceive('exitCode')->andReturn($exitCode);

    return $result;
}

it('skips cloud init waiting when the instance does not have cloud init installed', function (): void {
    $commands = [];

    $host = new class(incusHostConfig(), $commands) extends IncusHost
    {
        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, private array &$commands)
        {
            parent::__construct($config);
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostProcessResult(
                successful: false,
                errorOutput: 'sh: 1: cloud-init: not found',
                exitCode: 127,
            );
        }
    };

    $host->waitForCloudInit('runtime-base-1', timeoutSeconds: 1);

    expect($commands)->toHaveCount(1);
});

it('still waits for cloud init when cloud init is installed', function (): void {
    $commands = [];

    $host = new class(incusHostConfig(), $commands) extends IncusHost
    {
        /**
         * @param  list<string>  $commands
         */
        public function __construct(E2EConfig $config, private array &$commands)
        {
            parent::__construct($config);
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            return incusHostProcessResult(output: 'status: done');
        }
    };

    $host->waitForCloudInit('cloud-base-1', timeoutSeconds: 1);

    expect($commands)->toHaveCount(1);
});
