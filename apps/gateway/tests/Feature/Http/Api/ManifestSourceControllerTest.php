<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\ManifestSourceController;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\RequireGrantPermission;
use App\Http\Middleware\WireGuardIdentity;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\ReleaseManifestSource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const MANIFEST_SOURCE_GATEWAY_WG_IP = '10.6.0.45';
const MANIFEST_SOURCE_DEFAULT_URL = 'https://github.com/hardimpactdev/orbit/releases/latest/download/orbit-release-manifest.json';

beforeEach(function (): void {
    config()->set('orbit.updates.release_manifest_url', MANIFEST_SOURCE_DEFAULT_URL);

    $this->gateway = createTestGatewayNode([
        'name' => 'gateway',
        'host' => 'gateway',
        'wireguard_address' => MANIFEST_SOURCE_GATEWAY_WG_IP,
    ]);
});

it('updates the gateway release manifest URL override', function (): void {
    $url = 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json';

    manifestSourceRequest('PUT', '/api/manifest', ['url' => $url])
        ->assertOk()
        ->assertJsonPath('success.data.manifest.source', 'custom')
        ->assertJsonPath('success.data.manifest.url', $url)
        ->assertJsonPath('success.data.manifest.custom_url', $url)
        ->assertJsonPath('success.data.manifest.default_url', MANIFEST_SOURCE_DEFAULT_URL);

    expect(ReleaseManifestSource::current()->custom_url)->toBe($url);
});

it('removes the gateway release manifest URL override and returns to the default source', function (): void {
    ReleaseManifestSource::current()->update([
        'custom_url' => 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json',
    ]);

    manifestSourceRequest('DELETE', '/api/manifest')
        ->assertOk()
        ->assertJsonPath('success.data.manifest.source', 'default')
        ->assertJsonPath('success.data.manifest.url', MANIFEST_SOURCE_DEFAULT_URL)
        ->assertJsonPath('success.data.manifest.custom_url', null)
        ->assertJsonPath('success.data.manifest.default_url', MANIFEST_SOURCE_DEFAULT_URL);

    expect(ReleaseManifestSource::current()->custom_url)->toBeNull();
});

it('rejects non-http manifest URLs', function (): void {
    manifestSourceRequest('PUT', '/api/manifest', ['url' => 'file:///tmp/orbit-release-manifest.json'])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonPath('error.meta.field', 'url');

    expect(ReleaseManifestSource::query()->count())->toBe(0);
});

it('requires gateway admin authority for non-gateway callers', function (): void {
    Node::factory()->create([
        'name' => 'operator',
        'status' => 'active',
        'wireguard_address' => '10.6.0.90',
    ]);

    manifestSourceRequest(
        'PUT',
        '/api/manifest',
        ['url' => 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json'],
        remoteAddress: '10.6.0.90',
    )
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed')
        ->assertJsonPath('error.meta.missing_permission', '*')
        ->assertJsonPath('error.meta.serving_node', 'gateway');
});

it('allows non-gateway callers with gateway admin authority', function (): void {
    $caller = Node::factory()->create([
        'name' => 'operator',
        'status' => 'active',
        'wireguard_address' => '10.6.0.90',
    ]);
    NodeAccess::query()->create([
        'consumer_node_id' => $caller->id,
        'serving_node_id' => $this->gateway->id,
        'permissions' => ['*'],
        'custom_permissions' => ['*'],
    ]);

    manifestSourceRequest(
        'PUT',
        '/api/manifest',
        ['url' => 'https://artifacts.example.com/channels/live-test/orbit-release-manifest.json'],
        remoteAddress: '10.6.0.90',
    )->assertOk();
});

it('declares gateway-wide permission on the manifest source controller', function (): void {
    $attributes = (new ReflectionClass(ManifestSourceController::class))
        ->getAttributes(RequiresPermission::class);

    expect($attributes)->toHaveCount(1);

    $permission = $attributes[0]->newInstance();

    expect($permission->permission)->toBe('*')
        ->and($permission->servingNode)->toBe(ServingNode::Gateway);
});

it('lives in the logged authenticated gateway API group', function (string $routeName): void {
    $route = Route::getRoutes()->getByName($routeName);

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain(WireGuardIdentity::class)
        ->and($middleware)->toContain(RequireGrantPermission::class)
        ->and($middleware)->toContain(LogActivity::class);
})->with([
    'api.manifest.update',
    'api.manifest.destroy',
]);

/**
 * @param  array<string, mixed>  $payload
 */
function manifestSourceRequest(
    string $method,
    string $path,
    array $payload = [],
    string $remoteAddress = MANIFEST_SOURCE_GATEWAY_WG_IP,
): TestResponse {
    return test()->call($method, $path, $payload, [], [], [
        'REMOTE_ADDR' => $remoteAddress,
    ]);
}
