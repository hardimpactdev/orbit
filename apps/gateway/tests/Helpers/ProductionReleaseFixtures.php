<?php

declare(strict_types=1);

use App\Actions\Tools\PrepareVpEnvironmentAction;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Infrastructure\Instances\RemoteProductionDeployment;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Tests\Support\FakeAgentCommandRunner;

/**
 * A production Instance and its deployment with a fake agent runner. `$configure` sets the runner's answers.
 *
 * @return array{RemoteProductionDeployment, FakeAgentCommandRunner, Instance} */
function release_commands(?Closure $configure = null): array
{
    $node = Node::query()->create(['name' => 'deployment-node', 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64', 'public_ssh_host' => '192.0.2.219', 'wireguard_ip' => '10.44.0.219', 'user' => 'orbit']);
    $node->roles()->create(['role' => 'app-prod', 'status' => 'active']);
    $project = Project::query()->create(['name' => 'Deployment', 'slug' => 'deployment', 'repository_url' => 'https://example.test/deployment.git', 'default_branch' => 'main', 'root' => 'public']);
    $instance = Instance::query()->create([
        'project_id' => $project->id, 'node_id' => $node->id, 'name' => 'production', 'environment' => 'production',
        'source_layout' => 'release', 'checkout_path' => '/home/orbit-app-1/releases/initial', 'production_user' => 'orbit-app-1',
        'production_home' => '/home/orbit-app-1', 'root' => 'public', 'branch' => 'main', 'provisioning_step' => 'active', 'status' => 'active',
    ]);
    $agents = new FakeAgentCommandRunner;
    if ($configure !== null) {
        $configure($agents);
    }
    $deployment = new RemoteProductionDeployment($agents, app(RepositoryReadAccess::class), app(PrepareVpEnvironmentAction::class), static fn (): string => '20260911-a1');

    return [$deployment, $agents, $instance];
}
