<?php

declare(strict_types=1);

use App\Models\Node;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    $node = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.2', 'wireguard_ip' => '10.44.0.2',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
});

it('has no Instance path proxy API routes', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(static fn (Illuminate\Routing\Route $route): bool => str_ends_with($route->uri(), '/path-proxy'));

    expect($routes)->toBeEmpty();
    $this->getJson('/api/v1/instances/1/path-proxy')->assertNotFound();
    $this->putJson('/api/v1/instances/1/path-proxy', [])->assertNotFound();
    $this->deleteJson('/api/v1/instances/1/path-proxy')->assertNotFound();
});

it('does not publish or call the removed MCP tools', function (string $operation): void {
    $name = 'instance-path-proxy-'.$operation;
    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'])
        ->assertOk()->assertJsonMissing(['name' => $name]);

    $this->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
        'params' => ['name' => $name, 'arguments' => ['instance' => 1]],
    ])->assertStatus(400)->assertJsonPath('error.code', -32602);
})->with(['set', 'show', 'unset']);

it('has no path proxy table after a fresh migration', function (): void {
    expect(Schema::hasTable('instance_path_proxies'))->toBeFalse();
});
