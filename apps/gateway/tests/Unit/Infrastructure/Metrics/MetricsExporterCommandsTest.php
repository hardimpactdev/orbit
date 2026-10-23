<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Metrics\AgentMetricsCadvisorRuntime;
use App\Infrastructure\Metrics\AgentMetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterCommands;
use App\Infrastructure\Metrics\MetricsExporterState;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('sends one complete exporter action and the recorded Node to the agent', function (string $kind, string $operation): void {
    $command = $kind === 'exporter' ? 'metrics.node-exporter' : 'metrics.cadvisor';
    $agents = new FakeAgentCommandRunner()->answer($command, ['changed' => true]);
    $commands = new MetricsExporterCommands($agents, new CommandDeadline);
    $runtime = $kind === 'exporter' ? new AgentMetricsExporterRuntime($commands) : new AgentMetricsCadvisorRuntime($commands);
    $node = new Node(['name' => 'workload', 'wireguard_ip' => '10.44.0.4', 'user' => 'deployer']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3']);
    $state = new MetricsExporterState('opaque saved configuration', true, '10.44.0.2');

    if ($operation === 'restore') {
        $runtime->restore($node, $metrics, $state);
    } else {
        $runtime->{$operation}($node, $metrics);
    }

    expect($agents->calls)->toBe([[
        'node' => 'workload', 'command' => $command,
        'input' => [
            'address' => '10.44.0.4', 'metrics_address' => '10.44.0.3',
            'action' => $operation === 'restore'
                ? ['operation' => 'restore', 'state' => ['configuration' => 'opaque saved configuration', 'service_active' => true, 'firewall_source' => '10.44.0.2']]
                : ['operation' => $operation],
        ],
    ]]);
})->with(['exporter', 'cadvisor'])->with(['converge', 'remove', 'restore']);

it('retains the Node snapshot for fleet rollback without interpreting its configuration', function (string $command): void {
    $agents = new FakeAgentCommandRunner()->answer($command, ['configuration' => 'opaque snapshot', 'service_active' => true, 'firewall_source' => '10.44.0.2']);
    $commands = new MetricsExporterCommands($agents, new CommandDeadline);
    $node = new Node(['wireguard_ip' => '10.44.0.4']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3']);

    $state = $commands->snapshot($node, $metrics, $command);

    expect($state->toAgent())->toBe(['configuration' => 'opaque snapshot', 'service_active' => true, 'firewall_source' => '10.44.0.2']);
    expect($agents->calls[0]['input']['action'])->toBe(['operation' => 'snapshot']);
})->with(['metrics.node-exporter', 'metrics.cadvisor']);

it('preserves typed refusals and maps lost results to exporter unavailability', function (string $kind, bool $transport): void {
    $command = $kind === 'exporter' ? 'metrics.node-exporter' : 'metrics.cadvisor';
    $agents = new FakeAgentCommandRunner;
    if ($transport) {
        $agents->answer($command, fn () => throw new AgentRunnerFailure($command, AgentRunnerFailure::Transport, 'unreachable'));
    } else {
        $agents->refuse($command, 'metrics.'.$kind.'_firewall_ownership_drift', ['status' => 409]);
    }
    $commands = new MetricsExporterCommands($agents, new CommandDeadline);
    $node = new Node(['wireguard_ip' => '10.44.0.4']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3']);

    try {
        $commands->snapshot($node, $metrics, $command);
        $this->fail('Expected a structured refusal.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('metrics.'.$kind.($transport ? '_configuration_inspection_failed' : '_firewall_ownership_drift'));
        expect($exception->status)->toBe($transport ? 502 : 409);
    }
    expect($agents->calls)->toHaveCount(1);
})->with(['exporter', 'cadvisor'])->with([true, false]);

it('refuses malformed snapshots before fleet mutation', function (array $data): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.node-exporter', $data);
    $commands = new MetricsExporterCommands($agents, new CommandDeadline);
    $node = new Node(['wireguard_ip' => '10.44.0.4']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3']);

    expect(fn () => $commands->snapshot($node, $metrics, 'metrics.node-exporter'))->toThrow(ResourceOperationException::class, 'invalid result');
    expect($agents->commands())->toBe(['metrics.node-exporter']);
})->with([
    'missing fields' => [[]],
    'invalid service' => [['configuration' => null, 'service_active' => 'active', 'firewall_source' => null]],
    'invalid source' => [['configuration' => null, 'service_active' => false, 'firewall_source' => '10.44.0.0/24']],
]);

it('refuses an invalid address without sending a command', function (bool $metricsInvalid): void {
    $agents = new FakeAgentCommandRunner;
    $commands = new MetricsExporterCommands($agents, new CommandDeadline);
    $node = new Node(['wireguard_ip' => $metricsInvalid ? '10.44.0.4' : 'bad']);
    $metrics = new Node(['wireguard_ip' => $metricsInvalid ? 'bad' : '10.44.0.3']);

    expect(fn () => $commands->snapshot($node, $metrics, 'metrics.node-exporter'))->toThrow(ResourceOperationException::class, 'IPv4');
    expect($agents->calls)->toBe([]);
})->with([true, false]);

it('reports exporter observations and keeps failed observations unknown', function (string $actual): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.node-exporter', ['actual' => $actual]);
    $runtime = new AgentMetricsExporterRuntime(new MetricsExporterCommands($agents, new CommandDeadline));
    $node = new Node(['wireguard_ip' => '10.44.0.4']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3']);

    expect($runtime->actual($node, $metrics))->toBe($actual === 'invalid' ? 'unknown' : $actual);
    expect($agents->calls[0]['input']['action'])->toBe(['operation' => 'actual']);
})->with(['active', 'inactive', 'drift', 'unknown', 'invalid']);

it('keeps exporter execution and firewall decisions in the agent', function (): void {
    foreach (['AgentMetricsExporterRuntime', 'AgentMetricsCadvisorRuntime', 'MetricsExporterCommands'] as $name) {
        $source = file_get_contents(app_path('Infrastructure/Metrics/'.$name.'.php'));
        expect($source)->not->toContain('SshExecutor', 'RemoteCommand', 'ProcessRunner', 'UfwStatusParser', 'systemctl', '<<<');
    }
});
