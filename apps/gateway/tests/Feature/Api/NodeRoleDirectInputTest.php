<?php

declare(strict_types=1);

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleDirectInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use Tests\Support\Fleet\FleetFixtures;

it('captures a public role snapshot without account probes effects or private bytes', function (RoleName $role): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('role-native', [$role]);
    $node->forceFill(['agent_secret_hash' => str_repeat('e', 64)])->save();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run', 'install');
    app()->instance(AgentCommandRunner::class, $agents);
    $accounts = Mockery::mock(ManagedUserAccountResolver::class);
    $accounts->shouldNotReceive('resolve');
    app()->instance(ManagedUserAccountResolver::class, $accounts);

    $input = app(NodeRoleDirectInput::class)->capture($node, $node->roles->sole(), false);

    expect($input['node_id'])->toBe($node->id)->and($input['role'])->toBe($role->value)
        ->and($input['footprint']['node_id'])->toBe($node->id)
        ->and($input['footprint'])->not->toHaveKey('secret_hash')
        ->and($input['prerequisites'])->not->toHaveKeys(['user', 'group', 'home'])
        ->and(json_encode($input, JSON_THROW_ON_ERROR))->not->toContain(str_repeat('e', 64));
})->with([RoleName::Database, RoleName::CiRunner]);

it('refuses a foreign assignment before capturing its role payload', function (): void {
    FleetFixtures::bind();
    $node = FleetFixtures::node('owner', [RoleName::Database]);
    $foreign = FleetFixtures::node('foreign');
    expect(fn () => app(NodeRoleDirectInput::class)->capture($foreign, $node->roles->sole(), false))
        ->toThrow(ResourceOperationException::class);
});
