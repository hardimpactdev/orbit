<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsFleetChildren;
use App\Domain\Metrics\MetricsFleetExecution;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

beforeEach(function (): void {
    $this->nodes = [];
    foreach (['metrics', 'app'] as $name) {
        $this->nodes[] = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.19']);
    }
    $fleet = array_map(static fn (Node $node): array => ['node_id' => $node->id, 'name' => $node->name, 'address' => '10.44.0.'.$node->id,
        'selected' => true, 'caddy' => false, 'fpm' => false, 'hosts' => [], 'instance_ids' => []], $this->nodes);
    $this->parent = Operation::query()->create(['kind' => 'metrics.reconcile', 'target_type' => 'metrics', 'target_id' => $this->nodes[0]->id,
        'node_id' => $this->nodes[0]->id, 'status' => 'running', 'input' => ['fleet' => $fleet, 'node_id' => $this->nodes[0]->id,
            'assignment_id' => 77, 'metrics_address' => '10.44.0.'.$this->nodes[0]->id, 'gateway_node_id' => null, 'gateway_address' => null,
            'dns_node_id' => null, 'remove' => false, 'unreachable' => false, 'purge_data' => false, 'relocated' => false]]);
});

function recordMetricsChild(Operation $parent, array $intent, string $status = 'succeeded', ?string $error = null): Operation
{
    $child = new Operation(['kind' => $intent['kind'], 'target_type' => 'metrics_effect', 'target_id' => $intent['node_id'],
        'node_id' => $intent['node_id'], 'status' => $status, 'input' => $intent['input'], 'error_code' => $error]);
    $child->forceFill(['parent_operation_id' => $parent->id, 'child_key' => $intent['key'], 'run_constraints' => []])->save();
    $snapshot = ['intent' => $intent, 'admitted' => ['parent_operation_id' => $parent->id, 'key' => $intent['key'],
        'kind' => $intent['kind'], 'node_id' => $intent['node_id'], 'target_type' => $child->target_type, 'target_id' => $child->target_id,
        'input' => $child->input, 'run_constraints' => []]];
    $child->forceFill(['child_admission' => $snapshot, 'child_fingerprint' => OperationChildIntent::fingerprint($snapshot)])->save();

    return $child;
}

function metricsChildIntents(Operation $parent): array
{
    return app(MetricsFleetChildren::class)->context($parent);
}

it('exposes all snapshots before sequential mutations and no runtime or publication early', function (): void {
    $intents = metricsChildIntents($this->parent);
    expect(array_column($intents, 'kind'))->toBe(['metrics.node_exporter_snapshot', 'metrics.node_exporter_snapshot']);
    recordMetricsChild($this->parent, $intents[0]);
    expect(metricsChildIntents($this->parent))->toBe($intents);
    recordMetricsChild($this->parent, $intents[1]);
    $next = metricsChildIntents($this->parent);
    expect(array_column($next, 'kind'))->toBe(['metrics.node_exporter_snapshot', 'metrics.node_exporter_snapshot', 'metrics.node_exporter_converge']);
    recordMetricsChild($this->parent, $next[2]);
    $next = metricsChildIntents($this->parent);
    expect(array_column($next, 'kind'))->toBe(['metrics.node_exporter_snapshot', 'metrics.node_exporter_snapshot', 'metrics.node_exporter_converge', 'metrics.node_exporter_converge']);
});

it('does not offer fleet rollback for an accepted unavailable member while the next snapshot is pending', function (): void {
    foreach (metricsChildIntents($this->parent) as $intent) {
        recordMetricsChild($this->parent, $intent);
    }
    recordMetricsChild($this->parent, array_values(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')))[0]);
    $second = array_values(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')))[1];
    recordMetricsChild($this->parent, $second);
    $snapshots = array_values(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => $intent['kind'] === 'metrics.cadvisor_snapshot'));
    recordMetricsChild($this->parent, $snapshots[1], 'failed', 'metrics.cadvisor_service_inspection_failed');
    $next = metricsChildIntents($this->parent);
    expect(array_filter($next, static fn (array $intent): bool => str_contains($intent['key'], '-restore-')))->toBe([]);
    expect(array_column($next, 'kind'))->not->toContain('metrics.runtime_converge');
});

it('binds rollback to the successful snapshot of the same parent and refuses tampered child proof', function (): void {
    foreach (metricsChildIntents($this->parent) as $intent) {
        recordMetricsChild($this->parent, $intent);
    }
    $apply = array_values(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')))[0];
    $failed = recordMetricsChild($this->parent, $apply, 'failed', 'metrics.install_refused');
    $restore = array_values(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-restore-')))[0];
    $snapshot = $this->parent->children()->where('child_key', $restore['input']['source_key'])->sole();
    expect($restore['input']['source_operation_id'])->toBe($snapshot->id)
        ->and($restore['node_id'])->toBe($snapshot->node_id);
    $failed->update(['input' => ['address' => '10.99.0.1']]);
    expect(fn () => metricsChildIntents($this->parent))->toThrow(ResourceOperationException::class);
});

it('renders the preference-only receipt without a fleet remove field', function (): void {
    $operation = new Operation(['kind' => 'metrics.exporter_enable', 'input' => ['preference_only' => true]]);
    expect(app(MetricsFleetExecution::class)->context($operation)['descriptors'][0]['input'])->toBe(['backend' => 'active']);
});
