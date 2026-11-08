<?php

declare(strict_types=1);

use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\TaskVms\TaskVmHost;
use App\Domain\TaskVms\TaskVmModelRoute;
use App\Domain\TaskVms\TaskVmProviderKind;
use App\Domain\TaskVms\TaskVmSettings;
use App\Domain\TaskVms\TaskVmSize;
use App\Domain\TaskVms\TaskVmState;
use App\Models\Node;
use App\Models\Operation;
use App\Models\TaskVm;
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

/** The router on `services` (10.44.0.3), with task VMs in 10.44.0.128/25. */
function routeWorld(): Node
{
    $services = routeNode('services', '10.44.0.3');
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25',
        [new TaskVmHost(9, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB')],
        '/pi', str_repeat('a', 64), ['gpt-6']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');
    appliedTaskGuard($services);

    return $services;
}

it('gives every task VM the guard origin and the one fixed key, and never the router key or the anchor key', function (): void {
    routeWorld();
    $route = app(TaskVmModelRoute::class);
    $secrets = app(AiRouterSecrets::class);

    $route->assertReady();

    expect($route->origin())->toBe('http://10.44.0.3:8318')->and($route->key())->toBe($secrets->task())
        ->and($route->key())->toBe($route->key())->and($route->key())->not->toBe($secrets->anchor('http://10.44.0.3:8317'));
});

it('refuses the route until a run applied the guard', function (): void {
    $services = routeNode('services', '10.44.0.3');
    app()->instance(TaskVmSettings::class, new TaskVmSettings(true, null, '10.44.0.128/25',
        [new TaskVmHost(9, 'orbit-tasks', 'orbittask0', '10.252.7.0/24', 6, 4, '8GiB', '20GiB')], '/pi', str_repeat('a', 64), ['gpt-6']));
    app(AiRouterState::class)->enable($services->id, 'services-valkey', 'http://10.44.0.3:8317', 'management', 'read', 'control');

    expect(fn () => app(TaskVmModelRoute::class)->assertReady())->toThrow(ResourceOperationException::class, 'ai-router:task-guard');
    appliedTaskGuard($services);
    app(TaskVmModelRoute::class)->assertReady();
    app(AiRouterState::class)->disable();
    expect(fn () => app(TaskVmModelRoute::class)->assertReady())->toThrow(ResourceOperationException::class, 'ai-router:setup');
});

it('refuses the route while the ai-router is off', function (): void {
    $route = app(TaskVmModelRoute::class);

    expect(fn () => $route->assertReady())->toThrow(ResourceOperationException::class, 'ai-router')
        ->and(fn () => $route->origin())->toThrow(ResourceOperationException::class);
});

it('tears the ai-router down while task VMs run: they hold no per-VM key that only the router could revoke', function (): void {
    routeWorld();
    $host = routeNode('beast', '10.44.0.7');
    $node = routeNode('beast-tvm-1', '10.44.0.131');
    TaskVm::query()->create(['host_node_id' => $host->id, 'node_id' => $node->id, 'provider' => TaskVmProviderKind::Incus, 'size' => TaskVmSize::Normal,
        'name' => 'beast-tvm-1', 'state' => TaskVmState::Ready, 'wireguard_ip' => '10.44.0.131', 'pi_token' => str_repeat('p', 64)]);

    $status = app(DisableAiRouterAction::class)->execute();

    expect($status->enabled)->toBeFalse()->and(app(AiRouterState::class)->enabled())->toBeFalse();
});
