<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyBuilder;
use Illuminate\Contracts\Process\ProcessResult;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function dedicatedIngressBakeResult(string $output = ''): ProcessResult
{
    $result = m::mock(ProcessResult::class);
    $result->shouldReceive('successful')->andReturn(true);
    $result->shouldReceive('output')->andReturn($output);
    $result->shouldReceive('errorOutput')->andReturn('');

    return $result;
}

it('bakes dedicated ingress before app production references it', function (): void {
    $commands = [];
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            return dedicatedIngressBakeResult();
        });

    $builder = new IncusTopologyBuilder($host);
    $gateway = new IncusInstance($host, 'orbit-template-gateway-base', commandTransport: true);
    $method = new ReflectionMethod(IncusTopologyBuilder::class, 'runPreparedDedicatedIngressBakeInParallel');
    $method->setAccessible(true);

    $method->invoke($builder, $gateway, '10.201.0.12', '10.201.0.13', '10.201.0.14');

    $script = $commands[0];

    expect($script)->toContain('orbit:internal:bake-ingress-node')
        ->and($script)->toContain('orbit:internal:bake-app-node')
        ->and(strpos($script, 'wait "$PID_BAKE_INGRESS"'))->toBeLessThan(strpos($script, 'PID_BAKE_PROD=$!'))
        ->and(strpos($script, 'PID_BAKE_PROD=$!'))->toBeLessThan(strpos($script, 'wait "$PID_BAKE_PROD"'));
});

it('limits reusable base snapshot deletion to the target topology chain', function (): void {
    $deleted = [];
    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $host->shouldReceive('deleteSnapshot')
        ->andReturnUsing(function (string $template, string $snapshot) use (&$deleted): ProcessResult {
            $deleted[] = "{$template}/{$snapshot}";

            return dedicatedIngressBakeResult();
        });

    $builder = new IncusTopologyBuilder($host);
    $method = new ReflectionMethod(IncusTopologyBuilder::class, 'deleteSnapshotsAfterReusableBase');
    $method->setAccessible(true);

    $method->invoke(
        $builder,
        E2ETopologyKind::OperatorGatewayAppdevAppprodIngress,
        E2ETopologyKind::OperatorGatewayAppdevAppprodAgent,
    );

    expect($deleted)->toBe([
        'orbit-template-operator-base/clean-operator_gateway_app-dev_app-prod_ingress-base',
        'orbit-template-gateway-base/clean-operator_gateway_app-dev_app-prod_ingress-base',
        'orbit-template-app-dev-base/clean-operator_gateway_app-dev_app-prod_ingress-base',
        'orbit-template-app-prod-base/clean-operator_gateway_app-dev_app-prod_ingress-base',
    ])->not->toContain('orbit-template-operator-base/clean-operator_gateway_app-dev_app-prod_agent_websocket-base');
});
