<?php

declare(strict_types=1);

use App\Actions\Nodes\AdmitNodeRoleOperationAction;
use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\NodeChildAdmission;
use App\Domain\Nodes\NodeRoleOperationCompletion;
use App\Domain\Nodes\NodeRoleOperationExecution;
use App\Domain\Nodes\NodeRoleRelocationSourceCompletion;
use App\Domain\Nodes\PrepareNodeRoleCommand;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\TransferNodeRoleCommand;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildren;
use App\Domain\Operations\OperationKinds;
use App\Domain\Operations\OperationOutcome;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use Tests\Support\Fleet\FleetFixtures;

final readonly class RelocationRuntimeAdmission implements OperationChildAdmission
{
    public function admit(Operation $parent, array $intent): Operation
    {
        return app(StartOperationAction::class)->execute($intent['kind'], 'node', $intent['node_id'], $intent['node_id'], $intent['input'], 'test', null);
    }
}

function relocationOperationFixture(): array
{
    $definition = ['executor' => 'swamp', 'execution' => NodeRoleOperationExecution::class, 'completion' => NodeRoleOperationCompletion::class,
        'admission' => NodeChildAdmission::class, 'runs' => [],
        'children' => array_map(static fn (string $kind): array => ['kind' => $kind, 'node_from' => 'intent.node_ids'],
            ['node.role_relocate_source', 'node.role_relocate_target', 'websocket.role_remove']),
        'completion_contract' => ['step' => 'role-result', 'transport' => 'gateway', 'name' => 'node.role-result', 'result' => ['role_proven' => true]],
        'result_fields' => ['error_code', 'role_proven', 'assignment_id', 'created', 'role_wait_until']];
    $definitions = array_fill_keys(['node.role_relocate', 'node.role_relocate_source', 'node.role_relocate_target', 'node.role_add'], $definition);
    $definitions['websocket.role_remove'] = [...$definition, 'admission' => RelocationRuntimeAdmission::class];
    app()->instance(OperationKinds::class, new OperationKinds(app(), $definitions));
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldReceive('run')->withArgs(static fn (Node $node, string $name): bool => $name === 'node.os-release')->andReturn(['id' => 'ubuntu', 'codename' => 'resolute']);
    $agents->shouldReceive('run')->withArgs(static fn (Node $node, string $name): bool => $name === 'account.lookup')->andReturn(['user' => 'orbit', 'group' => 'orbit', 'home' => '/home/orbit']);
    app()->instance(AgentCommandRunner::class, $agents);
    $source = FleetFixtures::node('relocation-proof-source', [RoleName::WebSocket]);
    $target = FleetFixtures::node('relocation-proof-target');
    $parent = app(AdmitNodeRoleOperationAction::class)->relocate($target, RoleName::WebSocket, true);
    app(PrepareNodeRoleCommand::class)->run($parent, ['role' => 'websocket', 'converge' => true]);
    $parent->update(['status' => 'running']);
    $sourceOperation = app(OperationChildren::class)->admit($parent, ['key' => 'role-relocation-source'])['operation'];
    $sourceOperation->update(['status' => 'running']);
    $targetOperation = app(OperationChildren::class)->admit($sourceOperation, ['key' => 'role-relocation-target'])['operation'];

    return [$parent, $sourceOperation, $targetOperation, $source, $target];
}

it('holds both Nodes and binds the original role intent through destination and source stages', function (): void {
    [$parent, $sourceOperation, $targetOperation, $source, $target] = relocationOperationFixture();

    expect($parent->lock_key)->toBe('node:'.$target->id);
    expect($sourceOperation->lock_key)->toBe('node:'.$source->id);
    expect($targetOperation->lock_key)->toBeNull();
    expect($targetOperation->state['role_intent'])->toBe($parent->state['role_intent']);
    expect($source->roles()->sole()->node_id)->toBe($source->id);
    foreach ([$source, $target] as $node) {
        expect(fn () => app(StartOperationAction::class)->execute('node.role_add', 'node', $node->id, $node->id, [], 'test', null))
            ->toThrow(ResourceOperationException::class, 'Another operation');
    }
    app(OperationChildren::class)->verify($parent, $sourceOperation->fresh());
    app(OperationChildren::class)->verify($sourceOperation, $targetOperation->fresh());
});

it('copies existing role credentials and transfers the stable assignment once without deleting source Settings', function (): void {
    [$parent, $sourceOperation, $targetOperation, $source, $target] = relocationOperationFixture();
    $settings = app(SettingRepository::class);
    $sourceScope = new SettingScope(SettingScopeType::Node, $source->id);
    $targetScope = new SettingScope(SettingScopeType::Node, $target->id);
    $settings->put($sourceScope, 'websocket.reverb.laravel_app_key', 'source-role-private', SettingValueProtection::Secret);

    $result = app(TransferNodeRoleCommand::class)->run($targetOperation, []);
    $replayed = app(TransferNodeRoleCommand::class)->run($targetOperation->fresh(), []);

    expect($result)->toBe($replayed);
    expect($source->roles()->count())->toBe(0);
    expect($target->roles()->sole()->id)->toBe($parent->state['role_intent']['assignment_id']);
    expect($settings->get($sourceScope, 'websocket.reverb.laravel_app_key'))->toBe('source-role-private');
    expect($settings->get($targetScope, 'websocket.reverb.laravel_app_key'))->toBe('source-role-private');
    app(OperationChildren::class)->verify($sourceOperation, $targetOperation->fresh());
});

it('preserves an existing destination credential during a repeated transfer', function (): void {
    [, , $targetOperation, $source, $target] = relocationOperationFixture();
    $settings = app(SettingRepository::class);
    $settings->put(new SettingScope(SettingScopeType::Node, $source->id), 'websocket.reverb.laravel_app_key', 'source-private', SettingValueProtection::Secret);
    $settings->put(new SettingScope(SettingScopeType::Node, $target->id), 'websocket.reverb.laravel_app_key', 'existing-target-private', SettingValueProtection::Secret);

    app(TransferNodeRoleCommand::class)->run($targetOperation, []);

    expect($settings->get(new SettingScope(SettingScopeType::Node, $target->id), 'websocket.reverb.laravel_app_key'))->toBe('existing-target-private');
});

it('waits for proven destination publication and DNS expiry before admitting source withdrawal', function (): void {
    $this->freezeTime();
    [, $sourceOperation, $targetOperation, $source, $target] = relocationOperationFixture();
    $settings = app(SettingRepository::class);
    $settings->put(new SettingScope(SettingScopeType::Node, $source->id), 'websocket.reverb.laravel_app_key', 'retained-private', SettingValueProtection::Secret);
    app(TransferNodeRoleCommand::class)->run($targetOperation, []);
    $targetOperation->update(['status' => 'succeeded', 'finished_at' => now()]);
    expect(fn () => app(OperationChildren::class)->admit($sourceOperation, ['key' => 'role-runtime']))
        ->toThrow(ResourceOperationException::class, 'not eligible');
    expect(fn () => app(NodeRoleRelocationSourceCompletion::class)->complete($sourceOperation, OperationOutcome::succeeded([
        'role_proven' => true, 'assignment_id' => $sourceOperation->state['role_intent']['assignment_id'], 'created' => false,
    ])))->toThrow(ResourceOperationException::class);
    $this->assertDatabaseHas('settings', ['scope_id' => $source->id, 'key' => 'websocket.reverb.laravel_app_key']);
    $this->travel(32)->seconds();
    $runtime = app(OperationChildren::class)->admit($sourceOperation->fresh(), ['key' => 'role-runtime'])['operation'];
    $runtime->update(['status' => 'succeeded', 'finished_at' => now()]);

    app(NodeRoleRelocationSourceCompletion::class)->complete($sourceOperation->fresh(), OperationOutcome::succeeded(app(NodeRoleOperationExecution::class)->result($sourceOperation->fresh())));

    $this->assertDatabaseMissing('settings', ['scope_id' => $source->id, 'key' => 'websocket.reverb.laravel_app_key']);
    $this->assertDatabaseHas('settings', ['scope_id' => $target->id, 'key' => 'websocket.reverb.laravel_app_key']);
    expect($target->roles()->sole()->status->value)->toBe('active');
});

it('keeps source Settings and assignment state when a destination stage fails', function (): void {
    [$parent, $sourceOperation, $targetOperation, $source] = relocationOperationFixture();
    $settings = app(SettingRepository::class);
    $settings->put(new SettingScope(SettingScopeType::Node, $source->id), 'websocket.reverb.laravel_app_key', 'retained-private', SettingValueProtection::Secret);

    app(NodeRoleOperationCompletion::class)->complete($targetOperation, OperationOutcome::failed('websocket.publication_failed'));
    app(NodeRoleRelocationSourceCompletion::class)->complete($sourceOperation, OperationOutcome::failed('websocket.publication_failed'));
    app(NodeRoleOperationCompletion::class)->complete($parent, OperationOutcome::failed('websocket.publication_failed'));

    expect($source->roles()->sole()->status->value)->toBe('active');
    $this->assertDatabaseHas('settings', ['scope_id' => $source->id, 'key' => 'websocket.reverb.laravel_app_key']);
});
