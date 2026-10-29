<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeEnrollmentContext;
use App\Domain\Operations\OperationChildIntent;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

function enrollmentContextChild(Operation $parent, Node $node, string $kind, string $key): Operation
{
    $intent = ['key' => $key, 'kind' => $kind, 'node_id' => $node->id, 'input' => []];
    $identity = ['parent_operation_id' => $parent->id, 'key' => $key, 'kind' => $kind, 'node_id' => $node->id,
        'target_type' => 'node', 'target_id' => $node->id, 'input' => [], 'run_constraints' => []];
    $snapshot = ['intent' => $intent, 'admitted' => $identity];
    $operation = new Operation;
    $operation->forceFill(['kind' => $kind, 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'input' => [], 'parent_operation_id' => $parent->id, 'child_key' => $key, 'child_admission' => $snapshot,
        'child_fingerprint' => OperationChildIntent::fingerprint($snapshot), 'run_constraints' => [], 'status' => 'running', 'sequence' => 1])->save();

    return $operation;
}

it('renders nested role effects for the candidate account while keeping the stored prior account', function (): void {
    $node = FleetFixtures::node('candidate-account');
    $parent = Operation::query()->create(['kind' => 'node.add', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'input' => ['managed_user' => 'candidate'], 'state' => ['enrollment_intent' => ['id' => $node->id, 'candidate' => ['user' => 'candidate']]], 'status' => 'running', 'sequence' => 1]);
    $role = enrollmentContextChild($parent, $node, 'node.role_add', 'add-role-app-dev');
    $tool = enrollmentContextChild($role, $node, 'tool.manager_converge', 'role-manager-vp');
    $context = app(NodeEnrollmentContext::class);

    expect($context->node($role)->user)->toBe('candidate');
    expect($context->node($tool)->user)->toBe('candidate');
    expect($context->forChild($role, $node)->user)->toBe('candidate');
    expect($node->fresh()->user)->toBe('orbit');
    $other = FleetFixtures::node('candidate-serving');
    $serving = enrollmentContextChild($role, $other, 'metrics.reconcile', 'role-metrics');
    expect($context->node($serving)->user)->toBe('orbit');
});

it('refuses an altered enrollment account or ancestry before selecting a candidate identity', function (bool $alterAccount): void {
    $node = FleetFixtures::node('candidate-refusal');
    $parent = Operation::query()->create(['kind' => 'node.add', 'node_id' => $node->id, 'target_type' => 'node', 'target_id' => $node->id,
        'input' => ['managed_user' => 'candidate'], 'state' => ['enrollment_intent' => ['id' => $node->id, 'candidate' => ['user' => $alterAccount ? 'replacement' : 'candidate']]], 'status' => 'running', 'sequence' => 1]);
    $role = enrollmentContextChild($parent, $node, 'node.role_add', 'add-role-app-dev');
    if (! $alterAccount) {
        $role->update(['target_id' => $node->id + 1]);
    }
    expect(fn () => app(NodeEnrollmentContext::class)->node($role))->toThrow(ResourceOperationException::class);
    expect($node->fresh()->user)->toBe('orbit');
})->with([false, true]);
