<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2EWgEasyGateway;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function wgEasyGatewayConfig(): E2EConfig
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
    );
}

it('reuses the baked wg-easy container when it is already healthy and recreates it otherwise', function (): void {
    $commands = [];
    $host = new class(wgEasyGatewayConfig(), $commands) extends IncusHost {
        /**
         * @param  array<int, string>  $commands
         */
        public function __construct(
            E2EConfig $config,
            public array &$commands,
        ) {
            parent::__construct($config);
        }

        #[Override]
        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            $this->commands[] = $command;

            $result = m::mock(ProcessResult::class);
            $result->shouldReceive('successful')->andReturn(true);
            $result->shouldReceive('output')->andReturn('');
            $result->shouldReceive('errorOutput')->andReturn('');

            return $result;
        }
    };

    new E2EWgEasyGateway()->start(
        new IncusInstance($host, 'clone-gateway', commandTransport: true),
        '10.231.5.10',
    );

    $script = implode("\n", $host->commands);
    $reuseGate = strpos($script, '"$wg_easy_reusable" -eq 1');
    $reuseAttempt = strpos($script, 'ensure_wg_easy 0');
    $recreateFallback = strpos($script, 'ensure_wg_easy 1');

    expect($reuseGate)
        ->toBeInt()
        ->and($reuseAttempt)
        ->toBeInt()
        ->and($recreateFallback)
        ->toBeInt()
        ->and($reuseGate)
        ->toBeLessThan($recreateFallback)
        ->and($reuseAttempt)
        ->toBeLessThan($recreateFallback)
        ->and($script)
        ->toContain('docker rm -f wg-easy')
        ->toContain('docker run -d')
        ->toContain('ip link show wg0')
        ->toContain('wg-easy.db')
        ->toContain('INIT_HOST=10.231.5.10');
});
