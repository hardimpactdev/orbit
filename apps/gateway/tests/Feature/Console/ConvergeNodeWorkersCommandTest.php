<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeAgentRuntime;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Models\Activity;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskVm;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;

beforeEach(function (): void {
    $attributes = ['platform' => 'linux', 'status' => 'active', 'ssh_host_fingerprint' => 'SHA256:fixture'];
    $this->pilot = Node::query()->create($attributes + ['name' => 'pilot', 'wireguard_ip' => '10.44.0.81', 'public_ssh_host' => '192.0.2.81']);
    $this->issuer = Node::query()->create($attributes + ['name' => 'issuer', 'wireguard_ip' => '10.44.0.82', 'public_ssh_host' => '192.0.2.82']);
    config(['swamp_workers.nodes' => [$this->pilot->id], 'swamp_workers.issuer_node_id' => $this->issuer->id]);
    $this->runtime = Mockery::mock(NodeAgentRuntime::class);
    $this->runner = Mockery::mock(AgentCommandRunner::class);
    app()->instance(NodeAgentRuntime::class, $this->runtime);
    app()->instance(AgentCommandRunner::class, $this->runner);
});

it('uses the existing hourly Gateway scheduler only for an enabled rollout', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'orbit:node-workers'
        || str_contains((string) $event->command, 'orbit:node-workers'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('0 * * * *')->and($event->filtersPass(app()))->toBeTrue();
    config(['swamp_workers.nodes' => []]);
    expect($event->filtersPass(app()))->toBeFalse();
});

it('leaves a fresh or draining worker alone after ordinary SSH converge', function (array $state): void {
    $this->runtime->shouldReceive('converge')->once()->withArgs(fn (Node $node): bool => $node->is($this->pilot));
    $this->runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.converge-node-worker' && $input === [])->andReturn($state);
    $this->artisan('orbit:node-workers')->assertSuccessful();
})->with([[['ready' => true, 'drained' => false]], [['ready' => false, 'drained' => false]]]);

it('issues only after the target is drained and delivers without an Operation or Activity', function (): void {
    $pair = ['enrollment_token' => 'orbit-node-'.$this->pilot->id.'.enrollmentSentinel',
        'server_token' => 'orbit-node-'.$this->pilot->id.'-access.serverSentinel', 'expires_at' => now()->addDay()->toIso8601String()];
    $this->runtime->shouldReceive('converge')->once();
    $this->runner->shouldReceive('run')->once()->ordered()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.converge-node-worker' && $input === [])->andReturn(['ready' => false, 'drained' => true]);
    $this->runner->shouldReceive('run')->once()->ordered()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->issuer)
        && $name === 'swamp.issue-node-worker' && $input === ['node_id' => $this->pilot->id])->andReturn($pair);
    $this->runner->shouldReceive('run')->once()->ordered()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.converge-node-worker' && $input === ['credentials' => $pair])->andReturn(['ready' => true, 'drained' => false]);
    $activities = Activity::query()->count();
    $operations = Operation::query()->count();
    $this->artisan('orbit:node-workers')->assertSuccessful();
    expect(Activity::query()->count())->toBe($activities)->and(Operation::query()->count())->toBe($operations);
});

it('keeps the target stopped on issuer failure or a pair for another Node and hides diagnostics', function (bool $throws): void {
    $this->runtime->shouldReceive('converge')->once();
    $this->runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.converge-node-worker' && $input === [])->andReturn(['ready' => false, 'drained' => true]);
    $mint = $this->runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name): bool => $node->is($this->issuer) && $name === 'swamp.issue-node-worker');
    if ($throws) {
        $mint->andThrow(new RuntimeException('private-issuer-sentinel'));
    } else {
        $mint->andReturn(['enrollment_token' => 'another.private-issuer-sentinel', 'server_token' => 'other', 'expires_at' => 'later']);
    }
    expect(Artisan::call('orbit:node-workers'))->toBe(1)
        ->and(Artisan::output())->not->toContain('private-issuer-sentinel');
})->with([true, false]);

it('skips disabled non-Linux and task-VM Nodes before any command', function (string $reason): void {
    if ($reason === 'disabled') {
        config(['swamp_workers.nodes' => []]);
    } elseif ($reason === 'macos') {
        $this->pilot->update(['platform' => 'macos']);
    } else {
        $project = Project::query()->create(['name' => 'Worker task', 'slug' => 'worker-task', 'repository_url' => 'git@github.com:acme/worker-task.git']);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Task worker', 'brief' => 'Task worker', 'status' => 'backlog']);
        TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $this->pilot->id, 'host_node_id' => $this->issuer->id,
            'provider' => 'incus', 'name' => 'tvm-worker', 'wireguard_ip' => $this->pilot->wireguard_ip, 'state' => 'ready']);
    }
    $this->runtime->shouldNotReceive('converge');
    $this->runner->shouldNotReceive('run');
    $this->artisan('orbit:node-workers')->assertSuccessful();
})->with(['disabled', 'macos', 'task-vm']);
