<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Models\Node;
use App\Models\Operation;
use Orbit\Sdk\Requests\Nodes\AddNodeRequest;
use Orbit\Sdk\Requests\Operations\ShowOperationRequest;
use Tests\Support\NativeEnrollmentFixtures;

it('records public terminal enrollment Operations for client replay', function (string $fixture, string $platform, string $status): void {
    $this->travelTo(new DateTimeImmutable('2026-11-01T00:00:00+00:00'));
    $gateway = $this->markAsGateway(Node::query()->create([
        'name' => 'gateway', 'status' => LifecycleStatus::Active, 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.1', 'public_ssh_host' => '192.0.2.1',
    ]));
    $node = Node::query()->create(['name' => $platform === 'macos' ? 'mini' : 'app-dev',
        'platform' => $platform, 'status' => $status === 'succeeded' ? LifecycleStatus::Active : LifecycleStatus::Failed,
        'wireguard_ip' => '10.44.0.3', 'public_ssh_host' => '192.0.2.3']);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    $operation = Operation::query()->create([
        'kind' => 'node.add', 'target_type' => 'node', 'target_id' => $node->id, 'node_id' => $node->id,
        'input' => [], 'status' => $status, 'sequence' => 3, 'started_at' => now(), 'finished_at' => now(),
        'error_code' => $status === 'failed' ? 'node.enrollment_failed' : null,
        'result' => ['node_id' => $node->id, 'enrolled' => $status === 'succeeded', 'restored' => $status === 'failed'],
    ]);

    record_fixture($this->getJson('/api/v1/operations/'.$operation->id)->assertOk()
        ->assertJsonPath('data.kind', 'node.add')->assertJsonPath('data.status', $status),
        $fixture, ShowOperationRequest::class, 'GET /api/v1/operations/{operation}');
})->with([
    ['nodes/node-add/created', 'linux', 'succeeded'],
    ['nodes/node-add/macos-enrolled', 'macos', 'succeeded'],
    ['nodes/node-add/restored-failure', 'linux', 'failed'],
    ['nodes/node-add/cancelled', 'linux', 'cancelled'],
]);

it('records enrollment refusals before reserving a Node or starting a native run', function (): void {
    $fixture = NativeEnrollmentFixtures::bind();
    $gateway = $this->markAsGateway($fixture['gateway']);
    $this->withServerVariables(['REMOTE_ADDR' => $gateway->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());
    $input = ['name' => 'refused-enrollment', 'public_ssh_host' => '192.0.2.40', 'roles' => ['app-dev']];
    record_fixture($this->postJson('/api/v1/nodes', [...$input, 'host_key_fingerprint' => 'SHA256:'.str_repeat('A', 43)])
        ->assertUnprocessable()->assertJsonPath('error.code', 'node.tld_required'),
        'nodes/node-add/tld-required', AddNodeRequest::class, 'POST /api/v1/nodes');
    record_fixture($this->postJson('/api/v1/nodes', [...$input, 'tld' => 'refused.test'])
        ->assertUnprocessable()->assertJsonPath('error.code', 'node.ssh_host_fingerprint_required'),
        'nodes/node-add/fingerprint-required', AddNodeRequest::class, 'POST /api/v1/nodes');

    expect(Node::query()->where('name', 'refused-enrollment')->exists())->toBeFalse()
        ->and(Operation::query()->exists())->toBeFalse()
        ->and($fixture['socket']->sent)->toBe([]);
});
