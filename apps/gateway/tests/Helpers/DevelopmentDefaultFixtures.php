<?php

declare(strict_types=1);

use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;

function dev935_instance(string $slug = 'dev935', string $name = 'default', string $role = 'app-dev'): Instance
{
    $node = Node::query()->create(['name' => $slug, 'platform' => 'linux', 'status' => 'active', 'user' => 'orbit', 'public_ssh_host' => '192.0.2.35', 'wireguard_ip' => '10.44.0.'.(Node::query()->count() + 35)]);
    $node->roles()->create(['role' => $role, 'status' => 'active']);
    $project = Project::query()->create(['name' => $slug, 'slug' => $slug, 'type' => 'monorepo', 'repository_url' => 'https://example.test/'.$slug.'.git', 'default_branch' => 'main']);

    return Instance::query()->create(['project_id' => $project->id, 'node_id' => $node->id, 'name' => $name, 'checkout_path' => '/fast/apps/'.$slug.'/'.$name, 'source_layout' => 'checkout', 'branch' => 'main', 'status' => 'active']);
}

/** @param list<DevelopmentDeployStep> $steps */
function dev935_steps(Instance $instance, array $steps): void
{
    foreach ($steps as $step) {
        app(ProjectDevelopmentDeployStepStore::class)->create($instance->project, $step, null, null);
    }
}
