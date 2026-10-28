<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

it('follows role writes and returns the final Operation with its exit status', function (string $verb, bool $succeeded): void {
    $home = sys_get_temp_dir().'/orbit-role-operation-'.Str::uuid();
    config(['orbit.home' => $home]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', caPath: '/unused'));
    $request = match ($verb) {
        'add' => AddNodeRoleRequest::class, 'remove' => RemoveNodeRoleRequest::class, 'relocate' => RelocateNodeRoleRequest::class,
    };
    $operation = ['id' => 7, 'kind' => 'node.role_'.$verb, 'target_type' => 'node', 'target_id' => 2,
        'status' => $succeeded ? 'succeeded' : 'failed', 'error_code' => $succeeded ? null : 'node_role.teardown_failed',
        'sequence' => 3, 'input' => [], 'steps' => [], 'result' => ['role_proven' => $succeeded],
        'finished_at' => '2026-10-29T00:00:00Z'];
    $envelope = ['data' => $operation, 'meta' => ['request_id' => '0198e15c-bf97-7c23-8f1f-61b8fe67a844']];
    $mock = MockClient::global([
        $request => MockResponse::make($envelope, 202),
        ShowOperationRequest::class => MockResponse::make($envelope),
        '*/api/v1/realtime' => MockResponse::make(['error' => ['code' => 'http.404', 'message' => 'Unavailable']], 404),
    ]);

    try {
        $args = ['node' => '2', 'role' => 'websocket', '--idempotency-key' => 'role-proof', '--json' => true];
        if ($verb !== 'add') {
            $args['--force'] = true;
        }
        $this->artisan('node:role:'.$verb, $args)->expectsOutputToContain('node.role_'.$verb)->assertExitCode($succeeded ? 0 : 1);
        $mock->assertSent(fn ($pending): bool => $pending instanceof $request && $pending->headers()->get('Idempotency-Key') === 'role-proof');
        $mock->assertSent(ShowOperationRequest::class);
    } finally {
        MockClient::destroyGlobal();
        new Filesystem()->deleteDirectory($home);
    }
})->with(['add', 'remove', 'relocate'])->with([true, false]);
