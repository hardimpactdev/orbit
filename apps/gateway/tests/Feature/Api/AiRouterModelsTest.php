<?php

declare(strict_types=1);

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Extensions\ExtensionStore;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Models\DatabaseConnection;
use App\Models\Node;
use Illuminate\Support\Facades\Http;
use Orbit\Extensions\AiRouter\Domain\AiRouterAccount;
use Orbit\Extensions\AiRouter\Domain\AiRouterCache;
use Orbit\Extensions\AiRouter\Domain\AiRouterKeys;
use Orbit\Extensions\AiRouter\Domain\AiRouterModel;
use Orbit\Extensions\AiRouter\Domain\AiRouterSnapshotStore;

it('refuses the model list with ai_router.disabled before the collector is set up', function (): void {
    $gateway = ai_router_models_gateway();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/models')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ai_router.disabled');
});

it('returns the stored model list and does not call CLIProxyAPI', function (): void {
    $gateway = ai_router_models_gateway();
    $node = ai_router_models_node();
    ai_router_models_valkey($node);
    Http::fake();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    app(AiRouterCache::class)->put(AiRouterKeys::Snapshot, json_encode([
        'accounts' => [[
            'id' => 'plus.json',
            'provider' => 'codex',
            'label' => 'plus',
            'disabled' => false,
            'status' => 'enabled',
            'windows' => [],
        ]],
        'providers' => [],
        'models' => [
            ['id' => 'gpt-5.6-luna', 'provider' => 'codex', 'display_name' => 'Luna', 'type' => 'chat'],
            ['id' => 'claude-opus', 'provider' => 'claude'],
            ['id' => '', 'provider' => 'codex'],
            ['provider' => 'claude'],
        ],
        'collected_at' => '2026-09-20T12:00:00+00:00',
    ], JSON_THROW_ON_ERROR));

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/models')
        ->assertOk()
        ->assertJsonPath('data', [
            ['id' => 'gpt-5.6-luna', 'provider' => 'codex'],
            ['id' => 'claude-opus', 'provider' => 'claude'],
        ])
        ->assertJsonMissingPath('data.0.display_name')
        ->assertJsonMissingPath('data.0.type');

    Http::assertNothingSent();
});

it('returns an empty model list when the snapshot has none', function (): void {
    $gateway = ai_router_models_gateway();
    $node = ai_router_models_node();
    ai_router_models_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/models')
        ->assertOk()
        ->assertJsonPath('data', []);
});

it('refuses the model list with ai_router.disabled after teardown', function (): void {
    $gateway = ai_router_models_gateway();
    $node = ai_router_models_node();
    ai_router_models_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->deleteJson('/api/v1/ai-router')
        ->assertOk();

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/models')
        ->assertStatus(409)
        ->assertJsonPath('error.code', 'ai_router.disabled');
});

it('keeps stored models when a later snapshot write does not supply them', function (): void {
    $gateway = ai_router_models_gateway();
    $node = ai_router_models_node();
    ai_router_models_valkey($node);

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->postJson('/api/v1/ai-router', [
            'node_id' => $node->id,
            'cache_connection' => 'valkey',
            'cliproxy_url' => 'http://127.0.0.1:8317',
            'cliproxy_management_key' => 'management-key',
        ])
        ->assertCreated();

    $store = app(AiRouterSnapshotStore::class);
    $store->write([
        new AiRouterAccount('plus.json', 'codex', 'plus', false, 'enabled', []),
    ], '2026-09-20T12:00:00+00:00', [
        new AiRouterModel('gpt-5.6-luna', 'codex'),
    ]);
    $store->write([
        new AiRouterAccount('plus.json', 'codex', 'plus', true, 'disabled', []),
    ], '2026-09-20T12:05:00+00:00');

    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip])
        ->getJson('/api/v1/ai-router/models')
        ->assertOk()
        ->assertJsonPath('data', [
            ['id' => 'gpt-5.6-luna', 'provider' => 'codex'],
        ]);
});

function ai_router_models_gateway(): Node
{
    $node = Node::query()->create([
        'name' => 'gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.1',
        'wireguard_ip' => '10.44.0.1',
    ]);
    test()->markAsGateway($node);
    app(ExtensionStore::class)->set('ai-router', true);

    return $node;
}

function ai_router_models_node(): Node
{
    return Node::query()->create([
        'name' => 'beast',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.8',
        'wireguard_ip' => '10.44.0.8',
    ]);
}

function ai_router_models_valkey(Node $node): DatabaseConnection
{
    $node->roles()->create(['role' => RoleName::Database, 'status' => LifecycleStatus::Active]);

    return DatabaseConnection::query()->create([
        'slug' => 'valkey',
        'driver' => DatabaseDriver::Redis,
        'node_id' => $node->id,
        'host' => $node->wireguard_ip,
        'port' => 6379,
        'database' => '0',
    ]);
}
