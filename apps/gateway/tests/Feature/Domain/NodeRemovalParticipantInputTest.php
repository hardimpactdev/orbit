<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\NodeRemovalPublicInput;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Operation;
use App\Models\RouteRemovalResidue;
use Tests\Support\Fleet\FleetFixtures;

it('captures fresh serving network residue and DNS after TaskVM provider deletion without contacting the guest', function (): void {
    FleetFixtures::bind();
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    $root = FleetFixtures::node('removal-participant-root', [RoleName::Gateway]);
    $hub = FleetFixtures::node('removal-participant-hub', [RoleName::Vpn]);
    RouteRemovalResidue::query()->create(['node_id' => $hub->id, 'route_id' => 987654, 'domain' => 'gone.orbit', 'steps' => ['caddy']]);
    $guest = FleetFixtures::taskVm();
    $guest->update(['status' => LifecycleStatus::Removing]);
    $snapshot = app(NodeRemovalPlan::class)->build($guest, true, true);
    $input = app(NodeRemovalInput::class)->capture($snapshot, executor: $root);
    expect($input['retirement'])->toBeNull()->and($input['snapshot']['machine_deleted'])->toBeTrue();
    $view = app(NodeRemovalPublicInput::class)->participant($snapshot, $hub);
    expect($view['node_id'])->toBe($hub->id)->and($view['removal_node_id'])->toBe($guest->id)
        ->and($view['network'])->toBeNull()->and($view['residue'])->toBeArray()
        ->and($view['dns']['records'])->toBeString()->and($view['dns']['catalog'])->toBeString()
        ->and($view['route_removal'])->toBeNull();
    expect(fn () => app(NodeRemovalPublicInput::class)->participant($snapshot, $guest))->toThrow(ResourceOperationException::class);
    $hub->update(['user' => 'changedowner']);
    $restored = app(NodeRemovalPublicInput::class)->participant($snapshot, $hub->fresh(), true);
    expect($restored['residue']['user'])->toBe('changedowner');
    expect(NodeRemovalInput::privateOwner(new Operation(['kind' => 'task_vm.destroy', 'target_type' => 'task_vm'])))->toBeNull();
    $this->assertModelExists($guest);
});
