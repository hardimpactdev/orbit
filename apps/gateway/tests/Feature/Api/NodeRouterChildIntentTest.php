<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Domain\Nodes\NodeRoleOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationExecution;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Cluster;
use App\Models\NodeRole;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function routerParentFixture(): array
{
    $cluster = Cluster::query()->create(['name' => 'router-parent']);
    $candidate = FleetFixtures::node('router-candidate');
    $source = FleetFixtures::node('router-source');
    $candidate->update(['cluster_id' => $cluster->id]);
    $source->update(['cluster_id' => $cluster->id]);
    $assignment = NodeRole::query()->create(['node_id' => $candidate->id, 'cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => 'provisioning']);
    $old = NodeRole::query()->create(['node_id' => $source->id, 'cluster_id' => $cluster->id, 'role' => RoleName::Router, 'status' => 'removing']);
    $parent = Operation::query()->create(['kind' => 'cluster_router.set', 'target_type' => 'cluster_router', 'target_id' => $cluster->id,
        'node_id' => $candidate->id, 'input' => ['cluster_id' => $cluster->id, 'candidate_role_id' => $assignment->id, 'node_id' => $candidate->id,
            'old_role_id' => $old->id, 'old_node_id' => $source->id, 'route_ids' => [], 'node_ids' => [$candidate->id, $source->id]],
        'state' => ['route_plan' => ['assignment_ids' => [$assignment->id, $old->id], 'old_role_ids' => [$old->id],
            'candidate_role_id' => $assignment->id, 'node_ids' => [$candidate->id, $source->id]]], 'status' => 'running', 'sequence' => 1, 'attempts' => 1]);
    app()->instance(OperationKinds::class, new OperationKinds(app(), array_fill_keys(['node.role_converge', 'node.role_remove'], [
        'executor' => 'swamp', 'completion' => NodeRoleOperationCompletion::class, 'execution' => NodeRoleOperationExecution::class,
        'runs' => [], 'completion_contract' => ['step' => 'role-result', 'transport' => 'gateway', 'name' => 'node.role-result', 'result' => ['role_proven' => true]],
        'result_fields' => ['error_code', 'created', 'assignment_id', 'role_proven'],
    ])));

    return [$parent, $assignment, $old];
}

it('leaves a Router candidate Provisioning after a successful or failed role child', function (bool $succeeded): void {
    [$parent, $assignment] = routerParentFixture();
    $intent = ['key' => 'prepare-router', 'kind' => 'node.role_converge', 'node_id' => $assignment->node_id, 'input' => ['assignment_id' => $assignment->id]];
    $child = app(OperationIdempotency::class)->duringChild($parent, $intent,
        fn (): Operation => app(AdmitNodeRoleOperationAction::class)->routerChild($parent, $intent));
    $child->update(['input' => [...$child->input, '_role_plan' => []], 'state' => [...$child->state, 'node_role_prepared' => true]]);
    $outcome = $succeeded ? OperationOutcome::succeeded(app(NodeRoleOperationExecution::class)->result($child)) : OperationOutcome::failed('node_role.child_failed');

    app(NodeRoleOperationCompletion::class)->complete($child, $outcome);

    expect($assignment->refresh()->status->value)->toBe('provisioning');
    expect($child->state['role_owned_by_parent'])->toBeTrue();
    expect($child->state['role_intent'])->toMatchArray(['assignment_id' => $assignment->id, 'created' => false]);
})->with([true, false]);

it('leaves removal of an old Router assignment to its Cluster parent after effect proof', function (): void {
    [$parent, , $old] = routerParentFixture();
    $intent = ['key' => 'remove-old-router', 'kind' => 'node.role_remove', 'node_id' => $old->node_id,
        'input' => ['role' => 'router', 'purge_data' => false, 'offline' => false]];
    $child = app(OperationIdempotency::class)->duringChild($parent, $intent,
        fn (): Operation => app(AdmitNodeRoleOperationAction::class)->routerChild($parent, $intent));
    $child->update(['input' => [...$child->input, '_role_plan' => []], 'state' => [...$child->state, 'node_role_prepared' => true]]);

    app(NodeRoleOperationCompletion::class)->complete($child, OperationOutcome::succeeded(app(NodeRoleOperationExecution::class)->result($child)));

    expect($old->refresh()->status->value)->toBe('removing');
    $this->assertModelExists($old);
});

it('refuses Router role admission outside its exact recorded Cluster ownership', function (string $variant): void {
    [$parent, $assignment] = routerParentFixture();
    $intent = ['key' => 'prepare-router', 'kind' => 'node.role_converge', 'node_id' => $assignment->node_id, 'input' => ['assignment_id' => $assignment->id]];
    $state = $parent->state;
    if ($variant === 'missing-candidate') {
        $state['route_plan']['assignment_ids'] = [];
        $parent->update(['state' => $state]);
    } elseif ($variant === 'cluster') {
        $parent->update(['input' => [...$parent->input, 'cluster_id' => 12345]]);
    } elseif ($variant === 'target') {
        $intent['node_id'] = $parent->input['old_node_id'];
    } else {
        $parent->update(['target_type' => 'node']);
    }

    expect(fn () => app(OperationIdempotency::class)->duringChild($parent, $intent,
        fn (): Operation => app(AdmitNodeRoleOperationAction::class)->routerChild($parent, $intent)))
        ->toThrow(ResourceOperationException::class);

    expect(Operation::query()->where('parent_operation_id', $parent->id)->count())->toBe(0);
    expect($assignment->refresh()->status->value)->toBe('provisioning');
})->with(['missing-candidate', 'cluster', 'target', 'parent']);
