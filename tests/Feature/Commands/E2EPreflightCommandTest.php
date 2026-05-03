<?php

declare(strict_types=1);

use App\Console\Commands\E2EPreflightCommand;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Mockery as m;
use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\IncusHost;
use Tests\E2E\Support\IncusHostPool;

beforeEach(function (): void {
    Process::preventStrayProcesses();
});

afterEach(function (): void {
    m::close();
});

function e2eConfig(string $host = 'beast'): E2EConfig
{
    return new E2EConfig(
        providerNames: ['incus'],
        host: $host,
        sourceImage: 'images:ubuntu/26.04/cloud',
        blankImage: 'orbit-blank-ubuntu-26.04',
        controlImage: 'orbit-ready-control',
        gatewayImage: 'orbit-ready-gateway',
        hcloudServerType: 'cpx11',
        hcloudLocation: 'ash',
        hcloudBlankImage: 'ubuntu-24.04',
        hcloudControlImage: '',
        hcloudGatewayImage: '',
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        instancePrefix: 'orbit-e2e',
        timeoutSeconds: 600,
        cpus: '2',
        memory: '2GiB',
        keep: false,
    );
}

function processResult(bool $successful, string $output = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn($successful);
    $result->shouldReceive('output')->andReturn($output);

    return $result;
}

function testHost(E2EConfig $config, array $results): IncusHost
{
    return new class($config, $results) extends IncusHost
    {
        private array $results;

        public function __construct(E2EConfig $config, array $results)
        {
            parent::__construct($config);
            $this->results = $results;
        }

        public function run(string $command, ?int $timeoutSeconds = null): ProcessResult
        {
            if (! isset($this->results[$command])) {
                throw new RuntimeException("Unexpected command: {$command}");
            }

            return $this->results[$command];
        }
    };
}

it('is hidden', function (): void {
    $command = app(E2EPreflightCommand::class);

    expect($command->isHidden())->toBeTrue();
});

it('returns success json when all hosts reachable', function (): void {
    $host = testHost(e2eConfig('beast'), [
        'incus version' => processResult(true, "Client version: 0.7\nServer version: 0.7"),
        'incus ls --format json' => processResult(true),
    ]);

    $pool = new IncusHostPool([$host]);

    $command = app(E2EPreflightCommand::class);
    $command->setHostPoolFactory(fn () => $pool);
    $this->app->instance(E2EPreflightCommand::class, $command);

    $expected = json_encode([
        'success' => [
            'data' => [
                'provider' => 'incus',
                'hosts' => [
                    ['host' => 'beast', 'reachable' => true, 'incus_version' => '0.7'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:preflight', ['--json' => true])
        ->expectsOutput($expected)
        ->assertSuccessful();
});

it('returns error json when any host unreachable', function (): void {
    $host = testHost(e2eConfig('sidecar1'), [
        'incus version' => processResult(false),
    ]);

    $pool = new IncusHostPool([$host]);

    $command = app(E2EPreflightCommand::class);
    $command->setHostPoolFactory(fn () => $pool);
    $this->app->instance(E2EPreflightCommand::class, $command);

    $expected = json_encode([
        'error' => [
            'code' => 'preflight_failed',
            'message' => 'One or more Incus hosts are unreachable',
            'data' => [
                'provider' => 'incus',
                'hosts' => [
                    ['host' => 'sidecar1', 'reachable' => false, 'reason' => 'incus version check failed'],
                ],
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:preflight', ['--json' => true])
        ->expectsOutput($expected)
        ->assertFailed();
});

it('outputs text summary for reachable host', function (): void {
    $host = testHost(e2eConfig('beast'), [
        'incus version' => processResult(true, "Client version: 0.7\nServer version: 0.7"),
        'incus ls --format json' => processResult(true),
    ]);

    $pool = new IncusHostPool([$host]);

    $command = app(E2EPreflightCommand::class);
    $command->setHostPoolFactory(fn () => $pool);
    $this->app->instance(E2EPreflightCommand::class, $command);

    $this->artisan('e2e:preflight')
        ->expectsOutputToContain('OK beast (incus 0.7)')
        ->assertSuccessful();
});

it('outputs text summary for unreachable host', function (): void {
    $host = testHost(e2eConfig('sidecar1'), [
        'incus version' => processResult(false),
    ]);

    $pool = new IncusHostPool([$host]);

    $command = app(E2EPreflightCommand::class);
    $command->setHostPoolFactory(fn () => $pool);
    $this->app->instance(E2EPreflightCommand::class, $command);

    $this->artisan('e2e:preflight')
        ->expectsOutputToContain('FAIL sidecar1: incus version check failed')
        ->assertFailed();
});

it('returns failure with clear message for empty host pool', function (): void {
    $pool = new IncusHostPool([]);

    $command = app(E2EPreflightCommand::class);
    $command->setHostPoolFactory(fn () => $pool);
    $this->app->instance(E2EPreflightCommand::class, $command);

    $this->artisan('e2e:preflight')
        ->expectsOutputToContain('No Incus hosts configured')
        ->assertFailed();
});

it('returns json error for empty host pool', function (): void {
    $pool = new IncusHostPool([]);

    $command = app(E2EPreflightCommand::class);
    $command->setHostPoolFactory(fn () => $pool);
    $this->app->instance(E2EPreflightCommand::class, $command);

    $expected = json_encode([
        'error' => [
            'code' => 'preflight_failed',
            'message' => 'No Incus hosts configured',
        ],
    ], JSON_THROW_ON_ERROR);

    $this->artisan('e2e:preflight', ['--json' => true])
        ->expectsOutput($expected)
        ->assertFailed();
});
