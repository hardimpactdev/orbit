<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\DevelopmentDeployPrecheck;
use App\Domain\Projects\ProjectSourceAccess;
use App\Domain\Projects\ProjectSourceReader;
use App\Domain\SourceControl\RemoteBranchHeads;
use App\Jobs\Instances\CheckDevelopmentDeployments;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(LazilyRefreshDatabase::class);

function gatewayTickDefault(): Instance
{
    Queue::fake();
    $node = Node::withoutEvents(fn () => Node::query()->create(['name' => 'tick-node', 'status' => 'active', 'platform' => 'linux',
        'wireguard_ip' => '10.44.0.7', 'public_ssh_host' => '10.44.0.7', 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-key']));
    $node->forceFill(['instance_bridge' => '10.253.7.0/24'])->save();
    $node->roles()->create(['role' => 'app-dev', 'status' => 'active']);
    $control = Node::withoutEvents(fn () => Node::query()->create(['name' => 'tick-control', 'status' => 'active', 'platform' => 'linux', 'user' => 'orbit', 'wireguard_ip' => '10.44.0.209', 'public_ssh_host' => '10.44.0.209']));
    $control->roles()->create(['role' => 'swamp', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'tick-project', 'slug' => 'tick-project', 'type' => 'node-package',
        'repository_url' => 'https://github.com/example/tick-project.git', 'default_branch' => 'main', 'root' => '.']);
    $instance = Instance::withoutEvents(fn () => Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id,
        'name' => 'default', 'status' => 'active', 'source_layout' => 'checkout', 'checkout_path' => '/srv/tick-project/default', 'auto_deploy' => true]));
    InstanceRuntime::query()->create(['instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => 'orbit-instances',
        'container_name' => 'instance-'.$instance->id, 'bridge_address' => '10.253.7.2', 'runtime_version' => 'r', 'agent_version' => 'a',
        'power_state' => 'stopped', 'seed_commit' => str_repeat('a', 40)]);
    app()->instance(ProjectSourceReader::class, new class implements ProjectSourceReader
    {
        public function read(string $repositoryUrl, ProjectSourceAccess $access, string $branch, array $paths, ?string $commit = null): array
        {
            return ['head' => $commit ?? str_repeat('b', 40), 'files' => array_fill_keys($paths, null)];
        }
    });

    return $instance;
}

it('does no remote read or admission for an off default', function (): void {
    $instance = gatewayTickDefault();
    $instance->update(['auto_deploy' => false]);
    $this->mock(RemoteBranchHeads::class)->shouldNotReceive('head');
    app(DevelopmentDeployPrecheck::class)->check();
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('reads an eligible Project head once and starts no native policy or deployment for unchanged seeds', function (): void {
    $instance = gatewayTickDefault();
    $this->mock(RemoteBranchHeads::class)->shouldReceive('head')->once()->andReturn(str_repeat('a', 40));
    app(DevelopmentDeployPrecheck::class)->check();
    expect(Operation::query()->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('admits a changed eligible head as ordinary instance.deploy and keeps its exact pin', function (): void {
    $instance = gatewayTickDefault();
    $this->mock(RemoteBranchHeads::class)->shouldReceive('head')->once()->andReturn(str_repeat('b', 40));
    app(DevelopmentDeployPrecheck::class)->check();
    $operation = Operation::query()->sole();
    expect($operation->kind)->toBe('instance.deploy')->and($operation->target_id)->toBe($instance->id)
        ->and($operation->input['commit'])->toBe(str_repeat('b', 40))->and($operation->input['development_policy'])->toBeTrue();
});

it('dispatches a unique minute job and keeps an execution overlap lock after uniqueness expires', function (): void {
    $job = new CheckDevelopmentDeployments;
    expect($job)->toBeInstanceOf(ShouldBeUnique::class)
        ->and($job->queue)->toBe('tasks')->and($job->middleware())->toHaveCount(1)
        ->and($job->middleware()[0]->expiresAfter)->toBe($job->uniqueFor);
    $this->artisan('schedule:list', ['--json' => true])->assertSuccessful();
    $events = app(Schedule::class)->events();
    expect(array_any($events, fn ($event) => str_contains($event->description ?? '', CheckDevelopmentDeployments::class) && $event->expression === '* * * * *'))->toBeTrue();
});
