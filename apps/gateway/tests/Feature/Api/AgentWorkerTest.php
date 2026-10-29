<?php

declare(strict_types=1);

use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;

beforeEach(function (): void {
    $this->pilot = Node::query()->create(['name' => 'pilot-worker', 'platform' => 'linux', 'status' => 'active',
        'public_ssh_host' => '192.0.2.81', 'wireguard_ip' => '10.44.0.81', 'ssh_host_fingerprint' => 'SHA256:pilot']);
    $this->pilot->forceFill(['agent_secret_hash' => hash('sha256', 'pilot-secret')])->save();
    $this->issuer = Node::query()->create(['name' => 'swamp-issuer', 'platform' => 'linux', 'status' => 'active',
        'public_ssh_host' => '192.0.2.82', 'wireguard_ip' => '10.44.0.82', 'ssh_host_fingerprint' => 'SHA256:issuer']);
    config(['swamp_workers.nodes' => [$this->pilot->id], 'swamp_workers.issuer_node_id' => $this->issuer->id]);
    $this->withServerVariables(['REMOTE_ADDR' => $this->pilot->wireguard_ip])->withToken('pilot-secret');
});

it('couriers only the authenticated Node pair without recording credentials', function (): void {
    $pair = ['enrollment_token' => 'orbit-node-'.$this->pilot->id.'.enrollment-sentinel',
        'server_token' => 'orbit-node-'.$this->pilot->id.'-access.server-sentinel', 'expires_at' => '2026-10-30T05:00:00Z'];
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $command, array $input): bool => $node->is($this->issuer) && $command === 'swamp.issue-node-worker' && $input === ['node_id' => $this->pilot->id])->andReturn($pair);
    app()->instance(AgentCommandRunner::class, $runner);
    $activities = Activity::query()->count();
    $operations = Operation::query()->count();

    $this->postJson('/api/v1/agent/swamp-worker')->assertOk()->assertExactJson($pair)->assertHeader('Cache-Control', 'no-store, private');

    expect(Activity::query()->count())->toBe($activities)->and(Operation::query()->count())->toBe($operations);
});

it('refuses a missing or wrong agent secret before contacting the issuer', function (?string $secret): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->withHeaders(['Authorization' => $secret === null ? '' : 'Bearer '.$secret]);
    $response = $this->postJson('/api/v1/agent/swamp-worker');
    $response->assertStatus($secret === null ? 401 : 403)->assertJsonPath('error.code', $secret === null ? 'agent.secret_required' : 'agent.secret_invalid');
})->with([null, 'wrong-secret']);

it('refuses caller-selected targets and token names', function (array $input): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);
    $this->postJson('/api/v1/agent/swamp-worker', $input)->assertUnprocessable()->assertJsonPath('error.code', 'swamp.worker_request_invalid');
})->with([[['node_id' => 999]], [['name' => 'another-worker']]]);

it('keeps rollout disabled by default and excludes non-Linux Nodes', function (): void {
    config(['swamp_workers.nodes' => []]);
    $this->postJson('/api/v1/agent/swamp-worker')->assertForbidden()->assertJsonPath('error.code', 'swamp.worker_not_enabled');
    config(['swamp_workers.nodes' => [$this->pilot->id]]);
    $this->pilot->update(['platform' => 'macos']);
    $this->postJson('/api/v1/agent/swamp-worker')->assertForbidden()->assertJsonPath('error.code', 'swamp.worker_not_enabled');
});

it('refuses a task VM even if its Node ID enters the rollout list', function (): void {
    $project = Project::query()->create(['name' => 'Worker task', 'slug' => 'worker-task', 'repository_url' => 'git@github.com:acme/worker-task.git']);
    $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Task worker', 'brief' => 'Task worker', 'status' => 'backlog']);
    TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $this->pilot->id, 'host_node_id' => $this->issuer->id,
        'provider' => 'incus', 'name' => 'tvm-worker', 'wireguard_ip' => $this->pilot->wireguard_ip, 'state' => 'ready']);
    $runner = Mockery::mock(AgentCommandRunner::class);
    $runner->shouldNotReceive('run');
    app()->instance(AgentCommandRunner::class, $runner);

    $this->postJson('/api/v1/agent/swamp-worker')->assertForbidden()->assertJsonPath('error.code', 'swamp.worker_not_enabled');
});

it('does not leak issuer diagnostics or a mismatched credential pair', function (bool $throws): void {
    $runner = Mockery::mock(AgentCommandRunner::class);
    $call = $runner->shouldReceive('run')->once();
    if ($throws) {
        $call->andThrow(new RuntimeException('private-issuer-sentinel'));
    } else {
        $call->andReturn(['enrollment_token' => 'private-issuer-sentinel', 'server_token' => 'other', 'expires_at' => 'later']);
    }
    app()->instance(AgentCommandRunner::class, $runner);
    $response = $this->postJson('/api/v1/agent/swamp-worker')->assertServiceUnavailable()->assertJsonPath('error.code', 'swamp.issuer_unavailable');
    expect($response->getContent())->not->toContain('private-issuer-sentinel');
})->with([true, false]);
