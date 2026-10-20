<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\Setting;
use App\Models\WebSocketApp;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

function websocket_apps_migration(): Migration
{
    Schema::drop('websocket_app_targets');
    Schema::drop('websocket_apps');

    return require base_path('database/migrations/2026_12_07_000000_create_websocket_apps.php');
}

function websocket_apps_migration_node(string $name, string $wireguardIp): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.70',
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
}

it('moves the websocket role app into the gateway app and deletes the old settings', function (): void {
    $node = websocket_apps_migration_node('services', '10.44.0.70');
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);
    $leftover = websocket_apps_migration_node('beast', '10.44.0.71');
    $settings = app(SettingRepository::class);
    $scope = new SettingScope(SettingScopeType::Node, $node->id);
    $settings->put($scope, 'websocket.reverb.app_id', 'live-app-id');
    $settings->put($scope, 'websocket.reverb.app_key', 'live-app-key');
    $settings->put($scope, 'websocket.reverb.app_secret', 'live-app-secret', SettingValueProtection::Secret);
    $settings->put($scope, WebSocketFootprint::SettingKeyAppKeyLaravel, 'base64:live', SettingValueProtection::Secret);
    $settings->put(new SettingScope(SettingScopeType::Node, $leftover->id), 'websocket.reverb.app_id', 'leftover-app-id');

    websocket_apps_migration()->up();

    $app = WebSocketApp::query()->sole();

    expect($app->only(['name', 'app_id', 'key', 'secret']))->toBe([
        'name' => WebSocketApp::GatewayName,
        'app_id' => 'live-app-id',
        'key' => 'live-app-key',
        'secret' => 'live-app-secret',
    ])
        ->and(Setting::query()->where('key', 'like', 'websocket.reverb.app_%')->count())->toBe(0)
        ->and($settings->get($scope, WebSocketFootprint::SettingKeyAppKeyLaravel))->toBe('base64:live');
});

it('creates no app when the websocket role has none yet', function (): void {
    websocket_apps_migration()->up();

    expect(WebSocketApp::query()->count())->toBe(0);
});
