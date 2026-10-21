<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketAppIssuer;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\Setting;
use App\Models\WebSocketApp;
use Illuminate\Support\Facades\DB;

it('creates the Gateway app and the server APP_KEY once and keeps them stable across converges', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);

    $first = $manager->ensure($node);
    $second = $manager->ensure($node);

    expect($second)->toEqual($first)
        ->and($first->apps)->toHaveCount(1)
        ->and($first->apps[0]->appId)->toBe(WebSocketApp::query()->sole()->app_id)
        ->and(WebSocketApp::query()->sole()->name)->toBe(WebSocketApp::GatewayName)
        ->and($first->laravelAppKey)->toStartWith('base64:');
});

it('returns every app for the server, ordered by name', function (): void {
    $node = websocketCredentialsNode();
    $issuer = new WebSocketAppIssuer;
    $hauzer = $issuer->issue('hauzer');
    $conn = $issuer->issue('conn');

    $apps = app(WebSocketCredentialManager::class)->ensure($node)->apps;

    expect(array_map(static fn (WebSocketCredentials $app): string => $app->appId, $apps))->toBe([
        $conn->app_id,
        WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole()->app_id,
        $hauzer->app_id,
    ]);
});

it('never stores an app secret or the generated APP_KEY unencrypted', function (): void {
    $node = websocketCredentialsNode();
    $credentials = app(WebSocketCredentialManager::class)->ensure($node);
    $setting = Setting::query()->where('scope_type', 'node')->where('scope_id', $node->id)->sole();
    $raw = DB::table('websocket_apps')->sole();

    expect($setting->key)->toBe(WebSocketFootprint::SettingKeyAppKeyLaravel)
        ->and($setting->is_secret)->toBeTrue()
        ->and($setting->value)->not->toContain($credentials->laravelAppKey)
        ->and($raw->secret)->not->toBe($credentials->apps[0]->appSecret)
        ->and(WebSocketApp::query()->sole()->toArray())->not->toHaveKey('secret');
});

it('returns null credentials when no websocket role is active', function (): void {
    expect(app(WebSocketCredentialManager::class)->current())->toBeNull();
});

it('returns null credentials when more than one websocket role is active', function (): void {
    $first = websocketCredentialsNode('websocket-a', '10.44.0.91');
    $second = websocketCredentialsNode('websocket-b', '10.44.0.92');
    app(WebSocketCredentialManager::class)->ensure($first);
    app(WebSocketCredentialManager::class)->ensure($second);

    expect(app(WebSocketCredentialManager::class)->current())->toBeNull();
});

it('reads the active assignment credentials through current()', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);
    $ensured = $manager->ensure($node)->apps[0];

    $current = $manager->current();

    expect($current)->not->toBeNull()
        ->and($current?->appId)->toBe($ensured->appId)
        ->and($current?->appKey)->toBe($ensured->appKey)
        ->and($current?->appSecret)->toBe($ensured->appSecret);
});

it('points the Gateway realtime client at the Node that serves reverb.orbit during a move', function (): void {
    $source = Node::query()->create([
        'name' => 'websocket-source',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.89',
        'wireguard_ip' => '10.44.0.89',
        'user' => 'orbit',
    ]);
    new CaddySiteCertificates()->record($source->id, WebSocketCaddySiteSource::CertificateSlug);
    // The move stored the role row on the target, which has its certificate but no live build yet.
    $target = websocketCredentialsNode();
    new CaddySiteCertificates()->record($target->id, WebSocketCaddySiteSource::CertificateSlug);
    $manager = app(WebSocketCredentialManager::class);
    $manager->ensure($target);

    expect($manager->current()?->servingAddress)->toBe('10.44.0.89')
        ->and($manager->current()?->addresses())->toBe(['10.44.0.89']);

    new WebSocketDnsTarget()->markServing($target->id);

    expect($manager->current()?->servingAddress)->toBe('10.44.0.90')
        ->and($manager->current()?->addresses())->toBe(['10.44.0.90', '10.44.0.89']);

    // The old Node's withdrawal forgets its certificate record before its build and its mark after it.
    new CaddySiteCertificates()->forget($source->id, WebSocketCaddySiteSource::CertificateSlug);

    expect($manager->current()?->addresses())->toBe(['10.44.0.90']);

    new WebSocketDnsTarget()->markServing($source->id);

    expect($manager->current()?->addresses())->toBe(['10.44.0.90', '10.44.0.89']);

    new WebSocketDnsTarget()->forget($source->id);

    expect($manager->current()?->addresses())->toBe(['10.44.0.90']);
});

it('purges the server APP_KEY and keeps the apps', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);
    $manager->ensure($node);

    $manager->purge($node);

    expect(Setting::query()->where('scope_type', 'node')->where('scope_id', $node->id)->count())->toBe(0)
        ->and(WebSocketApp::query()->count())->toBe(1);
});

function websocketCredentialsNode(string $name = 'websocket-node', string $wireguardIp = '10.44.0.90'): Node
{
    $node = Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => $wireguardIp,
        'user' => 'orbit',
    ]);
    $node->roles()->create(['role' => RoleName::WebSocket, 'status' => LifecycleStatus::Active]);

    return $node->refresh();
}
