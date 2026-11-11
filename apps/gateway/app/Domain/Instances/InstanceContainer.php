<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\TaskVms\TaskVmConfig;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\InstanceFolders;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\DatabaseConnection;
use App\Models\Instance;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;

/**
 * The container of a development Instance (docs/reference/development-containers.md#lifecycle): its name, its address on the Node's
 * bridge, its ACL, the FrankenPHP unit and the paths the create and deploy flows use. The Project's default Instance runs no T3: it has
 * no `/etc/orbit/instance`. Every other Instance is a clone of the default's seed snapshot, with its own T3 port, the agent's files and
 * the instance file, so T3 starts there. The run's intent carries it as `container`, and a clone's part as `container.clone`.
 */
final readonly class InstanceContainer
{
    /** FrankenPHP listens on the container's bridge address; the Node's Caddy proxies the Route to it. */
    public const int WebPort = 8080;

    public const string Image = 'orbit-instance';

    public const string FrankenPhpUnit = 'orbit-frankenphp.service';

    public const string T3Unit = 't3.service';

    /** The proxy device that publishes a clone's T3 on the Node's WireGuard address, in NAT mode, so T3 sees the client's address. */
    public const string T3Device = 't3';

    /** The ports of the Node's WireGuard address that publish T3, one per clone. */
    public const int T3PortFirst = 3800;

    public const int T3PortLast = 3999;

    /** The Node's WireGuard interface, where T3's clients come in. */
    public const string WireGuardInterface = 'orbit';

    /** The file whose presence starts T3: the Instance, its Project and its URL. */
    public const string InstanceFile = '/etc/orbit/instance';

    /** The placeholder model key and the model origin, which T3's unit hands to Claude Code and Codex. */
    public const string ModelEnvironment = '/home/agent/.config/orbit/model.env';

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

    /** The application's folder in the workspace: the Project root of a monorepo, else the workspace itself. */
    public static function application(Instance $instance): string
    {
        return ApplicationDirectory::resolve(InstanceHostIntent::Workspace, $instance->root ?? $instance->project->root);
    }

    /** The Node keeps a bare mirror of the Project's repository per Instance: Git runs there, never in the container. */
    public static function mirror(Instance $instance): string
    {
        return '/var/lib/orbit/instance-repositories/'.$instance->id.'.git';
    }

    /**
     * Reserves the container's runtime row: the lowest free guest address of the Node's bridge (the bridge keeps the first usable
     * address and the image build the last) and the shared folder versions the Node installs now. A clone also gets the lowest free
     * T3 port and names the default it copies; the default gets no T3 port.
     */
    public function reserve(Instance $instance, Node $node, ?Instance $source = null): InstanceRuntime
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
        $port = null;
        // Every Instance but the default runs T3: a clone, and a checkout Instance that moved into a container.
        if ($instance->name !== 'default') {
            $ports = array_map(ValidatedData::integer(...), InstanceRuntime::query()->where('node_id', $node->id)->whereNotNull('t3_port')->pluck('t3_port')->all());
            $port = array_values(array_diff(range(self::T3PortFirst, self::T3PortLast), $ports))[0]
                ?? throw new ResourceOperationException('instance.host_unavailable', 'The Node has no free T3 port.', 409);
        }
        $plan = InstanceFolderPlan::for($folders);

        return InstanceRuntime::query()->create([
            'instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => InstanceHostIntent::Project,
            'container_name' => self::name($instance), 'bridge_address' => $address, 't3_port' => $port,
            'runtime_version' => $plan['runtime']['version'], 'agent_version' => $plan['agent']['version'],
            'clone_source_instance_id' => $source?->id, 'power_state' => InstanceRuntimePowerState::Stopped,
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
     * The `container` section of a create or deploy intent. A deployment run sets `commit` when the deployment pins one. `$database`
     * is the Instance's database on a Database server, which the container reaches through its own rules.
     *
     * @return array<string, mixed>
     */
    public function intent(Instance $instance, InstanceRuntime $runtime, ?DatabaseConnection $database = null, string $branch = '', ?string $url = null): array
    {
        $instance->loadMissing(['project', 'node']);
        $project = $instance->project;
        $workspace = InstanceHostIntent::Workspace;
        $application = self::application($instance);
        $gateway = Ipv4Subnet::from(ValidatedData::string(Node::query()->whereKey($runtime->node_id)->value('instance_bridge')))->usableRange()[0];
        $environment = InstanceImage::environment($gateway);
        $access = $database === null ? null : $this->databaseAccess($instance, $runtime, $database);
        $clone = $runtime->t3_port === null ? null : $this->clone($instance, $runtime, $gateway, $url);

        return [
            'instance_id' => $instance->id, 'project' => $runtime->incus_project, 'name' => $runtime->container_name,
            'config' => ['limits.cpu' => '4', 'limits.memory' => '8GiB', 'limits.processes' => '4096'],
            'root_disk_pool' => InstanceHostIntent::Pool, 'root_disk_size' => '40GiB',
            'image' => self::Image, 'address' => $runtime->bridge_address, 'network' => InstanceHostIntent::Network,
            'acl' => $runtime->container_name,
            // The bridge ACL keeps the ways to the model forwarder and the egress proxy; this one lets only the Node's Caddy reach
            // FrankenPHP, a clone's T3 clients reach T3, and the container reach its own database server.
            'acl_rules' => ['description' => 'Orbit Instance '.$instance->id,
                'egress' => $access === null ? [] : [['action' => 'allow', 'destination' => $access['host'].'/32', 'protocol' => 'tcp',
                    'destination_port' => (string) $access['port'], 'state' => 'enabled']],
                'ingress' => [['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                    'destination_port' => (string) self::WebPort, 'state' => 'enabled'],
                    ...(($vitePort = app(ContainerProcesses::class)->vitePort($instance)) === null ? [] : [['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                        'destination_port' => (string) $vitePort, 'state' => 'enabled']]),
                    ...($clone === null ? [] : [['action' => 'allow', 'protocol' => 'tcp', 'destination_port' => (string) InstanceHostIntent::T3Port, 'state' => 'enabled']]),
                    // An agent's development servers: only the Node's Caddy reaches them, and it serves them only on a private Route.
                    ...($this->servesDevPorts($instance, $runtime) ? [['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                        'destination_port' => InstanceDevPorts::First.'-'.InstanceDevPorts::Last, 'state' => 'enabled']] : [])]],
            // UFW on the Node, under one comment prefix per Instance, so its removal takes them all.
            'firewall' => [...($clone === null ? [] : $this->t3Firewall($instance, $runtime)), ...($access === null ? [] : [$access['rule']])],
            'mirror' => self::mirror($instance), 'repository' => $project->repository_url,
            'protocols' => ValidatedData::stringList(config('orbit.git.checkout_protocols', ['https'])),
            'branch' => $branch !== '' ? $branch : ValidatedData::string($project->default_branch), 'commit' => null,
            'workspace' => $workspace, 'environment_file' => $application.'/.env',
            // The deploy commands' whole environment: `orbit`'s home and the runtime folder, the Project's PHP first, the Vite+ home and
            // the egress proxy (docs/reference/development-containers.md#egress-and-dns).
            'environment' => ['HOME=/home/orbit', 'USER=orbit', 'LOGNAME=orbit', 'PATH='.self::path($project),
                ...array_map(static fn (string $name, string $value): string => "{$name}={$value}", array_keys($environment), $environment)],
            'units' => $this->units($instance, $runtime, $application),
            // `php` on every PATH in the container is the Project's static build, so `composer` runs for `agent` and `orbit` alike.
            'php' => $this->php($project),
            'clone' => $clone,
            // The Project's development Processes; a deployment's new seed restarts the running ones on the new commit.
            'processes' => app(ContainerProcesses::class)->units($instance),
        ];
    }

    /** The Project's `php` in the runtime folder, through `current`, or null when the Project has no PHP version the folder holds. */
    private function php(Project $project): ?string
    {
        $version = $project->php_version;
        if (! is_string($version) || ($this->folders()->php[$version]->artifact ?? null) === null) {
            return null;
        }

        return InstanceHostIntent::RuntimeFolder.'/current/php/'.$version.'/bin/php';
    }

    /**
     * The placeholder model key and the model origin for T3's unit, which hands them to Claude Code and Codex. It goes to the run
     * through the vault: the key is fixed, but it is still a key.
     */
    public function modelEnvironment(InstanceRuntime $runtime, string $key): string
    {
        $origin = 'http://'.Ipv4Subnet::from(ValidatedData::string(Node::query()->whereKey($runtime->node_id)->value('instance_bridge')))->usableRange()[0]
            .':'.InstanceHostIntent::ModelForwarderPort;

        return "ANTHROPIC_BASE_URL={$origin}\nANTHROPIC_AUTH_TOKEN={$key}\nORBIT_MODEL_KEY={$key}\n"
            ."CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC=1\nDISABLE_TELEMETRY=1\nDISABLE_ERROR_REPORTING=1\n";
    }

    /**
     * The part of the intent of an Instance with T3: T3's published port and the files the agent and T3 need, and for a clone the
     * default's container and seed snapshot it copies. A checkout Instance that moved in copies none. The model key is not here: it
     * comes through the vault.
     *
     * @return array<string, mixed>
     */
    private function clone(Instance $instance, InstanceRuntime $runtime, string $gateway, ?string $url): array
    {
        $source = $runtime->clone_source_instance_id === null ? null
            : InstanceRuntime::query()->where('instance_id', $runtime->clone_source_instance_id)->firstOrFail();
        $node = Node::query()->findOrFail($runtime->node_id);
        $project = $instance->project;
        $agent = InstanceHostIntent::AgentUid;
        $workspace = InstanceHostIntent::Workspace;
        $note = InstanceHostIntent::AgentFolder.'/current/notes/'.InstanceNotes::for($project->type).'.md';
        $codex = "check_for_update_on_startup = false\nmodel_provider = \"orbit\"\n\n[model_providers.orbit]\nname = \"Orbit\"\n"
            ."base_url = \"http://{$gateway}:".InstanceHostIntent::ModelForwarderPort."/v1\"\nenv_key = \"ORBIT_MODEL_KEY\"\n"
            ."wire_api = \"responses\"\nsupports_websockets = false\n\n[projects.\"{$workspace}\"]\ntrust_level = \"trusted\"\n";

        return [
            // The default's mirror on the Node holds the seed's commit: a new branch starts there, without the network.
            ...($source === null ? [] : ['source' => $source->container_name, 'snapshot' => 'seed-'.ValidatedData::string($source->seed_commit),
                'seed_commit' => ValidatedData::string($source->seed_commit), 'source_mirror' => self::mirror(Instance::query()->findOrFail($source->instance_id))]),
            't3' => ['port' => $runtime->t3_port, 'listen' => ValidatedData::string($node->wireguard_ip), 'device' => self::T3Device,
                'title' => $project->name ?: $project->slug,
                'unit' => self::T3Unit, 'internal_port' => InstanceHostIntent::T3Port, 'ready_seconds' => 30,
                'url' => 'http://'.$node->wireguard_ip.':'.$runtime->t3_port, 'label' => $project->slug.' / '.$instance->name,
                't3' => InstanceHostIntent::AgentFolder.'/current/agent/bin/t3', 'project_mark' => '/home/agent/.config/orbit/t3-project'],
            'model_environment' => ['path' => self::ModelEnvironment, 'uid' => $agent, 'gid' => $agent, 'mode' => '0600'],
            'files' => [
                ['path' => self::InstanceFile, 'uid' => 0, 'gid' => 0, 'mode' => '0644', 'content' => "# Managed by Orbit.\n"
                    ."ORBIT_INSTANCE_ID={$instance->id}\nORBIT_INSTANCE={$instance->name}\nORBIT_PROJECT={$project->slug}\n"
                    ."ORBIT_PROJECT_ID={$project->id}\nORBIT_PROJECT_NAME=".InstanceEnvironmentRenderer::quote(str_replace(["\r", "\n"], ' ', $project->name))."\n"
                    ."ORBIT_NODE={$node->name}\nORBIT_URL=".($url ?? '')."\n"
                    .($this->servesDevPorts($instance, $runtime) ? 'ORBIT_DEV_PORTS='.InstanceDevPorts::First.'-'.InstanceDevPorts::Last."\n"
                        .'ORBIT_DEV_PORTS_TAKEN='.implode(',', app(InstanceDevPorts::class)->excluded($node))."\n" : '')],
                ['path' => '/home/agent/.codex/config.toml', 'uid' => $agent, 'gid' => $agent, 'mode' => '0600', 'content' => $codex],
                ['path' => '/home/agent/.claude.json', 'uid' => $agent, 'gid' => $agent, 'mode' => '0600', 'json' => ['hasCompletedOnboarding' => true]],
                ['path' => '/home/agent/.t3/userdata/settings.json', 'uid' => $agent, 'gid' => $agent, 'mode' => '0600', 'json' => ['linkedEnvironmentsEnabled' => true]],
            ],
            // The note of the Project's type, as the agents' own instructions file; it states facts about the container only.
            'links' => [['path' => '/home/agent/.claude/CLAUDE.md', 'target' => $note], ['path' => '/home/agent/.codex/AGENTS.md', 'target' => $note]],
        ];
    }

    /**
     * T3's published port: WireGuard peers reach it through the proxy device, but the task VM range does not, so its deny comes first.
     *
     * @return list<array<string, string>>
     */
    private function t3Firewall(Instance $instance, InstanceRuntime $runtime): array
    {
        $rule = ['route' => 'yes', 'interface_in' => self::WireGuardInterface, 'interface_out' => InstanceHostIntent::Network,
            'to_ip' => $runtime->bridge_address, 'to_port' => (string) InstanceHostIntent::T3Port, 'proto' => 'tcp'];

        return [
            [...$rule, 'rule' => 'deny', 'from_ip' => TaskVmConfig::wireguardRange(config('task_vms.wireguard_range'))->value(),
                'comment' => self::firewallPrefix($instance).'t3-task-vms'],
            [...$rule, 'rule' => 'allow', 'from_ip' => 'any', 'comment' => self::firewallPrefix($instance).'t3'],
        ];
    }

    /**
     * The way to the Instance's database server: the server's address and port. A server on the Instance's own Node is reached on the
     * Node; one on another Node through the Node's WireGuard address, as the bridge's NAT sends it.
     *
     * `$name` ends the rule's comment, so an Instance with more than one server keeps a rule for each.
     *
     * @return array{host: string, port: int, rule: array<string, string>}
     */
    public function databaseAccess(Instance $instance, InstanceRuntime $runtime, DatabaseConnection $database, string $name = 'database'): array
    {
        $host = $database->host;
        $port = $database->port;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! is_int($port)) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'A container reaches only a database server with an IPv4 address and a port.', 422);
        }
        $local = $database->server?->node_id === $runtime->node_id;
        $rule = ['rule' => 'allow', 'interface_in' => InstanceHostIntent::Network, 'from_ip' => $runtime->bridge_address,
            'to_ip' => $host, 'to_port' => (string) $port, 'proto' => 'tcp', 'comment' => self::firewallPrefix($instance).$name,
            ...($local ? [] : ['route' => 'yes', 'interface_out' => self::WireGuardInterface])];

        return ['host' => $host, 'port' => $port, 'rule' => $rule];
    }

    /**
     * Whether an agent's development servers answer through the Instance's Route (docs/reference/development-containers.md#agent-development-servers):
     * an Instance that runs T3, with its own private Route. The default runs no agent. A public Route never serves them: the servers run as
     * `agent`, and the split of the two users keeps a public URL away from `agent`'s files. `$route` is the Route a caller renders;
     * without it, the Instance's own Route.
     */
    public function servesDevPorts(Instance $instance, InstanceRuntime $runtime, ?Route $route = null): bool
    {
        $route ??= $instance->routes()->whereNull('web_root')->orderBy('routes.id')->first();

        return $runtime->t3_port !== null && $route instanceof Route && $route->publication !== RoutePublication::Public;
    }

    /** Every UFW rule of a container Instance carries this comment prefix. */
    public static function firewallPrefix(Instance $instance): string
    {
        return 'orbit:instance-'.$instance->id.'-';
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
        $path = self::path($project);
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
    public static function path(Project $project): string
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
