<?php

declare(strict_types=1);

use App\Domain\Instances\ContainerModelRoute;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Orbit\Extensions\AiRouter\Actions\DisableAiRouterAction;
use Orbit\Extensions\AiRouter\Domain\AiRouterSecrets;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;

uses(LazilyRefreshDatabase::class);

/** A succeeded `ai_router.task_guard` run that applied the guard on `$node`: the model route is ready only after one. */
function appliedTaskGuard(Node $node): void
{
    Operation::query()->forceCreate(['kind' => 'ai_router.task_guard', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'status' => OperationStatus::Succeeded, 'input' => ['intent' => ['state' => 'present']]]);
}

function routeNode(string $name, string $address): Node
{
    return Node::withoutEvents(fn (): Node => Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux',
        'architecture' => 'x86_64', 'public_ssh_host' => $address, 'wireguard_ip' => $address, 'user' => 'orbit',
        'ssh_host_fingerprint' => 'SHA256:fixture', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key']));
}

/** The router on `services` (10.44.0.3), reached through the Node model forwarder. */
function routeWorld(): Node
{
    $services = routeNode('services', '10.44.0.3');
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    appliedTaskGuard($services);

    return $services;
}

it('gives the Node model forwarder the guard origin and containers one fixed placeholder key', function (): void {
    routeWorld();
    $route = app(ContainerModelRoute::class);
    $secrets = app(AiRouterSecrets::class);

    $route->assertReady();

    expect($route->origin())->toBe('http://10.44.0.3:8318')->and($route->key())->toBe($secrets->task())
        ->and($route->key())->toBe($route->key())->and($route->key())->not->toBe($secrets->anchor('http://10.44.0.3:8317'));
});

it('refuses the route until a run applied the guard', function (): void {
    $services = routeNode('services', '10.44.0.3');
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');

    expect(fn () => app(ContainerModelRoute::class)->assertReady())->toThrow(ResourceOperationException::class, 'ai-router:task-guard');
    appliedTaskGuard($services);
    app(ContainerModelRoute::class)->assertReady();
    app(AiRouterState::class)->disable();
    expect(fn () => app(ContainerModelRoute::class)->assertReady())->toThrow(ResourceOperationException::class, 'ai-router:setup');
});

it('refuses the route while the ai-router is off', function (): void {
    $route = app(ContainerModelRoute::class);

    expect(fn () => $route->assertReady())->toThrow(ResourceOperationException::class, 'Set up the ai-router extension with ai-router:setup first. Instance containers reach models through its guard.')
        ->and($route->key())->toBe(app(AiRouterSecrets::class)->task())
        ->and(fn () => $route->origin())->toThrow(ResourceOperationException::class);
});

it('tears the ai-router down while containers retain their placeholder key', function (): void {
    routeWorld();

    $status = app(DisableAiRouterAction::class)->execute();

    expect($status->enabled)->toBeFalse()->and(app(AiRouterState::class)->enabled())->toBeFalse();
});
