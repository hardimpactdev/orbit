<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeFootprintOperationExecution;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Log;
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
            'agent_pin' => str_repeat('a', 64), 'children' => $children, 'payloads' => $payloads,
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

it('clears a failed agent pin only when the bound installer receipt proves replacement', function (bool $proven): void {
    $operation = footprintContextOperation(['agent'], [], ['agent' => []]);
    Node::query()->whereKey($operation->node_id)->update(['agent_failed_pin' => str_repeat('a', 64)]);
    $execution = app(NodeFootprintOperationExecution::class);
    $run = ['key' => 'footprint-agent-install', 'transport' => 'node-agent', 'name' => 'agent.install',
        'input' => ['pin' => str_repeat('a', 64)], 'worker' => 'footprint-worker'];
    $receipt = $proven ? ['installed' => true, 'changed' => true] : ['error_code' => 'agent.install_failed'];

    $execution->received($operation, $run, $receipt);

    expect(Node::query()->findOrFail($operation->node_id)->agent_failed_pin)->toBe($proven ? null : str_repeat('a', 64));
})->with([false, true]);

it('binds PHP inputs and withholds later versions and artifacts until proof', function (): void {
    $inputs = array_map(static fn (string $version): array => ['version' => $version, 'runtime_ini' => 'rendered', 'release' => ['id' => 'ubuntu', 'codenames' => ['resolute']]], ['8.4', '8.5']);
    $operation = footprintContextOperation(['php-runtime', 'tmpfiles'], [], ['php-runtime' => ['configurations' => $inputs], 'tmpfiles' => ['rule' => 'owned']]);
    $execution = app(NodeFootprintOperationExecution::class);
    $contract = app(OperationExecutionContract::class);
    $run = ['worker' => 'worker', 'key' => 'footprint-php-runtime-8.4', 'transport' => 'agent', 'name' => 'php.configure-runtime', 'input' => $inputs[0]];
    expect($contract->authorizeRun($operation, $run)['name'])->toBe('php.configure-runtime');
    foreach (['version' => '8.5', 'runtime_ini' => 'unowned', 'release' => ['id' => 'other', 'codenames' => ['resolute']]] as $field => $value) {
        expect(fn () => $contract->authorizeRun($operation, [...$run, 'input' => [...$inputs[0], $field => $value]]))->toThrow(ResourceOperationException::class);
    }
    expect(fn () => $contract->authorizeRun($operation, [...$run, 'key' => 'footprint-php-runtime-8.5', 'input' => $inputs[1]]))->toThrow(ResourceOperationException::class);
    foreach ([['changed' => false], ['error_code' => 'php.runtime_configuration_failed'], []] as $result) {
        footprintContextReceipt($operation, 'footprint-php-runtime-8.4', 'agent', 'php.configure-runtime', $result);
        $keys = array_column($execution->context($operation)['descriptors'], 'key');
        expect(in_array('footprint-php-runtime-8.5', $keys, true))->toBe(isset($result['changed']));
        expect($keys)->not->toContain('footprint-tmpfiles');
    }
    footprintContextReceipt($operation, 'footprint-php-runtime-8.4', 'agent', 'php.configure-runtime', ['changed' => false]);
    footprintContextReceipt($operation, 'footprint-php-runtime-8.5', 'agent', 'php.configure-runtime', ['changed' => true]);
    expect(array_column($execution->context($operation)['descriptors'], 'key'))->toContain('footprint-tmpfiles');
});

it('keeps deferred PHP masters in sanitized results and logs their warning', function (): void {
    $input = ['version' => '8.5', 'runtime_ini' => 'rendered', 'release' => ['id' => 'ubuntu', 'codenames' => ['resolute']]];
    $operation = footprintContextOperation(['php-runtime'], [], ['php-runtime' => ['configurations' => [$input]]]);
    $run = ['worker' => 'worker', 'key' => 'footprint-php-runtime-8.5', 'transport' => 'agent', 'name' => 'php.configure-runtime', 'input' => $input];
    $receipt = ['changed' => true, 'reloaded' => false, 'deferred' => ['orbit-php-instance-42.service']];
    Log::spy();
    expect(app(NodeFootprintOperationExecution::class)->received($operation, $run, $receipt))->toBe($receipt);
    expect(app(OperationExecutionContract::class)->result($operation, [...$receipt, 'unlisted' => 'private']))->toBe($receipt);
    Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context['node_id'] === $operation->node_id && $context['masters'] === $receipt['deferred']);
});

it('refuses a saved listener run on a Gateway resolver client before any Node effect', function (string $roleStatus): void {
    $operation = footprintContextOperation(['private-dns'], ['private-dns' => ['kind' => 'route.private_dns_publish', 'input' => ['reason' => 'footprint']]],
        ['private-dns-listener' => ['release_id' => 'staged']]);
    $node = Node::query()->findOrFail($operation->node_id);
    $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    if ($roleStatus !== 'absent') {
        $node->roles()->create(['role' => RoleName::Vpn, 'status' => $roleStatus]);
    }
    $execution = app(NodeFootprintOperationExecution::class);
    $descriptor = array_find($execution->context($operation)['descriptors'], static fn (array $run): bool => $run['name'] === 'node.install-private-dns-listener');
    if ($roleStatus === 'active') {
        $execution->beforeRun($operation, $descriptor);
        expect($operation->fresh()->state['executor_runs'] ?? [])->toBe([]);
    } else {
        expect(fn () => $execution->beforeRun($operation, $descriptor))
            ->toThrow(fn (ResourceOperationException $error) => expect($error->errorCode)->toBe('node.private_dns_owner_changed'));
    }
})->with(['absent', 'provisioning', 'active']);
