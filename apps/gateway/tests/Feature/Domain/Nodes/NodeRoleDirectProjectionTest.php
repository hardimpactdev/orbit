<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(LazilyRefreshDatabase::class);

it('records WebSocket serving only after the role publication phase, including a later failure', function (string $publication, string $terminal): void {
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'publication', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.2',
        'wireguard_ip' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $assignment = $node->roles()->create(['role' => RoleName::WebSocket, 'status' => 'provisioning']);
    $operation = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1, 'attempts' => 1,
        'input' => ['_role' => ['assignment_id' => $assignment->id, 'role' => 'websocket', 'created' => true,
            'certificates' => ['leaves' => [['node_id' => $node->id, 'scope' => 'role-websocket']], 'withdrawals' => []]],
            'native_role' => ['publications' => [['node_id' => $node->id, 'after' => ['caddy' => ['content' => 'public']]]], 'artifact_digests' => []]],
        'state' => ['swamp_run_id' => 'fixture-native-role']]);

    app(NodeRoleDirectProjection::class)->apply($operation, ['run_id' => 'fixture-native-role', 'status' => $terminal,
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Sign role certificates-0' => ['status' => 'succeeded'],
            'Publish role sites' => ['status' => $publication], 'Check the role' => ['status' => $terminal]],
            'summary' => ['failed_steps' => $terminal === 'failed' ? ['Check the role'] : []]]]);

    expect(app(CaddySiteCertificates::class)->published($node->id, 'websocket'))->toBeTrue();
    expect(app(WebSocketDnsTarget::class)->isServing($node->id))->toBe($publication === 'succeeded');
    expect($assignment->refresh()->status->value)->toBe($terminal === 'succeeded' ? 'active' : 'failed');
    Http::assertNothingSent();
})->with(['published' => ['succeeded', 'succeeded'], 'later failure' => ['succeeded', 'failed'], 'publication failure' => ['failed', 'failed']]);
