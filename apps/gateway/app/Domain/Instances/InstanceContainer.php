<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\InstanceFolders;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use App\Support\ValidatedData;

/**
 * The container of a Project's default Instance (docs/reference/development-containers.md#the-default-instance): its name, its
 * address on the Node's bridge, its ACL, the FrankenPHP unit and the paths the create and deploy flows use. It runs no T3: the
 * default Instance has no `/etc/orbit/instance`. The run's intent carries it as `container`.
 */
final readonly class InstanceContainer
{
    /** FrankenPHP listens on the container's bridge address; the Node's Caddy proxies the Route to it. */
    public const int WebPort = 8080;

    public const string Image = 'orbit-instance';

    public const string FrankenPhpUnit = 'orbit-frankenphp.service';

    public function __construct(private RecipeImageBuilds $recipes) {}

    public static function name(Instance $instance): string
    {
        return 'instance-'.$instance->id;
    }

    /**
     * The workspace's path on the Node, inside the container's root filesystem. It keeps the Instance's checkout path unique on its
     * Node; Orbit never works in it from the Node.
     */
    public static function workspaceOnNode(Instance $instance): string
    {
        return '/var/lib/incus/storage-pools/'.InstanceHostIntent::Pool.'/containers/'.InstanceHostIntent::Project.'_'.self::name($instance)
            .'/rootfs'.InstanceHostIntent::Workspace;
    }

    /**
     * Refuses a command that works on an Instance's checkout from its Node, for an Instance in a container: its workspace lives in the
     * container. Destroy, setup, the environment file, transfer, rename and clone reach a container in later parts of the runtime.
     */
    public static function assertCheckout(Instance $instance): void
    {
        if ($instance->runtime()->exists()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'This command does not reach an Instance in a container yet.', 503);
        }
    }

    /** The Node keeps a bare mirror of the Project's repository per Instance: Git runs there, never in the container. */
    public static function mirror(Instance $instance): string
    {
        return '/var/lib/orbit/instance-repositories/'.$instance->id.'.git';
    }

    /**
     * Reserves the container's runtime row: the lowest free guest address of the Node's bridge (the bridge keeps the first usable
     * address and the image build the last), the shared folder versions the Node installs now, and no T3 port.
     */
    public function reserve(Instance $instance, Node $node): InstanceRuntime
    {
        $existing = InstanceRuntime::query()->where('instance_id', $instance->id)->first();
        if ($existing instanceof InstanceRuntime) {
            return $existing;
        }
        $folders = $this->folders();
        $bridge = Node::query()->whereKey($node->id)->value('instance_bridge');
        if (! is_string($bridge)) {
            throw new ResourceOperationException('instance.host_unavailable', 'The Node has no Instance bridge yet: converge its app-dev role first.', 409);
        }
        [$gateway, $last] = Ipv4Subnet::from($bridge)->usableRange();
        $taken = InstanceRuntime::query()->where('node_id', $node->id)->pluck('bridge_address')->all();
        $address = null;
        for ($candidate = (int) ip2long($gateway) + 1; $candidate < (int) ip2long($last); $candidate++) {
            if (! in_array(long2ip($candidate), $taken, true)) {
                $address = long2ip($candidate);
                break;
            }
        }
        if ($address === null) {
            throw new ResourceOperationException('instance.host_unavailable', 'The Node\'s Instance bridge has no free address.', 409);
        }
        $plan = InstanceFolderPlan::for($folders);

        return InstanceRuntime::query()->create([
            'instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => InstanceHostIntent::Project,
            'container_name' => self::name($instance), 'bridge_address' => $address, 't3_port' => null,
            'runtime_version' => $plan['runtime']['version'], 'agent_version' => $plan['agent']['version'],
            'clone_source_instance_id' => null, 'power_state' => InstanceRuntimePowerState::Stopped,
        ]);
    }

    /** A web-serving Project needs its PHP version, and the runtime folder its static build: FrankenPHP serves it. */
    public function assertPhp(Project $project): void
    {
        $version = $project->php_version;
        if (! $project->type->isWebServing()) {
            return;
        }
        if (! is_string($version)) {
            throw new ResourceOperationException('project.source_defaults_incomplete', "App [{$project->slug}] has no PHP version for FrankenPHP to serve.", 422);
        }
        if (($this->folders()->php[$version]->artifact ?? null) === null) {
            throw new ResourceOperationException('instance.host_unavailable', "The runtime folder has no static PHP {$version} build.", 409);
        }
    }

    /**
     * The `container` section of a create or deploy intent. A deployment run sets `commit` when the deployment pins one.
     *
     * @return array<string, mixed>
     */
    public function intent(Instance $instance, InstanceRuntime $runtime): array
    {
        $instance->loadMissing(['project', 'node']);
        $project = $instance->project;
        $workspace = InstanceHostIntent::Workspace;
        $application = ApplicationDirectory::resolve($workspace, $instance->root ?? $project->root);
        $gateway = Ipv4Subnet::from(ValidatedData::string(Node::query()->whereKey($runtime->node_id)->value('instance_bridge')))->usableRange()[0];

        return [
            'instance_id' => $instance->id, 'project' => $runtime->incus_project, 'name' => $runtime->container_name,
            'image' => self::Image, 'address' => $runtime->bridge_address, 'network' => InstanceHostIntent::Network,
            'acl' => $runtime->container_name,
            // The bridge ACL keeps the way to the model forwarder; this one lets only the Node's Caddy reach FrankenPHP.
            'acl_rules' => ['description' => 'Orbit Instance '.$instance->id, 'egress' => [],
                'ingress' => [['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                    'destination_port' => (string) self::WebPort, 'state' => 'enabled']]],
            'mirror' => self::mirror($instance), 'repository' => $project->repository_url,
            'protocols' => ValidatedData::stringList(config('orbit.git.checkout_protocols', ['https'])),
            'branch' => ValidatedData::string($project->default_branch), 'commit' => null,
            'workspace' => $workspace, 'environment_file' => $application.'/.env',
            // The deploy commands' whole environment: `orbit`'s home and the runtime folder, the Project's PHP first.
            'environment' => ['HOME=/home/orbit', 'USER=orbit', 'LOGNAME=orbit', 'PATH='.$this->path($project), InstanceImage::RuntimeEnvironment],
            'units' => $this->units($instance, $runtime, $application),
        ];
    }

    /**
     * FrankenPHP, in classic mode, as `orbit` from the runtime folder, for a web-serving Project with a PHP version.
     *
     * @return list<array{name: string, content: string}>
     */
    public function units(Instance $instance, InstanceRuntime $runtime, string $application): array
    {
        $project = $instance->project;
        $version = $project->php_version;
        if (! $project->type->isWebServing() || ! is_string($version)) {
            return [];
        }
        $path = $this->path($project);
        $php = InstanceHostIntent::RuntimeFolder.'/current/php/'.$version.'/bin';
        $root = InstanceHostIntent::Workspace.'/'.trim($instance->root ?? $project->root ?? 'public', '/');
        $listen = $runtime->bridge_address.':'.self::WebPort;

        return [['name' => self::FrankenPhpUnit, 'content' => <<<UNIT
            # Managed by Orbit.
            [Unit]
            Description=FrankenPHP
            After=network-online.target

            [Service]
            Type=simple
            User=orbit
            Group=orbit
            UMask=0007
            WorkingDirectory={$application}
            Environment=HOME=/home/orbit
            Environment=PATH={$path}
            ExecStart={$php}/frankenphp php-server --listen {$listen} --root {$root}
            Restart=always
            RestartSec=2

            [Install]
            WantedBy=multi-user.target

            UNIT]];
    }

    /** The runtime folder's programs, behind the static PHP build of the Project's version. */
    private function path(Project $project): string
    {
        $php = is_string($project->php_version) ? InstanceHostIntent::RuntimeFolder.'/current/php/'.$project->php_version.'/bin:' : '';

        return $php.InstanceHostIntent::RuntimeFolder.'/current/bin:/usr/local/bin:/usr/bin:/bin';
    }

    private function folders(): InstanceFolders
    {
        return $this->recipes->currentRecipe()->instances
            ?? throw new ResourceOperationException('instance.host_unavailable', 'The recipe holds no shared Instance folders.', 409);
    }
}
