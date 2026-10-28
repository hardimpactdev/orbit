<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeFootprintOperationExecution;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function footprintContextOperation(array $artifacts, array $children, array $payloads = []): Operation
{
    $node = FleetFixtures::node('footprint-context');
    $intents = [];
    foreach ($children as $name => &$child) {
        $child['node_id'] = $node->id;
        $intents[] = ['key' => 'footprint-'.$name, ...$child];
    }
    unset($child);

    return Operation::query()->create(['kind' => 'node.converge', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => ['force_artifacts' => false, 'first_visit' => false, '_footprint' => [
            'artifacts' => array_map(static fn (string $name): array => ['name' => $name, 'digest' => 'digest-'.$name, 'required' => true, 'repair' => false], $artifacts),
            'children' => $children, 'payloads' => $payloads,
        ]], 'state' => ['node_footprint_prepared' => true, 'child_intents' => $intents], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
}

function footprintContextReceipt(Operation $operation, string $key, string $transport, string $name, array $result): void
{
    $operation->update(['state' => [...$operation->state, 'executor_runs' => [...($operation->state['executor_runs'] ?? []),
        $key => ['transport' => $transport, 'name' => $name, 'result' => $result]]]]);
}

function footprintContextChild(Operation $parent, string $key, string $status, array $result): Operation
{
    $intent = array_find($parent->state['child_intents'], static fn (array $intent): bool => $intent['key'] === $key);
    $identity = ['parent_operation_id' => $parent->id, 'key' => $key, 'kind' => $intent['kind'], 'node_id' => $intent['node_id'],
        'target_type' => 'node', 'target_id' => $intent['node_id'], 'input' => $intent['input'], 'run_constraints' => []];
    $snapshot = ['intent' => $intent, 'admitted' => $identity];

    $child = new Operation;
    $child->forceFill(['kind' => $intent['kind'], 'node_id' => $intent['node_id'], 'target_type' => 'node', 'target_id' => $intent['node_id'],
        'input' => $intent['input'], 'parent_operation_id' => $parent->id, 'child_key' => $key, 'child_admission' => $snapshot,
        'child_fingerprint' => OperationChildIntent::fingerprint($snapshot), 'run_constraints' => [],
        'status' => $status, 'result' => $result, 'sequence' => 1, 'attempts' => 1])->save();

    return $child;
}

it('withholds private DNS publication until listener installation is proven', function (string $variant): void {
    $operation = footprintContextOperation(['private-dns'], ['private-dns' => ['kind' => 'route.private_dns_publish', 'input' => ['reason' => 'footprint']]],
        ['private-dns-listener' => ['release_id' => 'staged']]);
    if ($variant !== 'absent') {
        footprintContextReceipt($operation, 'footprint-private-dns-listener', $variant === 'wrong-transport' ? 'gateway' : 'agent', 'node.install-private-dns-listener',
            $variant === 'failed' ? ['error_code' => 'node.private_dns_failed'] : ['installed' => $variant === 'proven']);
    }

    $context = app(NodeFootprintOperationExecution::class)->childContext($operation);

    expect(array_column($context['children'], 'key'))->toBe($variant === 'proven' ? ['footprint-private-dns'] : []);
})->with(['absent', 'wrong-transport', 'failed', 'unproven', 'proven']);

it('keeps null residue binding and prior child intents after re-entry', function (): void {
    $operation = footprintContextOperation(['caddy', 'route-residue'], [
        'caddy' => ['kind' => 'route.caddy_converge', 'input' => ['reason' => 'footprint']],
        'route-residue' => ['kind' => 'route.offline_residue_clean', 'input' => ['removed_node_id' => null]],
    ]);
    footprintContextChild($operation, 'footprint-caddy', 'failed', ['stage' => 'render']);

    $context = app(NodeFootprintOperationExecution::class)->childContext($operation->fresh());

    expect(array_column($context['children'], 'key'))->toBe(['footprint-caddy', 'footprint-route-residue']);
    expect($context['children'][1]['input'])->toBe(['removed_node_id' => null]);
});

it('does not expose later children after a hard failure or a pending child', function (string $status): void {
    $operation = footprintContextOperation(['caddy', 'route-residue'], [
        'caddy' => ['kind' => 'route.caddy_converge', 'input' => ['reason' => 'footprint']],
        'route-residue' => ['kind' => 'route.offline_residue_clean', 'input' => ['removed_node_id' => null]],
    ]);
    footprintContextChild($operation, 'footprint-caddy', $status, ['stage' => 'reload']);

    $context = app(NodeFootprintOperationExecution::class)->childContext($operation);

    expect(array_column($context['children'], 'key'))->toBe(['footprint-caddy']);
})->with(['failed', 'running', 'waiting']);

it('refuses a different stored child input even on the same Node', function (): void {
    $operation = footprintContextOperation(['route-residue'], [
        'route-residue' => ['kind' => 'route.offline_residue_clean', 'input' => ['removed_node_id' => null]],
    ]);
    $state = $operation->state;
    $state['child_intents'][0]['input']['removed_node_id'] = $operation->node_id;
    $operation->update(['state' => $state]);

    expect(fn () => app(NodeFootprintOperationExecution::class)->childContext($operation))->toThrow(ResourceOperationException::class);
});

it('withholds agent configuration until the installer receipt is proven', function (): void {
    $operation = footprintContextOperation(['agent'], [], ['agent' => ['secret_hash' => ['$secret' => 'node.agent_secret_hash', 'id' => 1]]]);
    $execution = app(NodeFootprintOperationExecution::class);
    expect(array_column($execution->context($operation)['descriptors'], 'key'))->toBe(['prepare-footprint', 'footprint-agent-install', 'footprint-result']);
    footprintContextReceipt($operation, 'footprint-agent-install', 'node-agent', 'agent.install', ['installed' => true, 'changed' => true]);
    expect(array_column($execution->context($operation)['descriptors'], 'key'))->toBe(['prepare-footprint', 'footprint-agent-install', 'prepare-agent-configuration', 'footprint-result']);
    footprintContextReceipt($operation, 'prepare-agent-configuration', 'gateway', 'node.prepare-agent-configuration', ['prepared' => true]);
    expect(array_column($execution->context($operation)['descriptors'], 'key'))->toBe(['prepare-footprint', 'footprint-agent-install', 'prepare-agent-configuration', 'footprint-agent', 'footprint-result']);
});
