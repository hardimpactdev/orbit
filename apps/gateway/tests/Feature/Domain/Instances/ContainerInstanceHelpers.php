<?php

declare(strict_types=1);

use App\Actions\Instances\StartDevelopmentInstanceCreateAction;
use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Instances\CreateInstanceData;
use App\Domain\Instances\ContainerNodes;
use App\Domain\Nodes\RoleName;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Models\Project;
use App\Support\ValidatedData;

/* The Nodes, Projects and admitted creates of the container Instance tests, shared by their files. */

function containerDefaultNode(string $name = 'beast', string $ip = '10.44.0.7', bool $listed = true): Node
{
    $node = Node::withoutEvents(fn (): Node => Node::query()->create([
        'name' => $name, 'status' => 'active', 'platform' => 'linux', 'architecture' => 'x86_64',
        'wireguard_ip' => $ip, 'public_ssh_host' => $ip, 'user' => 'orbit', 'ssh_host_key_type' => 'ssh-ed25519', 'ssh_host_key' => 'fixture-public-key',
        'settings' => ['apps' => ['path' => '/home/orbit/apps']],
    ]));
    $node->roles()->create(['role' => RoleName::AppDev, 'status' => 'active']);
    if ($listed) {
        config(['instances.container_nodes' => [...app(ContainerNodes::class)->ids(), $node->id]]);
        app(AllocateInstanceBridgeAction::class)->execute($node);
    }

    return $node->refresh();
}

function containerDefaultProject(ProjectType $type = ProjectType::NodePackage, ?string $php = null): Project
{
    return Project::query()->create(['name' => 'tool', 'slug' => 'tool', 'type' => $type, 'repository_url' => 'https://github.com/nckrtl/tool.git',
        'default_branch' => 'main', 'root' => $type === ProjectType::LaravelApp ? 'public' : '.', 'php_version' => $php, 'is_laravel' => false, 'source_facts_captured_at' => now()]);
}

/** The admitted Operation; its native start stays queued. */
function containerCreate(Project $project, Node $node, string $name = 'default', ?string $databaseServer = null): Operation
{
    return app(StartDevelopmentInstanceCreateAction::class)->execute(
        new CreateInstanceData($project->id, $node->id, $name, null, null, null, $databaseServer), null, null);
}

/** @return array<string, mixed>|null */
function containerIntentOf(Operation $operation): ?array
{
    $container = ValidatedData::object($operation->input['direct_provisioning'])['container'];

    return $container === null ? null : ValidatedData::object($container);
}

function containerRefusal(Closure $create): string
{
    try {
        $create();
    } catch (ResourceOperationException $exception) {
        return $exception->errorCode;
    }

    return 'admitted';
}
