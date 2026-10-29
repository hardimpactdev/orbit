<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeEnrollmentContext;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerChildAdmission;
use App\Domain\Tools\ToolOperationAvailability;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    if (! class_exists(NodeEnrollmentContext::class)) {
        $this->markTestSkipped('Requires the Nodes family enrollment context.');
    }
    Queue::fake();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->node = Node::query()->create([
        'name' => 'candidate-manager', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'architecture' => 'x86_64', 'user' => 'prior', 'public_ssh_host' => '192.0.2.22',
    ]);
    $this->parent = new Operation([
        'kind' => 'node.add', 'target_type' => 'node', 'target_id' => $this->node->id, 'node_id' => $this->node->id,
        'input' => ['managed_user' => 'candidate'],
        'state' => ['enrollment_intent' => ['id' => $this->node->id, 'candidate' => ['user' => 'candidate']]],
    ]);
});

it('records the candidate account for manager children while preserving the prior Node account', function (string $manager): void {
    if (! ToolOperationAvailability::available()) {
        $this->markTestSkipped('Requires shared Kit C enforcement.');
    }

    $child = app(ToolManagerChildAdmission::class)->admit($this->parent, [
        'key' => 'manager-'.$manager, 'kind' => 'tool.manager_converge', 'node_id' => $this->node->id,
        'input' => ['manager' => $manager, 'image_provides_manager' => false],
    ]);

    expect($child->input['user'])->toBe('candidate')
        ->and($child->node_id)->toBe($this->node->id)->and($child->target_id)->toBe($this->node->id)
        ->and($this->node->fresh()->user)->toBe('prior');
    Queue::assertNothingPushed();
})->with(['vp', 'composer']);

it('rejects a mismatched candidate account before recording manager intent', function (string $manager): void {
    $this->parent->state = ['enrollment_intent' => ['id' => $this->node->id, 'candidate' => ['user' => 'altered']]];

    try {
        app(ToolManagerChildAdmission::class)->admit($this->parent, [
            'key' => 'manager-'.$manager, 'kind' => 'tool.manager_converge', 'node_id' => $this->node->id,
            'input' => ['manager' => $manager, 'image_provides_manager' => false],
        ]);
        $this->fail('The candidate must match admitted enrollment intent.');
    } catch (ResourceOperationException $error) {
        expect($error->errorCode)->toBe('operation.child_unproven');
    }

    expect($this->node->fresh()->user)->toBe('prior');
    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with(['vp', 'composer']);

it('keeps another serving Nodes own account in its manager recipe', function (string $manager): void {
    if (! ToolOperationAvailability::available()) {
        $this->markTestSkipped('Requires shared Kit C enforcement.');
    }
    $serving = Node::query()->create([
        'name' => 'other-serving-manager', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'architecture' => 'x86_64', 'user' => 'serving', 'public_ssh_host' => '192.0.2.23',
    ]);

    $child = app(ToolManagerChildAdmission::class)->admit($this->parent, [
        'key' => 'manager-'.$manager, 'kind' => 'tool.manager_converge', 'node_id' => $serving->id,
        'input' => ['manager' => $manager, 'image_provides_manager' => false],
    ]);

    expect($child->input['user'])->toBe('serving')
        ->and($this->node->fresh()->user)->toBe('prior')->and($serving->fresh()->user)->toBe('serving');
})->with(['vp', 'composer']);
