<?php

declare(strict_types=1);

use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ETopologyKind;
use App\E2E\Support\IncusHost;
use App\E2E\Support\IncusInstance;
use App\E2E\Support\IncusTopologyBuilder;
use App\E2E\Support\SshKeyPair;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
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

it('refreshes the reused gateway checkout before dedicated ingress baking', function (): void {
    $commands = [];

    Process::fake([
        'wg genkey' => Process::result(output: "private-key\n"),
        'wg pubkey' => Process::result(output: "public-key\n"),
    ]);

    $host = m::mock(IncusHost::class, [E2EConfig::fromEnvironment()])->makePartial();
    $host->shouldReceive('startInstance')->andReturn(dedicatedIngressBakeResult());
    $host->shouldReceive('launchTopologyInstance')->andReturn(dedicatedIngressBakeResult());
    $host->shouldReceive('run')
        ->andReturnUsing(function (string $command, ?int $timeoutSeconds = null) use (&$commands): ProcessResult {
            $commands[] = $command;

            if (str_contains($command, 'docker exec wg-easy wg show wg0 public-key')) {
                return dedicatedIngressBakeResult("wg-easy-public-key\n");
            }

            if (str_contains($command, 'orbit-template-app-dev-base') && str_contains($command, '/state')) {
                return dedicatedIngressBakeResult("10.201.0.12\n");
            }

            if (str_contains($command, 'orbit-template-app-prod-base') && str_contains($command, '/state')) {
                return dedicatedIngressBakeResult("10.201.0.13\n");
            }

            if (str_contains($command, 'orbit-template-ingress-base') && str_contains($command, '/state')) {
                return dedicatedIngressBakeResult("10.201.0.14\n");
            }

            if (str_contains($command, 'orbit-template-gateway-base') && str_contains($command, '/state')) {
                return dedicatedIngressBakeResult("10.201.0.11\n");
            }

            if (str_contains($command, 'orbit-template-operator-base') && str_contains($command, '/state')) {
                return dedicatedIngressBakeResult("10.201.0.10\n");
            }

            return dedicatedIngressBakeResult();
        });

    $builder = new IncusTopologyBuilder($host);
    $builder->useBundle('/tmp/orbit-e2e-bundle-test');

    $method = new ReflectionMethod(IncusTopologyBuilder::class, 'buildPreparedDedicatedIngressStage');
    $method->setAccessible(true);
    $method->invoke($builder, new SshKeyPair('/tmp/orbit-e2e-key', '/tmp/orbit-e2e-key.pub'));

    $commandOutput = implode("\n", $commands);
    $overlayPosition = strpos($commandOutput, "incus file push -r -p '/tmp/orbit-e2e-bundle-test'");
    $bakePosition = strpos($commandOutput, '/tmp/orbit-e2e-prepared-dedicated-ingress-bake.sh');

    expect($overlayPosition)->not->toBeFalse()
        ->and($bakePosition)->not->toBeFalse()
        ->and($overlayPosition)->toBeLessThan($bakePosition);
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
