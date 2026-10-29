<?php

declare(strict_types=1);

use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationFamilySpec;
use App\Domain\Operations\OperationKinds;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    if (! OperationFamilySpec::available('tool.install')) {
        $this->markTestSkipped('Tools require shared Kit B/C enforcement.');
    }
    Queue::fake();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
    $this->node = Node::query()->create([
        'name' => 'manager-child', 'status' => LifecycleStatus::Provisioning, 'platform' => 'linux',
        'architecture' => 'x86_64', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.22',
    ]);
    $this->parent = new Operation(['kind' => 'node.add', 'node_id' => null]);
});

it('records manager children from narrow intent without running commands', function (string $manager, bool $imageProvidesManager, ?string $materialize): void {
    $admission = app(OperationKinds::definitions()['tool.manager_converge']['admission']);
    expect($admission)->toBeInstanceOf(OperationChildAdmission::class);

    $child = $admission->admit($this->parent, [
        'key' => 'manager-'.$manager, 'kind' => 'tool.manager_converge', 'node_id' => $this->node->id,
        'input' => ['manager' => $manager, 'image_provides_manager' => $imageProvidesManager],
    ]);

    expect($child->kind)->toBe('tool.manager_converge')
        ->and($child->target_type)->toBe('node_tools')->and($child->target_id)->toBe($this->node->id)
        ->and($child->node_id)->toBe($this->node->id)->and($child->input['node_id'])->toBe($this->node->id)
        ->and($child->input['manager'])->toBe($manager)->and($child->input['completion_variant'])->toBe($manager.':version')
        ->and($child->input['materialize_command'] ?? null)->toBe($materialize)
        ->and($child->input)->not->toHaveKey('parent_operation_id');
    $this->assertDatabaseCount('operations', 1);
    $this->assertDatabaseCount('tools', 0);
    Queue::assertNothingPushed();
})->with([
    ['apt', false, null], ['vp', false, 'tool.materialize-vp'], ['composer', false, 'tool.materialize-composer'],
    ['vp', true, null], ['composer', true, null],
]);

it('keeps the recorded recipe immutable after Node details change', function (): void {
    $child = app(OperationKinds::definitions()['tool.manager_converge']['admission'])->admit($this->parent, [
        'key' => 'manager-vp', 'kind' => 'tool.manager_converge', 'node_id' => $this->node->id,
        'input' => ['manager' => 'vp', 'image_provides_manager' => false],
    ]);
    $this->node->update(['user' => 'another-account']);

    expect($child->refresh()->input['user'])->toBe('orbit')
        ->and($child->input['vp_lock'])->toBeString()->not->toBeEmpty()
        ->and($child->input['tools'])->toHaveKeys(['node', 'vp-cli', 'vp', 'pnpm']);
});

it('rejects malformed or expanded manager intent before recording an Operation', function (array $input): void {
    $admission = app(OperationKinds::definitions()['tool.manager_converge']['admission']);

    expect(fn () => $admission->admit($this->parent, [
        'key' => 'manager-vp', 'kind' => 'tool.manager_converge', 'node_id' => $this->node->id, 'input' => $input,
    ]))->toThrow(ResourceOperationException::class);

    $this->assertDatabaseCount('operations', 0);
    Queue::assertNothingPushed();
})->with([
    [['manager' => 'vp']],
    [['image_provides_manager' => false]],
    [['manager' => 'npm', 'image_provides_manager' => false]],
    [['manager' => ['vp'], 'image_provides_manager' => false]],
    [['manager' => 'vp', 'image_provides_manager' => 'false']],
    [['manager' => 'vp', 'image_provides_manager' => null]],
    [['manager' => 'vp', 'image_provides_manager' => false, 'node_id' => 99]],
    [['manager' => 'vp', 'image_provides_manager' => false, 'parent_operation_id' => 99]],
    [['manager' => 'vp', 'image_provides_manager' => false, 'materialize_command' => 'tool.materialize-composer']],
    [['manager' => 'vp', 'image_provides_manager' => false, 'package' => 'is-number']],
]);

it('requires a stored child Node and the manager kind', function (string $kind, ?int $nodeId): void {
    $admission = app(OperationKinds::definitions()['tool.manager_converge']['admission']);

    expect(fn () => $admission->admit($this->parent, [
        'key' => 'manager-vp', 'kind' => $kind, 'node_id' => $nodeId,
        'input' => ['manager' => 'vp', 'image_provides_manager' => false],
    ]))->toThrow(ResourceOperationException::class);

    $this->assertDatabaseCount('operations', 0);
})->with([['tool.manager_converge', null], ['tool.manager_converge', 2147483647], ['tool.install', null]]);

it('retains the owning action platform policy', function (): void {
    $this->node->update(['platform' => 'macos', 'architecture' => 'arm64']);

    expect(fn () => app(OperationKinds::definitions()['tool.manager_converge']['admission'])->admit($this->parent, [
        'key' => 'manager-apt', 'kind' => 'tool.manager_converge', 'node_id' => $this->node->id,
        'input' => ['manager' => 'apt', 'image_provides_manager' => false],
    ]))->toThrow(LogicException::class, 'The requested Tool manager does not support this Node.');

    $this->assertDatabaseCount('operations', 0);
});
