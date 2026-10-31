<?php

declare(strict_types=1);

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Orbit\Sdk\Requests\Nodes\RelocateNodeRoleRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeRoleRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Operations\OperationResponse;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

beforeEach(function (): void {
    MockClient::destroyGlobal();
    Sleep::fake(syncWithCarbon: true);
    $this->orbitHome = sys_get_temp_dir().'/orbit-role-lifecycle-'.Str::uuid();
    config(['orbit.home' => $this->orbitHome]);
    app(GatewayConfigRepository::class)->add(new GatewayProfile('test', 'https://10.44.0.1', '/home/orbit/.orbit/ca/root.pem'));
});
afterEach(function (): void {
    MockClient::destroyGlobal();
    Sleep::fake(false);
    new Filesystem()->deleteDirectory($this->orbitHome);
});

it('follows the owning native role Operation and preserves retries consent and terminal failures', function (string $verb, bool $json, bool $failed): void {
    $kind = $verb === 'remove' ? 'node.role_remove' : 'node.role_relocate';
    $class = $verb === 'remove' ? RemoveNodeRoleRequest::class : RelocateNodeRoleRequest::class;
    $requestId = '0198e15c-bf97-7c23-8f1f-61b8fe67a844';
    $started = ['id' => 91, 'kind' => $kind, 'target_type' => 'node', 'target_id' => 7, 'status' => 'queued', 'sequence' => 1, 'input' => []];
    $final = [...$started, 'sequence' => 2, 'status' => $failed ? 'failed' : 'succeeded',
        'failed_step' => $failed ? 'source' : null, 'error_code' => $failed ? 'node_role.source_cleanup_failed' : null,
        'result' => $failed ? ['failed_step' => 'source', 'error_code' => 'node_role.source_cleanup_failed'] : ['removed' => $verb === 'remove']];
    $client = MockClient::global([
        ShowRealtimeRequest::class => MockResponse::make(['data' => ['url' => null, 'key' => null, 'channel' => 'orbit'], 'meta' => ['request_id' => $requestId]]),
        $class => MockResponse::make(['data' => $started, 'meta' => ['request_id' => $requestId]], 202),
        ShowOperationRequest::class => MockResponse::make(['data' => $final, 'meta' => ['request_id' => $requestId]]),
    ]);
    $options = ['node' => '7', 'role' => 'websocket', '--force' => true, '--idempotency-key' => 'same-native-root', '--json' => $json];
    if ($verb === 'relocate') {
        $options['--from'] = '3';
    }
    $command = $this->artisan('node:role:'.$verb, $options);
    if ($json) {
        $command->expectsOutput(json_encode(OperationResponse::fromGatewayData($final, $requestId)->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    } else {
        $command->expectsOutputToContain(($verb === 'remove' ? 'Node role removal' : 'Node role relocation').($failed ? ' failed.' : ' succeeded.'));
    }
    $command->assertExitCode($failed ? 1 : 0);
    $command->run();
    $client->assertSent($class);
    $client->assertSent(ShowOperationRequest::class);
    $client->assertSent(static fn ($request): bool => $request instanceof $class && $request->headers()->get('Idempotency-Key') === 'same-native-root' && ($request->body()->all()['force'] ?? null) === true);
})->with(['remove', 'relocate'])->with([true, false])->with([true, false]);
