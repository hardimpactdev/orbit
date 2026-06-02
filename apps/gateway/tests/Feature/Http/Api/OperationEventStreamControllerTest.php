<?php

declare(strict_types=1);

use App\Http\Authorization\RequiresPermission;
use App\Http\Authorization\ServingNode;
use App\Http\Controllers\Api\OperationEventStreamController;
use App\Http\Middleware\CorrelationHeader;
use App\Http\Middleware\LogActivity;
use App\Http\Middleware\RequireGrantPermission;
use App\Http\Middleware\WireGuardIdentity;
use App\Models\Node;
use App\Models\NodeAccess;
use App\Models\OperationRun;
use App\Services\Operations\OperationRunRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const OPERATION_EVENTS_GATEWAY_WG_IP = '10.6.0.44';

beforeEach(function (): void {
    $this->gateway = createTestGatewayNode([
        'name' => 'gateway',
        'host' => 'gateway',
        'wireguard_address' => OPERATION_EVENTS_GATEWAY_WG_IP,
    ]);

    $this->recorder = app(OperationRunRecorder::class);
    $this->run = $this->recorder->queued(
        operationId: (string) Str::uuid(),
        lane: 'gateway',
        operationType: 'update:all',
    );
});

it('replays operation events from the beginning as server sent events', function (): void {
    $tree = $this->recorder->appendTree($this->run->id, 'Update all', [
        ['key' => 'gateway', 'label' => 'Update gateway'],
    ]);
    $step = $this->recorder->appendStep($this->run->id, 'gateway', 'running', 'Updating gateway');
    $complete = $this->recorder->appendComplete($this->run->id, 0, ['version' => '1.2.3']);

    $response = operationEventStreamRequest($this->run);

    $response->assertOk()
        ->assertHeader('Content-Type', 'text/event-stream; charset=UTF-8')
        ->assertHeader('X-Accel-Buffering', 'no');

    $content = $response->streamedContent();

    expect($content)->toContain("id: {$tree->id}\n")
        ->and($content)->toContain("event: tree\n")
        ->and($content)->toContain('"title":"Update all"')
        ->and($content)->toContain("id: {$step->id}\n")
        ->and($content)->toContain('"status":"running"')
        ->and($content)->toContain("id: {$complete->id}\n")
        ->and($content)->toContain("event: complete\n")
        ->and($content)->toContain('"exit_code":0')
        ->and($content)->toContain('"version":"1.2.3"');
});

it('continues replay after the last seen event id', function (): void {
    $first = $this->recorder->appendStep($this->run->id, 'gateway', 'running');
    $second = $this->recorder->appendStep($this->run->id, 'gateway', 'done');

    $response = operationEventStreamRequest($this->run, [
        'HTTP_LAST_EVENT_ID' => (string) $first->id,
    ]);

    $response->assertOk();

    $content = $response->streamedContent();

    expect($content)->not->toContain("id: {$first->id}\n")
        ->and($content)->toContain("id: {$second->id}\n")
        ->and($content)->toContain('"status":"done"');
});

it('streams terminal error state when the operation ended with an error event', function (): void {
    $terminal = $this->recorder->appendError(
        $this->run->id,
        message: 'Gateway health failed',
        exitCode: 17,
        data: ['code' => 'gateway_health_failed'],
    );

    $response = operationEventStreamRequest($this->run);

    $response->assertOk();

    expect($response->streamedContent())->toContain("id: {$terminal->id}\n")
        ->and($response->streamedContent())->toContain("event: error\n")
        ->and($response->streamedContent())->toContain('"message":"Gateway health failed"')
        ->and($response->streamedContent())->toContain('"exit_code":17');
});

it('rejects requests that do not resolve to a WireGuard node identity', function (): void {
    $this->call('GET', "/api/operations/{$this->run->id}/events", [], [], [], [
        'REMOTE_ADDR' => '10.6.0.222',
    ])
        ->assertForbidden()
        ->assertJsonPath('error.code', 'authorization_failed');
});

it('requires gateway admin authority for non-gateway callers', function (): void {
    Node::factory()->create([
        'name' => 'operator',
        'status' => 'active',
        'wireguard_address' => '10.6.0.90',
    ]);

    $this->call('GET', "/api/operations/{$this->run->id}/events", [], [], [], [
        'REMOTE_ADDR' => '10.6.0.90',
    ])
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

    $this->recorder->appendStep($this->run->id, 'gateway', 'running');

    $response = $this->call('GET', "/api/operations/{$this->run->id}/events", [], [], [], [
        'REMOTE_ADDR' => '10.6.0.90',
    ]);

    $response->assertOk();

    expect($response->streamedContent())->toContain("event: step\n");
});

it('declares gateway-wide permission on the controller', function (): void {
    $attributes = (new ReflectionClass(OperationEventStreamController::class))
        ->getAttributes(RequiresPermission::class);

    expect($attributes)->toHaveCount(1);

    $permission = $attributes[0]->newInstance();

    expect($permission->permission)->toBe('*')
        ->and($permission->servingNode)->toBe(ServingNode::Gateway);
});

it('uses WireGuard and grant middleware while bypassing LogActivity', function (): void {
    $route = Route::getRoutes()->getByName('api.operations.events');

    expect($route)->not->toBeNull();

    $middleware = $route->gatherMiddleware();

    expect($middleware)->toContain(CorrelationHeader::class)
        ->and($middleware)->toContain(WireGuardIdentity::class)
        ->and($middleware)->toContain(RequireGrantPermission::class)
        ->and($middleware)->not->toContain(LogActivity::class);
});

/**
 * @param  array<string, string>  $server
 */
function operationEventStreamRequest(OperationRun $run, array $server = []): TestResponse
{
    return test()->call('GET', "/api/operations/{$run->id}/events", [], [], [], [
        'REMOTE_ADDR' => OPERATION_EVENTS_GATEWAY_WG_IP,
        ...$server,
    ]);
}
