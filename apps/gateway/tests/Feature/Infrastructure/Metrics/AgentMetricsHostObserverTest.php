<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsHostObserver;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use Tests\Support\FakeAgentCommandRunner;

it('reads exporter and container state using only observation commands', function (): void {
    $node = new Node(['name' => 'exporter', 'wireguard_ip' => '10.44.0.13']);
    $metrics = new Node(['name' => 'metrics', 'wireguard_ip' => '10.44.0.17']);
    $agents = new FakeAgentCommandRunner()->answer('metrics.exporter-state', ['actual' => 'active'])
        ->answer('metrics.runtime-health', ['healthy' => true]);
    app()->instance(AgentCommandRunner::class, $agents);
    $observer = app(MetricsHostObserver::class);

    expect($observer->actual($node, $metrics))->toBe('active')
        ->and($observer->health($metrics, 'grafana'))->toBeTrue();
    expect($agents->calls)->toBe([
        ['node' => 'exporter', 'command' => 'metrics.exporter-state', 'input' => ['address' => '10.44.0.13', 'metrics_address' => '10.44.0.17']],
        ['node' => 'metrics', 'command' => 'metrics.runtime-health', 'input' => ['service' => 'grafana']],
    ]);
});

it('reports refused observations as unknown without attempting repair', function (): void {
    $node = new Node;
    $agents = new FakeAgentCommandRunner()->refuse('metrics.exporter-state', 'agent.version_mismatch')
        ->refuse('metrics.runtime-health', 'agent.version_mismatch');
    app()->instance(AgentCommandRunner::class, $agents);
    $observer = app(MetricsHostObserver::class);

    expect($observer->actual($node, $node))->toBe('unknown')
        ->and($observer->health($node, 'prometheus'))->toBeFalse();
    expect(array_column($agents->calls, 'command'))->toBe(['metrics.exporter-state', 'metrics.runtime-health']);
});

it('rejects unknown services without a host call', function (): void {
    $agents = new FakeAgentCommandRunner;
    app()->instance(AgentCommandRunner::class, $agents);

    expect(app(MetricsHostObserver::class)->health(new Node, 'unmanaged'))->toBeFalse();
    expect($agents->calls)->toBeEmpty();
});
