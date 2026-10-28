<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tools\ToolStatus;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Tool;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    $this->gateway = toolOperationNode('gateway', '10.44.0.2');
    $this->markAsGateway($this->gateway);
    $this->node = toolOperationNode('dev', '10.44.0.3');
    $this->withServerVariables(['REMOTE_ADDR' => $this->gateway->wireguard_ip]);
    Queue::fake();
    $agents = Mockery::mock(AgentCommandRunner::class);
    $agents->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $agents);
});

it('admits keyed installs without manager execution or premature Tool ownership', function (): void {
    $body = ['node_id' => $this->node->id, 'manager' => 'apt', 'package' => 'jq', 'version_constraint' => '^1.0'];
    $start = $this->withHeader('Idempotency-Key', 'tools-proof')->postJson('/api/v1/tools', $body)->assertAccepted();
    $this->postJson('/api/v1/tools', $body)->assertAccepted()->assertJsonPath('data.id', $start->json('data.id'));
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('tool.install')->and($operation->node_id)->toBe($this->node->id)
        ->and($operation->input['constraint_intervals'])->not->toBeEmpty()->and(Tool::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('keeps adopt admission free of package and manager mutations', function (): void {
    $this->postJson('/api/v1/tools/adopt', ['node_id' => $this->node->id, 'manager' => 'vp', 'package' => 'is-number'])
        ->assertAccepted()->assertJsonPath('data.kind', 'tool.adopt');
    expect(Tool::query()->count())->toBe(0)->and($this->node->toolManagers()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('rejects unsupported packages and constraints before admission', function (string $manager, string $package, ?string $constraint, string $code): void {
    $this->postJson('/api/v1/tools', ['node_id' => $this->node->id, 'manager' => $manager, 'package' => $package, 'version_constraint' => $constraint])
        ->assertUnprocessable()->assertJsonPath('error.code', $code);
    expect(Operation::query()->count())->toBe(0);
})->with([
    ['npm', 'is-number', null, 'tool.manager_unsupported'],
    ['apt', '--help', null, 'tool.package_invalid'],
    ['brew', 'foreign/tap/package', null, 'tool.package_invalid'],
    ['vp', 'https://example.test/pkg', null, 'tool.package_invalid'],
    ['composer', 'pkg', null, 'tool.package_invalid'],
    ['apt', 'jq', 'not a range', 'tool.constraint_invalid'],
]);

it('protects Node prerequisites and refuses role-owned packages', function (string $manager, string $package, string $code): void {
    $this->postJson('/api/v1/tools', ['node_id' => $this->node->id, 'manager' => $manager, 'package' => $package])
        ->assertConflict()->assertJsonPath('error.code', $code);
    expect(Operation::query()->count())->toBe(0);
})->with([
    ['apt', 'docker.io', 'tool.package_protected'], ['apt', 'openssh-server', 'tool.package_protected'],
    ['apt', 'php8.5-cli', 'tool.package_role_owned'], ['vp', 'pnpm', 'tool.package_protected'],
    ['brew', 'wireguard-go', 'tool.package_protected'],
]);

it('admits update and removal against the exact recorded identity', function (string $verb): void {
    $record = $this->node->toolManagers()->create(['name' => 'vp', 'status' => LifecycleStatus::Active]);
    $tool = $this->node->tools()->create(['tool_manager_id' => $record->id, 'package' => 'is-number', 'status' => ToolStatus::Installed, 'installed_version' => '7.0.0']);
    $response = $verb === 'update'
        ? $this->postJson('/api/v1/tools/'.$tool->id.'/update')
        : $this->deleteJson('/api/v1/tools/'.$tool->id);
    $response->assertAccepted()->assertJsonPath('data.kind', 'tool.'.$verb);
    expect(Operation::query()->sole()->input['tool_id'])->toBe($tool->id)->and($tool->refresh()->status)->toBe(ToolStatus::Installed);
    Queue::assertNothingPushed();
})->with(['update', 'remove']);

it('replays a completed removal after the Tool row has gone and still enforces Node access', function (): void {
    $record = $this->node->toolManagers()->create(['name' => 'vp', 'status' => LifecycleStatus::Active]);
    $tool = $this->node->tools()->create(['tool_manager_id' => $record->id, 'package' => 'is-number', 'status' => ToolStatus::Installed]);
    $path = '/api/v1/tools/'.$tool->id;
    $response = $this->withHeader('Idempotency-Key', 'remove-proof')->deleteJson($path)->assertAccepted();
    $tool->delete();
    $operation = Operation::query()->sole();
    $operation->update(['status' => 'succeeded', 'lock_key' => null]);
    $this->deleteJson($path)->assertAccepted()->assertJsonPath('data.id', $response->json('data.id'));
    $other = toolOperationNode('other', '10.44.0.4');
    $this->withServerVariables(['REMOTE_ADDR' => $other->wireguard_ip])->deleteJson($path)->assertForbidden();
});

it('rejects malformed strict input without exposing caller fields', function (): void {
    $this->postJson('/api/v1/tools', ['node_id' => $this->node->id, 'manager' => 'vp', 'package' => 'is-number', 'script' => 'secret-sentinel'])
        ->assertUnprocessable()->assertDontSee('secret-sentinel');
    expect(Operation::query()->count())->toBe(0);
});

function toolOperationNode(string $name, string $address): Node
{
    return Node::query()->create(['name' => $name, 'status' => LifecycleStatus::Active, 'platform' => 'linux', 'architecture' => 'x86_64',
        'public_ssh_host' => '192.0.2.20', 'public_ssh_port' => 22, 'user' => 'orbit', 'wireguard_ip' => $address,
        'ssh_host_fingerprint' => 'SHA256:'.str_repeat('a', 43)]);
}
