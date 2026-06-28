<?php

declare(strict_types=1);

use App\Http\Middleware\RequireGatewayExtension;
use App\Http\Middleware\WireGuardIdentity;
use App\Models\GatewayExtension;
use App\Models\Node;
use App\Models\NodeRoleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

uses(RefreshDatabase::class);

const REQUIRE_EXTENSION_CALLER_WG_IP = '10.6.30.84';

final class RequireGatewayExtensionOpenController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['success' => ['data' => ['ok' => true]]]);
    }
}

function requireExtensionCallerNode(): Node
{
    $node = Node::factory()->create([
        'name' => 'extension-caller',
        'status' => 'active',
        'wireguard_address' => REQUIRE_EXTENSION_CALLER_WG_IP,
    ]);

    NodeRoleAssignment::factory()->create([
        'node_id' => $node->id,
        'role' => 'gateway',
        'status' => 'active',
    ]);

    return $node;
}

function requireExtensionGet(string $uri): TestResponse
{
    /** @var TestCase $test */
    $test = test();

    return $test->call(
        'GET',
        $uri,
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'REMOTE_ADDR' => REQUIRE_EXTENSION_CALLER_WG_IP,
        ],
    );
}

describe('RequireGatewayExtension middleware', function (): void {
    beforeEach(function (): void {
        Route::middleware([WireGuardIdentity::class, RequireGatewayExtension::class.':cloudflare'])
            ->get('/_test/require-extension/cloudflare', RequireGatewayExtensionOpenController::class);

        Route::middleware([WireGuardIdentity::class, RequireGatewayExtension::class.':missing'])
            ->get('/_test/require-extension/missing', RequireGatewayExtensionOpenController::class);
    });

    it('passes through when the extension is enabled', function (): void {
        requireExtensionCallerNode();

        GatewayExtension::query()->create([
            'slug' => 'cloudflare',
            'enabled' => true,
            'enabled_at' => now(),
        ]);

        requireExtensionGet('/_test/require-extension/cloudflare')
            ->assertOk()
            ->assertJsonPath('success.data.ok', true);
    });

    it('returns extension_disabled when the extension is disabled', function (): void {
        requireExtensionCallerNode();

        requireExtensionGet('/_test/require-extension/cloudflare')
            ->assertConflict()
            ->assertJsonPath('error.code', 'extension_disabled')
            ->assertJsonPath('error.meta.extension', 'cloudflare')
            ->assertJsonPath('error.meta.scope', 'gateway');
    });

    it('returns extension_unknown for unknown middleware slugs', function (): void {
        requireExtensionCallerNode();

        requireExtensionGet('/_test/require-extension/missing')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'extension_unknown')
            ->assertJsonPath('error.meta.extension', 'missing');
    });
});
