<?php

declare(strict_types=1);

use App\Domain\Routes\NativeRouteNodeRender;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Domain\Routes\RouteRenderContext;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Caddy\Build\NodeCaddyListeners;
use App\Models\Node;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Orbit\Extensions\AiRouter\Domain\AiRouterHostname;
use Orbit\Extensions\AiRouter\Domain\AiRouterState;
use Orbit\Extensions\AiRouter\Infrastructure\AiRouterPrivateHostnames;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('reserves one safe collector hostname and publishes its VPN address only while setup holds it', function (): void {
    $contribution = app(AiRouterPrivateHostnames::class);
    $names = app(ReservedPrivateHostname::class)->names();

    expect($contribution->hostnames())->toBe(['collector.ai-router.orbit' => null])
        ->and(array_count_values($names)[AiRouterHostname::Value])->toBe(1)
        ->and(AiRouterHostname::Value)->toMatch('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+orbit\z/D');

    $node = Node::query()->create([
        'name' => 'collector',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.8',
    ]);
    $state = app(AiRouterState::class);
    $state->enable($node->id, 'valkey', 'http://127.0.0.1:8317', 'management', 'read', 'control');

    expect($contribution->hostnames())->toBe([AiRouterHostname::Value => $node->wireguard_ip]);

    $node->update(['wireguard_ip' => '10.44.0.9']);

    expect($contribution->hostnames())->toBe([AiRouterHostname::Value => '10.44.0.9']);

    $state->disable();

    expect($contribution->hostnames())->toBe(['collector.ai-router.orbit' => null])
        ->and(app(ReservedPrivateHostname::class)->names())->toContain(AiRouterHostname::Value);
});

it('keeps collector DNS aligned with its prospective Caddy listener without changing stored identity', function (): void {
    $node = Node::query()->create(['name' => 'prospective-collector', 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'public_ssh_host' => '192.0.2.18', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.18']);
    app(AiRouterState::class)->enable($node->id, 'valkey', 'http://127.0.0.1:8317', 'management', 'read', 'control');
    $contribution = app(AiRouterPrivateHostnames::class);
    $values = app(RouteRenderContext::class)->render(new NativeRouteNodeRender($node->id, '10.44.0.28', null), fn (): array => [
        $contribution->hostnames(), NodeCaddyListeners::forSites($node)->wireGuard]);
    expect($values)->toBe([[AiRouterHostname::Value => '10.44.0.28'], '10.44.0.28'])
        ->and($contribution->hostnames())->toBe([AiRouterHostname::Value => '10.44.0.18'])
        ->and($node->fresh()->wireguard_ip)->toBe('10.44.0.18');
});
