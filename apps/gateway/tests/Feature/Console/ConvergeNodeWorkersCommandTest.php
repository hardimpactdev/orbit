<?php

declare(strict_types=1);

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
    $attributes = ['platform' => 'linux', 'architecture' => 'x86_64', 'status' => 'active', 'ssh_host_fingerprint' => 'SHA256:fixture'];
    $this->pilot = Node::query()->create($attributes + ['name' => 'pilot', 'wireguard_ip' => '10.44.0.81', 'public_ssh_host' => '192.0.2.81']);
    $this->issuer = Node::query()->create($attributes + ['name' => 'issuer', 'wireguard_ip' => '10.44.0.82', 'public_ssh_host' => '192.0.2.82']);
    config(['swamp_workers.nodes' => [$this->pilot->id], 'swamp_workers.issuer_node_id' => $this->issuer->id]);
    $this->runner = Mockery::mock(AgentCommandRunner::class);
    app()->instance(AgentCommandRunner::class, $this->runner);
});

it('uses the existing Gateway scheduler every two minutes only for an enabled rollout', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($event): bool => $event->description === 'orbit:node-workers'
        || str_contains((string) $event->command, 'orbit:node-workers'));
    expect($event)->not->toBeNull()->and($event->expression)->toBe('*/2 * * * *')->and($event->filtersPass(app()))->toBeTrue();
    config(['swamp_workers.nodes' => []]);
    expect($event->filtersPass(app()))->toBeFalse();
});

it('leaves a fresh or draining worker alone without reconverging the agent', function (array $state): void {
    $this->runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.start-worker' && $input === ['node' => true, 'enrollment_id' => 'node-worker', 'workspace' => '/var/lib/orbit-node-worker/home', 'orchestrator_url' => 'wss://swamp.orbit', 'labels' => ['role' => 'node'], 'server_token_required' => true])->andReturn($state);
    $this->artisan('orbit:node-workers')->assertSuccessful();
})->with([[['started' => true, 'drained' => false]], [['started' => false, 'drained' => false]]]);

it('issues only after the target is drained and delivers without an Operation or Activity', function (): void {
    $pair = ['enrollment_token' => 'orbit-node-'.$this->pilot->id.'.enrollmentSentinel',
        'server_token' => 'orbit-node-'.$this->pilot->id.'-access.serverSentinel', 'expires_at' => now()->addDay()->toIso8601String()];
    $this->runner->shouldReceive('run')->once()->ordered()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.start-worker' && $input === ['node' => true, 'enrollment_id' => 'node-worker', 'workspace' => '/var/lib/orbit-node-worker/home', 'orchestrator_url' => 'wss://swamp.orbit', 'labels' => ['role' => 'node'], 'server_token_required' => true])->andReturn(['started' => false, 'drained' => true]);
    $this->runner->shouldReceive('run')->once()->ordered()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->issuer)
        && $name === 'swamp.issue-node-worker' && $input === ['node_id' => $this->pilot->id])->andReturn($pair);
    $this->runner->shouldReceive('run')->once()->ordered()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.start-worker' && $input['node'] === true && $input['enrollment_token'] === $pair['enrollment_token'])->andReturn(['started' => true, 'drained' => false]);
    $activities = Activity::query()->count();
    $operations = Operation::query()->count();
    $this->artisan('orbit:node-workers')->assertSuccessful();
    expect(Activity::query()->count())->toBe($activities)->and(Operation::query()->count())->toBe($operations);
});

it('keeps the target stopped on issuer failure or a pair for another Node and hides diagnostics', function (bool $throws): void {
    $this->runner->shouldReceive('run')->once()->withArgs(fn (Node $node, string $name, array $input): bool => $node->is($this->pilot)
        && $name === 'swamp.start-worker' && $input === ['node' => true, 'enrollment_id' => 'node-worker', 'workspace' => '/var/lib/orbit-node-worker/home', 'orchestrator_url' => 'wss://swamp.orbit', 'labels' => ['role' => 'node'], 'server_token_required' => true])->andReturn(['started' => false, 'drained' => true]);
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
    } elseif ($reason === 'architecture') {
        $this->pilot->update(['architecture' => 'aarch64']);
    } elseif ($reason === 'task-settings') {
        $this->pilot->update(['name' => 'beast', 'settings' => ['task_vms' => []]]);
    } else {
        $this->pilot->update(['name' => 'beast']);
        $project = Project::query()->create(['name' => 'Worker task', 'slug' => 'worker-task', 'repository_url' => 'git@github.com:acme/worker-task.git']);
        $group = Task::topLevel()->create(['project_id' => $project->id, 'title' => 'Task worker', 'brief' => 'Task worker', 'status' => 'backlog']);
        TaskVm::query()->create(['group_id' => $group->id, 'node_id' => $reason === 'host' ? $this->issuer->id : $this->pilot->id, 'host_node_id' => $reason === 'host' ? $this->pilot->id : $this->issuer->id,
            'provider' => 'incus', 'name' => 'tvm-worker', 'wireguard_ip' => $this->pilot->wireguard_ip, 'state' => 'ready']);
    }
    $this->runner->shouldNotReceive('run');
    $this->artisan('orbit:node-workers')->assertSuccessful();
})->with(['disabled', 'macos', 'architecture', 'task-vm', 'host', 'task-settings']);
