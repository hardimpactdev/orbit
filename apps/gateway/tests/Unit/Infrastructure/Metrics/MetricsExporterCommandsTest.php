<?php

declare(strict_types=1);

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\Metrics\AgentMetricsExporterRuntime;
use App\Infrastructure\Metrics\MetricsExporterCommands;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;
use Tests\TestCase;

uses(TestCase::class);

it('reports exporter observations and keeps failed observations unknown', function (string $actual): void {
    $agents = new FakeAgentCommandRunner()->answer('metrics.node-exporter', ['actual' => $actual]);
    $runtime = new AgentMetricsExporterRuntime(new MetricsExporterCommands($agents, new CommandDeadline));
    $node = new Node(['wireguard_ip' => '10.44.0.4']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3']);

    expect($runtime->actual($node, $metrics))->toBe($actual === 'invalid' ? 'unknown' : $actual);
    expect($agents->calls[0]['input']['action'])->toBe(['operation' => 'actual']);
})->with(['active', 'inactive', 'drift', 'unknown', 'invalid']);

it('preserves typed refusals and maps lost results to exporter unavailability', function (bool $transport): void {
    $command = 'metrics.node-exporter';
    $agents = new FakeAgentCommandRunner;
    if ($transport) {
        $agents->answer($command, fn () => throw new AgentRunnerFailure($command, AgentRunnerFailure::Transport, 'unreachable'));
    } else {
        $agents->refuse($command, 'metrics.exporter'.'_firewall_ownership_drift', ['status' => 409]);
    }
    $commands = new MetricsExporterCommands($agents, new CommandDeadline);
    $node = new Node(['wireguard_ip' => '10.44.0.4']);
    $metrics = new Node(['wireguard_ip' => '10.44.0.3']);

    try {
        $commands->run($node, $metrics);
        $this->fail('Expected a structured refusal.');
    } catch (ResourceOperationException $exception) {
        expect($exception->errorCode)->toBe('metrics.exporter'.($transport ? '_configuration_inspection_failed' : '_firewall_ownership_drift'));
        expect($exception->status)->toBe($transport ? 502 : 409);
    }
    expect($agents->calls)->toHaveCount(1);
})->with([true, false]);

it('refuses an invalid address without sending a command', function (bool $metricsInvalid): void {
    $agents = new FakeAgentCommandRunner;
    $commands = new MetricsExporterCommands($agents, new CommandDeadline);
    $node = new Node(['wireguard_ip' => $metricsInvalid ? '10.44.0.4' : 'bad']);
    $metrics = new Node(['wireguard_ip' => $metricsInvalid ? 'bad' : '10.44.0.3']);

    expect(fn () => $commands->run($node, $metrics))->toThrow(ResourceOperationException::class, 'IPv4');
    expect($agents->calls)->toBe([]);
})->with([true, false]);
