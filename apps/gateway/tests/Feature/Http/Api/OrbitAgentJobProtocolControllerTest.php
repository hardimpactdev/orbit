<?php

declare(strict_types=1);

use App\Models\Node;
use App\Models\NodeRoleAssignment;
use App\Models\OperationEvent;
use App\Models\OperationRun;
use App\Models\OrbitAgentJob;
use App\Services\Operations\OperationPayloadRejected;
use App\Services\OrbitAgentJobs\OrbitAgentJobDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

function create_orbit_agent_protocol_node(string $name, string $wireGuardAddress): Node
{
    return Node::factory()
        ->appDev(['tld' => "{$name}.app"])
        ->orbitAgentCapable()
        ->create([
            'name' => $name,
            'host' => "{$name}.test",
            'platform' => 'macos_15-5',
            'wireguard_address' => $wireGuardAddress,
            'status' => 'active',
        ]);
}

function post_orbit_agent_protocol_json(Node $node, string $uri, array $payload = []): TestResponse
{
    return test()->call('POST', $uri, $payload, [], [], ['REMOTE_ADDR' => $node->wireguard_address]);
}

function queue_orbit_agent_noop_job(Node $node, array $payload = ['reason' => 'protocol-smoke']): OrbitAgentJob
{
    return app(OrbitAgentJobDispatcher::class)->queueNoop(targetNode: $node, payload: $payload);
}

function queue_orbit_agent_app_dev_convergence_job(Node $node): OrbitAgentJob
{
    $assignment = NodeRoleAssignment::query()
        ->where('node_id', $node->id)
        ->where('role', 'app-dev')
        ->sole();
    $tld = $assignment->settings['tld'] ?? null;

    expect($tld)->toBeString();

    return app(OrbitAgentJobDispatcher::class)->queueAppDevConvergence(
        targetNode: $node,
        tld: $tld,
    );
}

it('claims a queued typed noop job only for the authenticated target node', function (): void {
    $nodeA = create_orbit_agent_protocol_node(name: 'agent-a', wireGuardAddress: '10.6.0.41');
    $nodeB = create_orbit_agent_protocol_node(name: 'agent-b', wireGuardAddress: '10.6.0.42');
    $job = queue_orbit_agent_noop_job(node: $nodeA);

    $this->postJson('/api/orbit-agent/jobs/claim')
        ->assertForbidden();

    post_orbit_agent_protocol_json(node: $nodeB, uri: '/api/orbit-agent/jobs/claim')
        ->assertNotFound();

    $response = post_orbit_agent_protocol_json(node: $nodeA, uri: '/api/orbit-agent/jobs/claim')
        ->assertOk();

    $response->assertJson(
        fn (AssertableJson $json) => $json
            ->where('job.id', $job->id)
            ->where('job.type', 'noop')
            ->where('job.target_node.name', 'agent-a')
            ->has('job.payload', fn (AssertableJson $json) => $json
                ->where('reason', 'protocol-smoke')
                ->missing('command')
                ->missing('argv')
                ->missing('shell')
                ->missing('password')
                ->missing('secret')
                ->etc())
            ->etc(),
    );

    expect($job->refresh()->status)->toBe('accepted');
});

it('claims a queued typed app-dev convergence job only for the authenticated target node', function (): void {
    $node = create_orbit_agent_protocol_node(name: 'mac-app-dev', wireGuardAddress: '10.6.0.45');
    $job = queue_orbit_agent_app_dev_convergence_job($node);

    $response = post_orbit_agent_protocol_json(node: $node, uri: '/api/orbit-agent/jobs/claim')
        ->assertOk();

    $response->assertJson(
        fn (AssertableJson $json) => $json
            ->where('job.id', $job->id)
            ->where('job.type', 'app-dev-convergence')
            ->where('job.target_node.name', 'mac-app-dev')
            ->has('job.payload', fn (AssertableJson $json) => $json
                ->where('operation', 'app_dev_convergence')
                ->where('role', 'app-dev')
                ->where('tld', 'mac-app-dev.app')
                ->where('tools', ['caddy', 'composer', 'docker', 'laravel-installer', 'php-cli'])
                ->missing('command')
                ->missing('argv')
                ->missing('shell')
                ->etc())
            ->etc(),
    );

    expect($job->refresh()->status)->toBe('accepted');
});

it('only queues and claims jobs for active agent-capable nodes', function (): void {
    $node = Node::factory()->create([
        'name' => 'worker-a',
        'host' => 'worker-a.test',
        'wireguard_address' => '10.6.0.43',
        'status' => 'active',
    ]);
    $agentRoleNode = Node::factory()
        ->agent()
        ->create([
            'name' => 'agent-role-a',
            'host' => 'agent-role-a.test',
            'wireguard_address' => '10.6.0.44',
            'status' => 'active',
        ]);

    expect(fn () => queue_orbit_agent_noop_job(node: $node))
        ->toThrow(\InvalidArgumentException::class, 'Orbit Agent jobs require an active agent-capable node.');

    post_orbit_agent_protocol_json(node: $node, uri: '/api/orbit-agent/jobs/claim')
        ->assertNotFound();

    expect(fn () => queue_orbit_agent_noop_job(node: $agentRoleNode))
        ->toThrow(\InvalidArgumentException::class, 'Orbit Agent jobs require an active agent-capable node.');

    post_orbit_agent_protocol_json(node: $agentRoleNode, uri: '/api/orbit-agent/jobs/claim')
        ->assertNotFound();
});

it('records successful lifecycle events into operation and activity history without unsafe payloads', function (): void {
    $node = create_orbit_agent_protocol_node(name: 'agent-a', wireGuardAddress: '10.6.0.41');
    $job = queue_orbit_agent_noop_job(node: $node, payload: [
        'reason' => 'lifecycle-proof',
    ]);

    post_orbit_agent_protocol_json(node: $node, uri: '/api/orbit-agent/jobs/claim')->assertOk();

    foreach (['accepted', 'running', 'privilege_requested', 'succeeded'] as $event) {
        post_orbit_agent_protocol_json(node: $node, uri: "/api/orbit-agent/jobs/{$job->id}/events", payload: [
            'event' => $event,
            'payload' => [
                'message' => "agent event {$event}",
            ],
        ])->assertOk();
    }

    $job->refresh();

    expect($job->status)
        ->toBe('succeeded')
        ->and($job->operation_run_id)
        ->not->toBeNull();

    $operationRun = OperationRun::query()->findOrFail($job->operation_run_id);

    expect($operationRun->status->value)
        ->toBe('succeeded')
        ->and(OperationEvent::query()->where('operation_run_id', $operationRun->id)->count())
        ->toBeGreaterThanOrEqual(4);

    $activityProperties = json_encode(
        DB::table('activity_log')->pluck('properties')->all(),
        JSON_THROW_ON_ERROR,
    );
    $operationPayloads = json_encode(
        OperationEvent::query()->where('operation_run_id', $operationRun->id)->pluck('payload')->all(),
        JSON_THROW_ON_ERROR,
    );

    foreach ([
        'command',
        'argv',
        'shell',
        'operation_token',
        'bearer',
        'password',
        'secret',
        'api_key',
        'BEGIN PRIVATE KEY',
    ] as $unsafe) {
        expect($activityProperties)
            ->not->toContain($unsafe)->and($operationPayloads)
            ->not->toContain($unsafe);
    }
});

it('records failed lifecycle events and rejects wrong-node reports without mutating the job', function (): void {
    $nodeA = create_orbit_agent_protocol_node(name: 'agent-a', wireGuardAddress: '10.6.0.41');
    $nodeB = create_orbit_agent_protocol_node(name: 'agent-b', wireGuardAddress: '10.6.0.42');
    $job = queue_orbit_agent_noop_job(node: $nodeA);

    post_orbit_agent_protocol_json(node: $nodeA, uri: '/api/orbit-agent/jobs/claim')->assertOk();

    post_orbit_agent_protocol_json(node: $nodeB, uri: "/api/orbit-agent/jobs/{$job->id}/events", payload: [
        'event' => 'succeeded',
        'payload' => [
            'message' => 'wrong node tried to finish the job',
        ],
    ])->assertNotFound();

    expect($job->refresh()->status)->toBe('accepted');

    post_orbit_agent_protocol_json(node: $nodeA, uri: "/api/orbit-agent/jobs/{$job->id}/events", payload: [
        'event' => 'failed',
        'payload' => [
            'message' => 'noop failed deliberately',
        ],
    ])->assertOk();

    $job->refresh();

    expect($job->status)
        ->toBe('failed')
        ->and($job->operation_run_id)
        ->not->toBeNull();

    $operationRun = OperationRun::query()->findOrFail($job->operation_run_id);

    expect($operationRun->status->value)
        ->toBe('failed')
        ->and(OperationEvent::query()->where('operation_run_id', $operationRun->id)->count())
        ->toBeGreaterThanOrEqual(2);
});

it('rejects shell-style jobs and lifecycle payloads at the public contract boundary', function (): void {
    $node = create_orbit_agent_protocol_node(name: 'agent-a', wireGuardAddress: '10.6.0.41');
    $apiKey = implode('', ['api', 'Key']);
    $operationToken = implode('_', ['operation', 'token']);
    $sensitiveValue = str_repeat('x', times: 16);

    expect(fn () => app(OrbitAgentJobDispatcher::class)->queue(targetNode: $node, type: 'shell', payload: [
        'command' => 'cat /etc/passwd',
    ]))->toThrow(\InvalidArgumentException::class, 'Orbit Agent jobs only support typed noop and app-dev convergence work.');

    expect(fn () => app(OrbitAgentJobDispatcher::class)->queue(
        targetNode: $node,
        type: 'app-dev-convergence',
        payload: [
            'operation' => 'app_dev_convergence',
            'role' => 'app-dev',
            'tld' => 'test',
            'tools' => ['docker'],
            'shell' => 'cat /etc/passwd',
        ],
    ))
        ->toThrow(OperationPayloadRejected::class);

    expect(fn () => app(OrbitAgentJobDispatcher::class)->queueNoop(targetNode: $node, payload: [
        $apiKey => $sensitiveValue,
    ]))
        ->toThrow(OperationPayloadRejected::class);

    $job = queue_orbit_agent_noop_job(node: $node);

    post_orbit_agent_protocol_json(node: $node, uri: '/api/orbit-agent/jobs/claim')->assertOk();

    post_orbit_agent_protocol_json(node: $node, uri: "/api/orbit-agent/jobs/{$job->id}/events", payload: [
        'event' => 'running',
        'payload' => [
            'argv' => ['cat', '/etc/passwd'],
            $operationToken => $sensitiveValue,
            'pem' => "-----BEGIN PRIVATE KEY-----\nredacted\n-----END PRIVATE KEY-----",
        ],
    ])->assertUnprocessable();

    $activityProperties = json_encode(
        DB::table('activity_log')->pluck('properties')->all(),
        JSON_THROW_ON_ERROR,
    );

    foreach (['cat /etc/passwd', 'argv', $operationToken, $sensitiveValue, 'BEGIN PRIVATE KEY'] as $unsafe) {
        expect($activityProperties)->not->toContain($unsafe);
    }
});
