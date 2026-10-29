<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCaddyRestoreExecution;
use App\Domain\Metrics\MetricsEffectAdmission;
use App\Domain\Metrics\MetricsFleetChildren;
use App\Domain\Metrics\MetricsFleetExecution;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->nodes = [];
    foreach (['metrics', 'app'] as $name) {
        $this->nodes[] = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '192.0.2.19']);
    }
    $fleet = array_map(static fn (Node $node): array => ['node_id' => $node->id, 'name' => $node->name, 'address' => '10.44.0.'.$node->id,
        'selected' => true, 'caddy' => false, 'fpm' => false, 'hosts' => [], 'instance_ids' => []], $this->nodes);
    $this->parent = Operation::query()->create(['kind' => 'metrics.reconcile', 'target_type' => 'metrics', 'target_id' => $this->nodes[0]->id,
        'node_id' => $this->nodes[0]->id, 'status' => 'running', 'input' => ['fleet' => $fleet, 'node_id' => $this->nodes[0]->id,
            'fleet_node_ids' => array_column($fleet, 'node_id'), 'assignment_id' => 77, 'metrics_address' => '10.44.0.'.$this->nodes[0]->id, 'gateway_node_id' => null, 'gateway_address' => null,
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
    recordMetricsChild($this->parent, array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-'))));
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
    $apply = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')));
    $failed = recordMetricsChild($this->parent, $apply, 'failed', 'metrics.install_refused');
    $restore = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-restore-')));
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

/** Registers the fleet contract while the unrelated list-result capability remains gated. */
function registerMetricsCompensationContract(): void
{
    $definition = OperationKinds::definitions()['metrics.service_caddy_restore'];
    $definition['execution'] = MetricsFleetExecution::class;
    $definition['compensations'] = ['metrics.node_exporter_restore', 'metrics.service_caddy_restore'];
    $definition['cancellation_boundary'] = 'metrics-cancellation-boundary';
    $definition['children'] = array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'input.fleet_node_ids'],
        ['metrics.node_exporter_snapshot', 'metrics.node_exporter_converge', 'metrics.node_exporter_restore', 'metrics.service_caddy_restore']);
    app()->instance(OperationKinds::class, new OperationKinds(app(), [...OperationKinds::definitions(), 'metrics.reconcile' => $definition]));
}

it('admits only snapshot-bound compensation after cancellation and fences forward children', function (): void {
    registerMetricsCompensationContract();
    $snapshots = metricsChildIntents($this->parent);
    expect($snapshots[0]['key'])->toBe('metrics-cancellation-boundary');
    foreach ($snapshots as $intent) {
        recordMetricsChild($this->parent, $intent);
    }
    $apply = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')));
    recordMetricsChild($this->parent, $apply);
    $this->parent->update(['cancel_requested_at' => now()]);

    $restore = metricsChildIntents($this->parent);
    expect(array_column($restore, 'key'))->toBe(['node_exporter-restore-'.$this->nodes[0]->id]);
    $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($this->parent, ['key' => $restore[0]['key']])['operation']);

    expect($child->input['source_key'])->toBe('metrics-cancellation-boundary')
        ->and($child->input['source_operation_id'])->toBe($this->parent->children()->where('child_key', 'metrics-cancellation-boundary')->sole()->id);
    app(OperationChildren::class)->assertRun($child);
    $forward = 'node_exporter-apply-'.$this->nodes[1]->id;
    expect(fn () => DB::transaction(fn () => app(OperationChildren::class)->admit($this->parent, ['key' => $forward])))
        ->toThrow(ResourceOperationException::class, 'The child key is not eligible');
    expect($this->parent->children()->where('child_key', $forward)->exists())->toBeFalse();
});

it('refuses compensation when the serving snapshot boundary is not successful', function (string $status): void {
    registerMetricsCompensationContract();
    $snapshots = metricsChildIntents($this->parent);
    $boundary = recordMetricsChild($this->parent, $snapshots[0]);
    recordMetricsChild($this->parent, $snapshots[1]);
    // The first mutation must settle before the second becomes eligible.
    $first = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')));
    recordMetricsChild($this->parent, $first);
    $apply = array_values(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')))[1];
    recordMetricsChild($this->parent, $apply);
    $boundary->update(['status' => $status]);
    $this->parent->update(['cancel_requested_at' => now()]);
    $key = 'node_exporter-restore-'.$this->nodes[1]->id;

    expect(fn () => DB::transaction(fn () => app(OperationChildren::class)->admit($this->parent, ['key' => $key])))
        ->toThrow(ResourceOperationException::class, 'The parent was cancelled');
    expect($this->parent->children()->where('child_key', $key)->exists())->toBeFalse();
})->with(['running', 'failed']);

it('captures a Caddy restoration child only after same-parent same-Node service restoration settles', function (string $status): void {
    registerMetricsCompensationContract();
    foreach (metricsChildIntents($this->parent) as $intent) {
        recordMetricsChild($this->parent, $intent);
    }
    $apply = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')));
    recordMetricsChild($this->parent, $apply, 'failed', 'metrics.install_refused');
    $key = 'service-restore-'.$this->nodes[0]->id;
    recordMetricsChild($this->parent, ['key' => $key, 'kind' => 'metrics.service_restore', 'node_id' => $this->nodes[0]->id, 'input' => []], $status);
    // The wrapper's eligibility is derived from a terminal service apply and its proven snapshot.
    recordMetricsChild($this->parent, ['key' => 'service-snapshot-'.$this->nodes[0]->id, 'kind' => 'metrics.service_snapshot', 'node_id' => $this->nodes[0]->id, 'input' => []]);
    recordMetricsChild($this->parent, ['key' => 'service-apply-'.$this->nodes[0]->id, 'kind' => 'metrics.service_converge', 'node_id' => $this->nodes[0]->id, 'input' => []], 'failed', 'metrics.service_failed');
    $this->parent->update(['cancel_requested_at' => now()]);

    $child = DB::transaction(fn (): Operation => app(OperationChildren::class)->admit($this->parent, ['key' => 'service-caddy-restore-'.$this->nodes[0]->id])['operation']);
    $plan = app(MetricsCaddyRestoreExecution::class)->childContext($child);

    expect($plan['children'])->toBe([['key' => 'service-caddy-restore', 'kind' => 'route.caddy_converge', 'node_id' => $this->nodes[0]->id, 'input' => ['reason' => 'metrics service rollback']]]);
    app(OperationChildren::class)->assertRun($child);
    $nested = recordMetricsChild($child, $plan['children'][0], 'running');
    app(OperationChildren::class)->assertRun($nested);
    expect($child->input['restore_key'])->toBe($key);
})->with(['succeeded', 'failed']);

it('refuses service Caddy rollback without terminal same-parent same-Node restoration', function (string $case): void {
    $node = $this->nodes[0];
    $intent = ['key' => 'service-restore-'.$node->id, 'kind' => $case === 'kind' ? 'metrics.node_exporter_restore' : 'metrics.service_restore',
        'node_id' => $case === 'node' ? $this->nodes[1]->id : $node->id, 'input' => []];
    $sourceParent = $case === 'parent' ? Operation::query()->create(['kind' => 'metrics.reconcile', 'target_type' => 'metrics', 'target_id' => $this->nodes[1]->id,
        'node_id' => $this->nodes[1]->id, 'status' => 'running', 'input' => []]) : $this->parent;
    recordMetricsChild($sourceParent, $intent, $case === 'pending' ? 'running' : 'succeeded');
    $count = Operation::query()->count();

    expect(fn () => app(MetricsEffectAdmission::class)->admit($this->parent, ['key' => 'service-caddy-restore-'.$node->id,
        'kind' => 'metrics.service_caddy_restore', 'node_id' => $node->id, 'input' => ['restore_key' => $intent['key']]]))
        ->toThrow(ResourceOperationException::class);
    expect(Operation::query()->count())->toBe($count);
})->with(['pending', 'node', 'parent', 'kind']);

it('requires exact compensation before failed or cancelled fleet completion', function (string $outcome): void {
    $snapshots = metricsChildIntents($this->parent);
    foreach ($snapshots as $intent) {
        recordMetricsChild($this->parent, $intent);
    }
    $apply = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')));
    recordMetricsChild($this->parent, $apply);
    $this->parent->update(['cancel_requested_at' => now()]);
    $execution = app(MetricsFleetExecution::class);
    $result = $outcome === 'failed' ? OperationOutcome::failed('metrics.install_failed') : OperationOutcome::cancelled();

    expect(fn () => $execution->assertCompletion($this->parent, $result))->toThrow(ResourceOperationException::class);
    $restore = metricsChildIntents($this->parent)[0];
    $child = recordMetricsChild($this->parent, $restore, 'running');
    expect(fn () => $execution->assertCompletion($this->parent, $result))->toThrow(ResourceOperationException::class);
    $child->update(['status' => 'succeeded']);
    $execution->assertCompletion($this->parent, $result);
    $child->update(['input' => [...$child->input, 'source_operation_id' => 999]]);
    expect(fn () => $execution->assertCompletion($this->parent, $result))->toThrow(ResourceOperationException::class);
})->with(['failed', 'cancelled']);

it('requires a proven restore refusal before accepting rollback failure', function (): void {
    foreach (metricsChildIntents($this->parent) as $intent) {
        recordMetricsChild($this->parent, $intent);
    }
    $apply = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')));
    recordMetricsChild($this->parent, $apply, 'failed', 'metrics.install_failed');
    $execution = app(MetricsFleetExecution::class);
    $result = OperationOutcome::failed('metrics.rollback_failed');
    expect(fn () => $execution->assertCompletion($this->parent, $result))->toThrow(ResourceOperationException::class);
    $restore = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-restore-')));
    recordMetricsChild($this->parent, $restore, 'failed', 'metrics.restore_refused');

    $execution->assertCompletion($this->parent, $result);

    expect(fn () => $execution->assertCompletion($this->parent, OperationOutcome::failed('metrics.install_failed')))->toThrow(ResourceOperationException::class);
});

it('requires service and Caddy restoration before cancellation can finish', function (): void {
    $node = $this->nodes[0];
    $input = ['address' => '10.44.0.'.$node->id, 'metrics_address' => $this->parent->input['metrics_address'], 'instance_ids' => [], 'caddy' => false, 'fpm' => false];
    $sourceKey = 'service-snapshot-'.$node->id;
    $snapshot = recordMetricsChild($this->parent, ['key' => $sourceKey, 'kind' => 'metrics.service_snapshot', 'node_id' => $node->id, 'input' => $input]);
    recordMetricsChild($this->parent, ['key' => 'service-apply-'.$node->id, 'kind' => 'metrics.service_converge', 'node_id' => $node->id, 'input' => $input]);
    $key = 'service-restore-'.$node->id;
    recordMetricsChild($this->parent, ['key' => $key, 'kind' => 'metrics.service_restore', 'node_id' => $node->id,
        'input' => [...$input, 'source_operation_id' => $snapshot->id, 'source_key' => $sourceKey, 'restore_key' => $key]]);
    $execution = app(MetricsFleetExecution::class);
    $result = OperationOutcome::cancelled();

    expect(fn () => $execution->assertCompletion($this->parent, $result))->toThrow(ResourceOperationException::class);
    recordMetricsChild($this->parent, ['key' => 'service-caddy-restore-'.$node->id, 'kind' => 'metrics.service_caddy_restore', 'node_id' => $node->id, 'input' => ['restore_key' => $key]]);
    $execution->assertCompletion($this->parent, $result);
});

it('preserves a committed healthy Metrics runtime after a later cancellation', function (): void {
    foreach (metricsChildIntents($this->parent) as $intent) {
        recordMetricsChild($this->parent, $intent);
    }
    $apply = array_first(array_filter(metricsChildIntents($this->parent), static fn (array $intent): bool => str_contains($intent['key'], '-apply-')));
    recordMetricsChild($this->parent, $apply);
    recordMetricsChild($this->parent, ['key' => 'metrics-runtime', 'kind' => 'metrics.runtime_converge', 'node_id' => $this->nodes[0]->id, 'input' => []]);

    app(MetricsFleetExecution::class)->assertCompletion($this->parent, OperationOutcome::cancelled());

    expect($this->parent->children()->where('kind', 'like', '%_restore')->count())->toBe(0);
});

it('accepts an early service restore refusal before later component restores are attempted', function (): void {
    foreach ($this->parent->input['fleet'] as $node) {
        $addresses = ['address' => $node['address'], 'metrics_address' => $this->parent->input['metrics_address']];
        foreach (['node_exporter', 'cadvisor', 'service'] as $component) {
            $input = $component === 'service' ? [...$addresses, 'instance_ids' => [], 'caddy' => false, 'fpm' => false] : $addresses;
            $sourceKey = $component === 'node_exporter' && $node['node_id'] === $this->parent->node_id ? 'metrics-cancellation-boundary' : $component.'-snapshot-'.$node['node_id'];
            $source = recordMetricsChild($this->parent, ['key' => $sourceKey, 'kind' => 'metrics.'.$component.'_snapshot', 'node_id' => $node['node_id'], 'input' => $input]);
            recordMetricsChild($this->parent, ['key' => $component.'-apply-'.$node['node_id'], 'kind' => 'metrics.'.$component.'_converge', 'node_id' => $node['node_id'], 'input' => $input]);
            if ($component !== 'service') {
                continue;
            }
            $key = 'service-restore-'.$node['node_id'];
            $failed = $node['node_id'] === $this->nodes[0]->id;
            recordMetricsChild($this->parent, ['key' => $key, 'kind' => 'metrics.service_restore', 'node_id' => $node['node_id'],
                'input' => [...$input, 'source_operation_id' => $source->id, 'source_key' => $sourceKey, 'restore_key' => $key]], $failed ? 'failed' : 'succeeded', $failed ? 'metrics.service_restore_failed' : null);
            if (! $failed) {
                recordMetricsChild($this->parent, ['key' => 'service-caddy-restore-'.$node['node_id'], 'kind' => 'metrics.service_caddy_restore', 'node_id' => $node['node_id'], 'input' => ['restore_key' => $key]]);
            }
        }
    }

    app(MetricsFleetExecution::class)->assertCompletion($this->parent, OperationOutcome::failed('metrics.rollback_failed'));

    expect($this->parent->children()->where('kind', 'metrics.cadvisor_restore')->exists())->toBeFalse();
});

it('proves the whole captured fleet before allowing the Metrics receipt', function (): void {
    $execution = app(MetricsFleetExecution::class);
    $run = ['key' => 'integration-receipt'];
    expect(fn () => $execution->beforeRun($this->parent, $run))->toThrow(ResourceOperationException::class);
    for ($stage = 0; $stage < 20; $stage++) {
        $created = 0;
        foreach (metricsChildIntents($this->parent) as $intent) {
            if (! $this->parent->children()->where('child_key', $intent['key'])->exists()) {
                recordMetricsChild($this->parent, $intent);
                $created++;
            }
        }
        if ($created === 0) {
            break;
        }
    }

    $execution->beforeRun($this->parent, $run);
    $execution->assertCompletion($this->parent, OperationOutcome::succeeded(['backend' => 'active']));

    expect($this->parent->children()->where('child_key', 'metrics-credentials-verified')->exists())->toBeTrue();
    expect($this->parent->children()->where('child_key', 'like', 'publication-%')->exists())->toBeFalse();
    $runtime = $this->parent->children()->where('child_key', 'metrics-runtime')->sole();
    $runtime->update(['input' => [...$runtime->input, 'assignment_id' => 999]]);
    expect(fn () => $execution->beforeRun($this->parent, $run))->toThrow(ResourceOperationException::class);
});
