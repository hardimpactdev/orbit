<?php

declare(strict_types=1);

use App\Actions\WebSocket\ReportWebSocketRuntimeAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketAppIssuer;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\Sources\WebSocketCaddySiteSource;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Infrastructure\WebSocket\WebSocketFootprint;
use App\Models\Node;
use App\Models\Setting;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

it('creates the Gateway app and the server APP_KEY once and keeps them stable across converges', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);

    $first = $manager->ensure($node);
    $second = $manager->ensure($node);

    expect($second)->toEqual($first)
        ->and($first->processId)->toBe(WebSocketRuntime::query()->sole()->id)
        ->and(WebSocketApp::query()->sole()->name)->toBe(WebSocketApp::GatewayName)
        ->and($first->laravelAppKey)->toStartWith('base64:');
});

it('loads every app through the current registry instead of bootstrap credentials', function (): void {
    $node = websocketCredentialsNode();
    $issuer = new WebSocketAppIssuer;
    $hauzer = $issuer->issue('hauzer');
    $conn = $issuer->issue('conn');

    app(WebSocketCredentialManager::class)->ensure($node);
    $apps = app(WebSocketRegistry::class)->snapshot()['apps'];

    expect(array_column($apps, 'app_id'))->toEqualCanonicalizing([
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
        ->and($raw->secret)->not->toBe(WebSocketApp::query()->sole()->secret)
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
    $manager->ensure($node);
    $ensured = WebSocketApp::query()->sole()->credentials();

    confirm_websocket_runtime($node);
    new WebSocketDnsTarget()->markServing($node->id);
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
    new WebSocketDnsTarget()->markServing($source->id);
    // The move stored the role row on the target, which has its certificate but no live build yet.
    $target = websocketCredentialsNode();
    new CaddySiteCertificates()->record($target->id, WebSocketCaddySiteSource::CertificateSlug);
    $manager = app(WebSocketCredentialManager::class);
    $manager->ensure($target);
    confirm_websocket_runtime($source);
    confirm_websocket_runtime($target);

    expect($manager->current()?->servingAddress)->toBe('10.44.0.89')
        ->and($manager->current()?->addresses())->toBe(['10.44.0.89']);

    new WebSocketDnsTarget()->markServing($target->id);

    expect($manager->current()?->servingAddress)->toBe('10.44.0.90')
        ->and($manager->current()?->addresses())->toBe(['10.44.0.90', '10.44.0.89']);

    // The old Node's withdrawal forgets its certificate record before its build and its mark after it.
    new CaddySiteCertificates()->forget($source->id, WebSocketCaddySiteSource::CertificateSlug);

    expect($manager->current()?->addresses())->toBe(['10.44.0.90', '10.44.0.89']);

    new WebSocketDnsTarget()->markServing($source->id);

    expect($manager->current()?->addresses())->toBe(['10.44.0.90', '10.44.0.89']);

    new WebSocketDnsTarget()->forget($source->id);

    expect($manager->current()?->addresses())->toBe(['10.44.0.90']);
});

it('keeps the confirmed Gateway app configured across role lifecycle failures', function (LifecycleStatus $status): void {
    [$node, $credentials] = activate_websocket_role();
    $node->roles()->update(['status' => $status, 'failed_step' => 'converge:private-dns', 'error_code' => 'app-dev.dns_config_failed']);

    $current = app(WebSocketCredentialManager::class)->current();

    expect($current?->appId)->toBe($credentials->appId)
        ->and($current?->addresses())->toBe([$node->wireguard_ip]);
})->with([LifecycleStatus::Provisioning, LifecycleStatus::Failed, LifecycleStatus::Removing]);

it('refuses an active assignment until its process and published site confirm serving', function (): void {
    $node = websocketCredentialsNode();
    $manager = app(WebSocketCredentialManager::class);
    $manager->ensure($node);
    new WebSocketDnsTarget()->markServing($node->id);
    expect($manager->current())->toBeNull();

    confirm_websocket_runtime($node);
    new WebSocketDnsTarget()->forget($node->id);
    expect($manager->current())->toBeNull();

    new WebSocketDnsTarget()->markServing($node->id);
    expect($manager->current())->not->toBeNull();
});

it('retains the served Gateway app across unrelated registry changes and delayed reports', function (): void {
    $this->freezeTime();
    [$node, $credentials] = activate_websocket_role();
    new WebSocketAppIssuer()->issue('pending-app');
    $this->travel(59)->seconds();

    expect(app(WebSocketCredentialManager::class)->current()?->appKey)->toBe($credentials->appKey);
});

it('withdraws realtime for a new boot, abandoned runtime, changed history or inactive Node', function (string $change): void {
    [$node] = activate_websocket_role();
    $runtime = WebSocketRuntime::query()->where('node_id', $node->id)->sole();
    match ($change) {
        'new boot' => app(ReportWebSocketRuntimeAction::class)->register($runtime, (string) Str::uuid()),
        'abandoned' => app(WebSocketRegistry::class)->abandonNode($node->id),
        'history' => $runtime->update(['epoch' => (string) Str::uuid()]),
        'inactive' => $node->update(['status' => LifecycleStatus::Failed]),
    };

    expect(app(WebSocketCredentialManager::class)->current())->toBeNull();
})->with(['new boot', 'abandoned', 'history', 'inactive']);

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

it('expires a confirmed endpoint after one minute and restores it on a fresh serving heartbeat', function (): void {
    $this->freezeTime();
    [$node, $credentials] = activate_websocket_role();
    $node->roles()->update(['status' => LifecycleStatus::Failed, 'failed_step' => 'converge:private-dns']);
    $runtime = WebSocketRuntime::query()->where('node_id', $node->id)->sole();
    new WebSocketAppIssuer()->issue('unrelated-pending-app');
    $report = ['boot_id' => $runtime->boot_id, 'epoch' => $runtime->epoch, 'revision' => $runtime->applied_revision, 'state' => 'serving', 'error' => 'registry.unreachable'];
    $manager = app(WebSocketCredentialManager::class);
    $this->travel(59)->seconds();
    expect($manager->current()?->appKey)->toBe($credentials->appKey);

    $this->travel(1)->seconds();
    expect($manager->current())->toBeNull()
        ->and($runtime->refresh()->required)->toBeTrue();
    app(ReportWebSocketRuntimeAction::class)->execute($runtime, [...$report, 'sequence' => 2]);
    expect($manager->current()?->appKey)->toBe($credentials->appKey);
});

it('excludes a serving endpoint without a report timestamp', function (): void {
    [$node] = activate_websocket_role();
    WebSocketRuntime::query()->where('node_id', $node->id)->update(['reported_at' => null]);

    expect(app(WebSocketCredentialManager::class)->current())->toBeNull();
});

it('omits an expired move endpoint while keeping a fresh serving endpoint', function (): void {
    $this->freezeTime();
    $source = Node::query()->create([
        'name' => 'websocket-source', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.89', 'wireguard_ip' => '10.44.0.89', 'user' => 'orbit',
    ]);
    confirm_websocket_runtime($source);
    new WebSocketDnsTarget()->markServing($source->id);
    $this->travel(60)->seconds();
    [$target] = activate_websocket_role();

    expect(app(WebSocketCredentialManager::class)->current()?->addresses())->toBe([$target->wireguard_ip]);
});
