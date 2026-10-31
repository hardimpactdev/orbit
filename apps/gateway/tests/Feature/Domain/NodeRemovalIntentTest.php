<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRemovalInput;
use App\Domain\Nodes\NodeRemovalIntent;
use App\Domain\Nodes\NodeRemovalPlan;
use App\Domain\Nodes\RoleName;
use App\Domain\Routes\RouteStatus;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Project;
use App\Models\Route;
use Illuminate\Support\Facades\DB;
use Tests\Support\Fleet\FleetFixtures;

it('captures both removal publication states before dispatch without contacting participants', function (): void {
    FleetFixtures::bind();
    app()->instance(AgentCommandRunner::class, Mockery::mock(AgentCommandRunner::class));
    $root = FleetFixtures::node('intent-root', [RoleName::Gateway]);
    $hub = FleetFixtures::node('intent-hub', [RoleName::Vpn]);
    $target = FleetFixtures::node('intent-target', [RoleName::AppDev]);
    $target->update(['tld' => 'remove.example.test']);
    $peer = FleetFixtures::node('intent-peer', [RoleName::AppDev]);
    $peer->update(['wireguard_public_key' => base64_encode(str_repeat('p', 32))]);
    $snapshot = app(NodeRemovalPlan::class)->build($target, true);
    $execution = app(NodeRemovalInput::class)->capture($snapshot, $root);
    $input = ['_node_removal' => $execution['snapshot'], '_removal' => $execution,
        'route_removal' => $execution['routes']];

    $intent = DB::transaction(fn (): array => app(NodeRemovalIntent::class)->capture($target, $root, $input));

    expect($target->fresh()->status)->toBe(LifecycleStatus::Removing);
    expect(array_column($intent['participants'], 'node_id'))
        ->toBe(array_column($intent['restore_participants'], 'node_id'));
    $forward = collect($intent['participants'])->firstWhere('node_id', $hub->id);
    $restore = collect($intent['restore_participants'])->firstWhere('node_id', $hub->id);
    expect($forward['dns']['records'])->toBeString();
    expect($restore['dns']['records'])->toBeString();
    $forwardPeer = collect($intent['participants'])->firstWhere('node_id', $peer->id);
    $restorePeer = collect($intent['restore_participants'])->firstWhere('node_id', $peer->id);
    expect($forwardPeer['network']['peer_dns']['domains'])->not->toContain('remove.example.test');
    expect($restorePeer['network']['peer_dns']['domains'])->toContain('remove.example.test');
    expect($intent['snapshot'])->toBe($execution['snapshot']);
});

it('rolls back membership admission when its outer transaction does not commit', function (): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('intent-rollback-root', [RoleName::Gateway]);
    FleetFixtures::node('intent-rollback-hub', [RoleName::Vpn]);
    $target = FleetFixtures::node('intent-rollback-target', [RoleName::AppDev]);
    $snapshot = app(NodeRemovalPlan::class)->build($target, true);
    $execution = app(NodeRemovalInput::class)->capture($snapshot, $root);

    expect(fn () => DB::transaction(function () use ($target, $root, $execution): void {
        app(NodeRemovalIntent::class)->capture($target, $root, [
            '_node_removal' => $execution['snapshot'], '_removal' => $execution,
            'route_removal' => $execution['routes'],
        ]);
        throw new RuntimeException('admission rolled back');
    }))->toThrow(RuntimeException::class, 'admission rolled back');

    expect($target->fresh()->status)->toBe(LifecycleStatus::Active);
});

it('captures restoration before withdrawing Route records and preserves associations until the terminal result', function (): void {
    FleetFixtures::bind();
    $root = FleetFixtures::node('intent-route-root', [RoleName::Gateway]);
    FleetFixtures::node('intent-route-hub', [RoleName::Vpn]);
    $target = FleetFixtures::node('intent-route-target');
    $project = Project::query()->create(['name' => 'Intent route', 'slug' => 'intent-route', 'repository_url' => 'https://github.com/example/intent.git']);
    $route = Route::query()->create(['project_id' => $project->id, 'node_id' => $target->id,
        'domain' => 'intent-route.orbit', 'provenance' => 'explicit', 'publication' => 'private', 'status' => 'pending']);
    $snapshot = app(NodeRemovalPlan::class)->build($target, true);
    $execution = app(NodeRemovalInput::class)->capture($snapshot, $root);

    $intent = DB::transaction(fn (): array => app(NodeRemovalIntent::class)->capture($target, $root, [
        '_node_removal' => $execution['snapshot'], '_removal' => $execution, 'route_removal' => $execution['routes'],
    ]));

    expect($route->fresh()->status)->toBe(RouteStatus::Retiring);
    expect($intent['routes']['routes'][0]['route_id'])->toBe($route->id);
    $this->assertModelExists($route);
    $this->assertModelExists($target);
});
