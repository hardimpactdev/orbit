<?php

declare(strict_types=1);

use App\Actions\WebSocket\ReportWebSocketRuntimeAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketRegistry;
use App\Infrastructure\AgentView\WebSocketClient;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use App\Models\WebSocketApp;
use App\Models\WebSocketRuntime;
use Illuminate\Support\Str;
use Tests\Support\FakeRoutePublicationSocket;

/**
 * Activates the `websocket` role on a Node and returns the Gateway's Reverb
 * app from the real credential manager, so realtime tests
 * exercise the same resolution path production code does instead of
 * poking `broadcasting.*` config directly.
 *
 * @return array{Node, WebSocketCredentials}
 */
function activate_websocket_role(?Node $node = null): array
{
    $node ??= Node::query()->create([
        'name' => 'websocket-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.90',
        'wireguard_ip' => '10.44.0.90',
        'user' => 'orbit',
    ]);

    $node->roles()->updateOrCreate(
        ['role' => RoleName::WebSocket],
        ['status' => LifecycleStatus::Active],
    );

    confirm_websocket_runtime($node->refresh());
    new WebSocketDnsTarget()->markServing($node->id);

    return [$node, WebSocketApp::query()->where('name', WebSocketApp::GatewayName)->sole()->credentials()];
}

/** Confirm a real registry snapshot, as a process does before accepting Gateway clients. */
function confirm_websocket_runtime(Node $node): WebSocketRuntime
{
    $credentials = app(WebSocketCredentialManager::class)->ensure($node);
    $runtime = WebSocketRuntime::query()->findOrFail($credentials->processId);
    $reports = app(ReportWebSocketRuntimeAction::class);
    $boot = (string) Str::uuid();
    $reports->register($runtime, $boot);
    $reports->execute($runtime, [
        'boot_id' => $boot, 'sequence' => 1, ...app(WebSocketRegistry::class)->version(),
        'state' => 'serving', 'app_count' => WebSocketApp::query()->count(), 'connections' => [],
    ]);

    return $runtime->refresh();
}

/** Keep API contract tests at the shared native-start transport boundary. */
function fake_websocket_registry_start(): void
{
    config(['swamp.run_token' => 'fixture.shared']);
    $socket = new FakeRoutePublicationSocket;
    $socket->reply = static fn (array $request): array => [['type' => 'event', 'id' => $request['id'], 'event' => [
        'kind' => 'started', 'workflowName' => 'operation-websocket-registry', 'runId' => 'a075eb23-7ac4-4487-80d1-57a038a92676',
    ]]];
    app()->instance(WebSocketClient::class, $socket);
}
