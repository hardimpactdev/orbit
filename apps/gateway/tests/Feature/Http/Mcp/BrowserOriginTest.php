<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Models\Node;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

use function Pest\Laravel\json;
use function Pest\Laravel\postJson;
use function Pest\Laravel\withServerVariables;

uses(LazilyRefreshDatabase::class);

dataset('MCP endpoint', ['/mcp', '/mcp/search']);

describe('MCP browser origins', function (): void {
    beforeEach(function (): void {
        config(['app.url' => 'https://gateway.orbit']);
    });

    it('initializes the server from an allowed origin or a headerless client', function (string $path, string $host, array $headers): void {
        $gateway = Node::withoutEvents(fn (): Node => Node::query()->create([
            'name' => 'gateway-node',
            'status' => 'active',
            'platform' => 'linux',
            'wireguard_ip' => '10.44.0.2',
            'public_ssh_host' => '10.44.0.2',
            'user' => 'orbit',
        ]));
        $gateway->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);

        withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);

        postJson("https://{$host}{$path}", [
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'initialize',
            'params' => ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'test', 'version' => '1']],
        ], $headers)
            ->assertOk()
            ->assertJsonPath('result.serverInfo.name', $path === '/mcp' ? 'Orbit Gateway' : 'Orbit Gateway (search)');
    })->with('MCP endpoint')->with([
        'canonical' => ['gateway.orbit', ['Origin' => 'https://gateway.orbit', 'Sec-Fetch-Site' => 'same-origin']],
        'Gateway node hostname' => ['gateway-node.orbit', ['Origin' => 'https://gateway-node.orbit', 'Sec-Fetch-Site' => 'same-origin']],
        'Gateway node origin on the canonical host' => ['gateway.orbit', ['Origin' => 'https://gateway-node.orbit', 'Sec-Fetch-Site' => 'same-site']],
        'headerless' => ['gateway-node.orbit', []],
    ]);

    it('returns HTTP 405 to the Gateway node origin for unsupported methods', function (string $path, string $method): void {
        json($method, 'https://gateway-node.orbit'.$path, [], ['Origin' => 'https://gateway-node.orbit'])
            ->assertStatus(405)
            ->assertHeader('Allow', 'POST');
    })->with('MCP endpoint')->with(['GET', 'DELETE']);

    it('refuses foreign browser origins before peer authentication with HTTP 403', function (string $path): void {
        postJson('https://gateway.orbit'.$path, [], ['Origin' => 'https://foreign.example'])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'api.origin_refused');
    })->with('MCP endpoint');

    it('still requires an active peer with HTTP 403 for headerless callers', function (string $path): void {
        postJson('https://gateway.orbit'.$path)
            ->assertForbidden()
            ->assertJsonPath('error.code', 'peer.identity_unknown');
    })->with('MCP endpoint');
});
