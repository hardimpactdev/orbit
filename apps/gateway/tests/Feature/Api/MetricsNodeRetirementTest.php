<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsReconcileAdmission;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->metrics = Node::query()->create(['name' => 'retirement-metrics', 'platform' => 'linux', 'status' => 'active',
        'wireguard_ip' => '10.44.0.20', 'public_ssh_host' => '192.0.2.20', 'user' => 'orbit', 'ssh_host_fingerprint' => 'fixture-pin']);
    $this->metrics->roles()->create(['role' => 'metrics', 'status' => 'active']);
});

it('captures online cleanup from actual removal reachability before Node deletion', function (bool $requestedOffline, bool $unreachable): void {
    $node = Node::query()->create(['name' => 'retiring', 'platform' => 'linux', 'status' => 'removing', 'wireguard_ip' => '10.44.0.18',
        'public_ssh_host' => '192.0.2.18', 'ssh_host_fingerprint' => 'fixture-pin']);
    $parent = new Operation(['kind' => 'node.remove', 'node_id' => $node->id, 'input' => ['offline' => $requestedOffline],
        'state' => ['removal_intent' => ['offline' => $unreachable]]]);

    $operation = app(MetricsReconcileAdmission::class)->admit($parent, ['kind' => 'metrics.reconcile', 'node_id' => $this->metrics->id, 'input' => []]);

    $retired = array_values(array_filter($operation->input['fleet'], static fn (array $candidate): bool => $candidate['node_id'] === $node->id));
    expect($retired)->toHaveCount($unreachable ? 0 : 1);
    if (! $unreachable) {
        expect($retired[0])->toMatchArray(['selected' => false, 'caddy' => false, 'fpm' => false]);
        expect($operation->input['fleet_node_ids'])->toContain($node->id);
    }
    expect($node->fresh()->status->value)->toBe('removing');
})->with([[true, true], [true, false], [false, false]]);

it('does not require exporter cleanup for an unmanaged or incomplete retiring peer', function (array $identity): void {
    $node = Node::query()->create(['name' => 'incomplete-retirement', 'platform' => 'linux', 'status' => 'removing', 'public_ssh_host' => '192.0.2.19', ...$identity]);
    $parent = new Operation(['kind' => 'node.remove', 'node_id' => $node->id, 'input' => [], 'state' => ['removal_intent' => ['offline' => false]]]);

    $operation = app(MetricsReconcileAdmission::class)->admit($parent, ['kind' => 'metrics.reconcile', 'node_id' => $this->metrics->id, 'input' => []]);

    expect($operation->input['fleet_node_ids'])->not->toContain($node->id);
    expect($node->fresh()->status->value)->toBe('removing');
})->with([
    ['identity' => []],
    ['identity' => ['wireguard_ip' => '10.44.0.19']],
    ['identity' => ['wireguard_ip' => 'incomplete-address', 'ssh_host_fingerprint' => 'fixture-pin']],
]);
