<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleDirectProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Http\Authorization\ServingNode;
use App\Http\Authorization\ServingNodeResolver;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\WebSocket\WebSocketDnsTarget;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Request;
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

it('keeps an active role active when its convergence fails, so the Gateway still finds its serving Node', function (): void {
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'gateway', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.3',
        'wireguard_ip' => '10.44.0.3', 'user' => 'orbit',
    ]));
    $assignment = $node->roles()->create(['role' => RoleName::Gateway, 'status' => 'active']);
    $operation = Operation::query()->create(['kind' => 'node.role_converge', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1, 'attempts' => 1,
        'input' => ['_role' => ['assignment_id' => $assignment->id, 'role' => 'gateway', 'created' => false,
            'certificates' => ['leaves' => [], 'withdrawals' => []]],
            'native_role' => ['publications' => [], 'artifact_digests' => []]],
        'state' => ['swamp_run_id' => 'fixture-native-role']]);

    app(NodeRoleDirectProjection::class)->apply($operation, ['run_id' => 'fixture-native-role', 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => ['Apply role prerequisites' => ['status' => 'failed']],
            'summary' => ['failed_steps' => ['Apply role prerequisites']]]]);

    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Active);
    expect($assignment->failed_step)->toBeNull();
    expect($operation->refresh()->status->value)->toBe('failed');
    expect(app(ServingNodeResolver::class)->resolve(Request::create('/'), ServingNode::Gateway))
        ->toHaveCount(1);
});

it('marks a role failed when its first add or a non-active convergence fails', function (string $before, string $step, string $failedStep, string $errorCode): void {
    Http::preventStrayRequests();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'database', 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.4',
        'wireguard_ip' => '10.44.0.4', 'user' => 'orbit',
    ]));
    $assignment = $node->roles()->create(['role' => RoleName::Database, 'status' => $before]);
    $operation = Operation::query()->create(['kind' => 'node.role_add', 'target_type' => 'node', 'target_id' => $node->id,
        'node_id' => $node->id, 'status' => 'running', 'sequence' => 1, 'attempts' => 1,
        'input' => ['_role' => ['assignment_id' => $assignment->id, 'role' => 'database', 'created' => false,
            'certificates' => ['leaves' => [], 'withdrawals' => []]],
            'native_role' => ['publications' => [], 'artifact_digests' => []]],
        'state' => ['swamp_run_id' => 'fixture-native-role']]);

    app(NodeRoleDirectProjection::class)->apply($operation, ['run_id' => 'fixture-native-role', 'status' => 'failed',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(),
        'result' => ['steps' => [$step => ['status' => 'failed']], 'summary' => ['failed_steps' => [$step]]]]);

    expect($assignment->refresh()->status)->toBe(LifecycleStatus::Failed);
    expect($assignment->failed_step)->toBe($failedStep);

    $node->accessibleNodes()->attach($node);
    $this->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $expected = [[
        'id' => $assignment->id,
        'role' => 'database',
        'status' => 'failed',
        'failed_step' => $failedStep,
        'error_code' => $errorCode,
    ]];

    $this->getJson('/api/v1/nodes/'.$node->id.'/roles')->assertOk()->assertJsonPath('data', $expected);
    $this->getJson('/api/v1/nodes/'.$node->id)->assertOk()->assertJsonPath('data.assignments', $expected);
    $this->getJson('/api/v1/nodes')->assertOk()->assertJsonPath('data.0.assignments', $expected);
})->with(['first add' => ['provisioning'], 'failed earlier' => ['failed']])->with([
    'native role check' => ['Check the role', 'converge:Check the role', 'node_role.convergence_failed'],
    'recorded Ubuntu requirement' => ['Require supported Ubuntu', 'converge:Require supported Ubuntu', 'role.os_unsupported'],
]);
