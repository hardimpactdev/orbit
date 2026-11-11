<?php

declare(strict_types=1);

use App\Domain\Instances\Deployment\InstanceDeploymentIntent;
use App\Domain\Instances\Deployment\InstanceDeployProjection;
use App\Domain\Nodes\RoleName;
use App\Domain\Operations\OperationStatus;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

it('keeps the observed release SHA in history when an unpinned production deploy fails later', function (): void {
    $this->freezeTime();
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => 'production', 'status' => 'active', 'platform' => 'linux', 'wireguard_ip' => '10.44.0.2',
        'public_ssh_host' => '10.44.0.2', 'user' => 'orbit',
    ]));
    $node->roles()->create(['role' => RoleName::AppProd, 'status' => 'active']);
    $project = Project::query()->create(['name' => 'site', 'slug' => 'site', 'repository_url' => 'https://example.test/site.git',
        'default_branch' => 'main', 'root' => '.']);
    $instance = Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => 'default',
        'environment' => 'production', 'status' => 'active', 'branch' => 'main', 'root' => '.',
        'production_user' => 'orbit-app-'.$project->id, 'production_home' => '/home/orbit-app-'.$project->id,
        'checkout_path' => '/home/orbit-app-'.$project->id.'/releases/initial']);
    $operation = Operation::query()->create(['kind' => 'instance.deploy', 'target_type' => 'instance', 'target_id' => $instance->id,
        'node_id' => $node->id, 'status' => OperationStatus::Running, 'triggered_by' => 'operator',
        'input' => ['instance_id' => $instance->id, 'deployment' => app(InstanceDeploymentIntent::class)->snapshot($instance), 'commit' => null,
            'runtime_definitions' => ['processes' => [], 'schedules' => [], 'restart' => []]],
        'state' => ['swamp_run_id' => 'production-failed-run']]);
    $commit = str_repeat('b', 40);

    $projected = app(InstanceDeployProjection::class)->apply($operation, ['status' => 'failed', 'run_id' => 'production-failed-run',
        'started_at' => now()->toIso8601String(), 'finished_at' => now()->toIso8601String(), 'result' => ['steps' => [
            'Read the release commit' => ['status' => 'succeeded', 'stdout' => $commit."\n"],
            'Prepare shared paths' => ['status' => 'failed'],
        ], 'summary' => ['failed_steps' => ['Prepare shared paths']]]]);

    $this->assertDatabaseHas('instance_deployments', ['instance_id' => $instance->id, 'status' => 'failed',
        'release' => InstanceDeploymentIntent::releaseName($operation), 'commit' => $commit,
        'failed_step' => 'Prepare shared paths', 'error_code' => 'deployment.source_failed']);
    expect($projected->status)->toBe(OperationStatus::Failed)->and($projected->result['commit'])->toBe($commit);
    expect($instance->fresh()->checkout_path)->toBe('/home/orbit-app-'.$project->id.'/releases/initial');
});
