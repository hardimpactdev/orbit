<?php

declare(strict_types=1);

// Frozen copies of InstanceHostIntent and InstanceContainer from origin/main 77f6bf5c22, before nested-fleet hosting. The identity
// tests in NestedFleetHostingTest compare today's normal Node and normal Instance intents with these, byte for byte. Only the class
// names changed, and Pint formatted the result. Do not edit: a change here weakens the proof that a normal Node and a normal Instance stay as they were.

namespace App\Domain\Instances;

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Nodes\NodeTaskVmContainersData;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * The container branch of the `app-dev` role converge (docs/reference/development-containers.md#nodes): the Incus project on the
 * Node's pool, the Instance bridge, the image and the two shared folders. Only a Node on the build list gets it, and never a task
 * VM's own Node: a task VM keeps its checkout workspace. A task VM host does get it, beside its task side: the two sides keep their
 * own Incus projects, bridges, pools and firewall rules, and the task side keeps the ZFS ARC. The admission that writes allocates
 * the Node's bridge first (`prepare`), and the Node keeps it; the intent only reads it. Null for every other Node, whose converge
 * does what it did before.
 */
final readonly class BaselineNormalInstanceHostIntent
{
    public const string Project = 'orbit-instances';

    public const string Network = 'orbitinst0';

    public const string NicAcl = self::Network.'-proxy';

    /** The Incus storage pool the Node provides: ZFS, so a copy is a clone, or `dir` on a nested proof Node. */
    public const string Pool = 'orbit-instances';

    public const string StockAlias = 'ubuntu-26.04-instance-stock';

    public const string RuntimeFolder = '/opt/orbit-runtime';

    public const string AgentFolder = '/opt/orbit-agent';

    public const string Workspace = '/srv/workspace';

    public const int AgentUid = 2750;

    public const int OrbitUid = 1000;

    public const int WorkGid = 2760;

    public const int T3Port = 3773;

    /** The model forwarder on the bridge's gateway address, which the model route PR builds. */
    public const int ModelForwarderPort = 8319;

    /** One isolated ID map per container, and at most one container per T3 port, 3800 to 3999. */
    public const int Containers = 200;

    public const int IdMapSize = 65536;

    /** The isolated range of each task container place and the base on a task container host (compute-drivers.md#the-host-system). */
    public const int TaskPlaceIds = 101000000;

    public function __construct(
        private ContainerNodes $nodes,
        private AllocateInstanceBridgeAction $bridges,
        private RecipeImageBuilds $recipes,
        private PeerDnsPolicy $privateDns,
    ) {}

    /** Allocates the Node's bridge in the admission transaction of a role add or converge, before the intent is captured. */
    public function prepare(Node $node): void
    {
        if ($this->applies($node)) {
            $this->bridges->execute($node);
        }
    }

    /** @return array<string, mixed>|null */
    public function for(Node $node): ?array
    {
        $admittedAt = (int) now()->format('Uu');
        if (! $this->applies($node)) {
            return null;
        }
        $architecture = MachineRecipe::architecture($node->architecture)
            ?? throw new ResourceOperationException('instance.host_unavailable', 'The Node architecture is unsupported.', 409);
        $recipe = $this->recipes->currentRecipe();
        $incus = $recipe->incus($architecture)
            ?? throw new ResourceOperationException('instance.host_unavailable', 'The recipe holds no Incus package for this architecture.', 409);
        $folders = $recipe->instances
            ?? throw new ResourceOperationException('instance.host_unavailable', 'The recipe holds no shared Instance folders.', 409);
        $cidr = Node::query()->whereKey($node->id)->value('instance_bridge');
        if (! is_string($cidr)) {
            throw new ResourceOperationException('instance.host_unavailable', 'The Node has no Instance bridge yet: the admission of a role add or converge allocates it.', 409);
        }
        // Instance containers and the task containers of the same host take their isolated ID maps from root's one range.
        $taskHost = app(NodeSettingsNormalizer::class)->fromStored($node->settings)?->task_vms;
        $taskContainers = $taskHost?->containers;
        $taskIds = $taskContainers instanceof NodeTaskVmContainersData ? ($taskContainers->task_places + $taskContainers->ci_places + 1) * self::TaskPlaceIds : 0;
        $subnet = Ipv4Subnet::from($cidr);
        [$gateway, $last] = $subnet->usableRange();

        return [
            'project' => self::Project, 'network' => self::Network, 'nic_acl' => self::NicAcl, 'pool' => self::Pool, 'cidr' => $cidr,
            'address' => $gateway.'/'.$subnet->prefixLength(),
            // The image build container takes the bridge's last address, which no Instance gets.
            'build_address' => $last,
            // DHCP never hands out the gateway's neighbour range end, so the build address stays free.
            'dhcp_range' => long2ip((int) ip2long($gateway) + 1).'-'.long2ip((int) ip2long($last) - 1),
            'stock' => self::StockAlias,
            // The common NIC ACL lets containers reach only the model forwarder (docs/reference/ai-router.md#container-model-forwarder) and
            // the egress proxy, their one way out (docs/reference/development-containers.md#egress-and-dns).
            'forwarder_port' => self::ModelForwarderPort,
            'ingress' => self::ingress($gateway),
            'bridge_admitted_at' => $admittedAt,
            'bridge_endpoints' => app(InstanceContainer::class)->nodeEndpoints($node),
            'egress' => InstanceEgress::for(self::Network, $gateway, $cidr, $this->privateDns->domains()),
            'ids' => (self::Containers + 1) * self::IdMapSize + $taskIds,
            // A task VM host sizes the ARC for its task side, or keeps the Node's own (task-vm-host-prepare.yml): the Instance side
            // never changes it there.
            'arc' => $taskHost === null,
            'incus' => $incus,
            'image' => InstanceImage::for($gateway),
            'folders' => InstanceFolderPlan::for($folders),
            // The redirect of agent development ports on the WireGuard address to Caddy's development listener.
            'dev_ports' => ['table' => InstanceDevPorts::Table, 'unit' => InstanceDevPorts::TableUnit,
                'ruleset' => app(InstanceDevPorts::class)->ruleset($node)],
            // `incus exec` sessions get the container environment from the profile; the image gives it to units and login shells.
            'profile_config' => [
                'environment.PATH' => InstanceImage::Path,
                ...array_combine(
                    array_map(static fn (string $name): string => "environment.{$name}", array_keys(InstanceImage::environment($gateway))),
                    InstanceImage::environment($gateway),
                ),
            ],
        ];
    }

    /**
     * The bridge ACL's ingress. Incus checks the bridge ACL before a container's own ACL, and a packet to a container must pass both.
     * So the bridge admits what a container's ACL may admit (InstanceContainer::intent), and no more: the Node's own connections,
     * which leave from the bridge address, to FrankenPHP's port and the development ports, and T3's port from any source. A proxy
     * device in NAT mode keeps the address of T3's client, a WireGuard peer; the Node's route rules decide which peers pass. The NIC
     * combines its proxy ACL, which keeps this same ingress, with its own as a union, so defaults also inherit it (F10, docs/reference/development-containers.md#network).
     *
     * @return list<array<string, string>>
     */
    private static function ingress(string $gateway): array
    {
        return [
            // FrankenPHP's port lies in the range of the development ports, so one rule covers both.
            ['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                'destination_port' => InstanceDevPorts::First.'-'.InstanceDevPorts::Last, 'state' => 'enabled'],
            ['action' => 'allow', 'protocol' => 'tcp', 'destination_port' => (string) self::T3Port, 'state' => 'enabled'],
        ];
    }

    private function applies(Node $node): bool
    {
        return $this->nodes->includes($node) && ! TaskVm::query()->where('node_id', $node->id)->exists();
    }
}

namespace App\Domain\Instances;

use App\Domain\DatabaseConnections\DatabaseDriver;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Routes\RoutePublication;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\ApplicationDirectory;
use App\Domain\TaskVms\TaskVmConfig;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\InstanceFolders;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\DatabaseConnection;
use App\Models\DatabaseConnectionTarget;
use App\Models\Instance;
use App\Models\InstanceNodeServiceTarget;
use App\Models\InstanceRuntime;
use App\Models\Node;
use App\Models\Project;
use App\Models\Route;
use App\Support\ValidatedData;
use RuntimeException;

/**
 * The container of a development Instance (docs/reference/development-containers.md#lifecycle): its name, its address on the Node's
 * bridge, its ACL, the FrankenPHP unit and the paths the create and deploy flows use. The Project's default Instance runs no T3: it has
 * no `/etc/orbit/instance`. Every other Instance is a clone of the default's seed snapshot, with its own T3 port, the agent's files and
 * the instance file, so T3 starts there. The run's intent carries it as `container`, and a clone's part as `container.clone`.
 */
final readonly class BaselineNormalInstanceContainer
{
    /** FrankenPHP listens on the container's bridge address; the Node's Caddy proxies the Route to it. */
    public const int WebPort = 8080;

    public const string Image = 'orbit-instance';

    public const string FrankenPhpUnit = 'orbit-frankenphp.service';

    public const string FrankenPhpConfig = '/etc/orbit/frankenphp.Caddyfile';

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
        $admittedAt = (int) now()->format('Uu');
        $instance->loadMissing(['project', 'node.roles']);
        $project = $instance->project;
        $workspace = InstanceHostIntent::Workspace;
        $application = self::application($instance);
        $gateway = Ipv4Subnet::from(ValidatedData::string($instance->node->instance_bridge))->usableRange()[0];
        $environment = InstanceImage::environment($gateway);
        $mail = app(DevelopmentMail::class)->forInstance($instance);
        $nodeServices = InstanceNodeServiceTargets::endpoints($instance);
        $environment = [...$environment, ...InstanceNodeServiceTargets::proxyEnvironment($gateway, $nodeServices)];
        $access = $this->targetAccesses($instance, $runtime, $database, $mail, $nodeServices);
        $clone = $runtime->t3_port === null ? null : $this->clone($instance, $runtime, $gateway, $url);
        $vitePort = app(ContainerProcesses::class)->vitePort($instance);

        return [
            'instance_id' => $instance->id, 'project' => $runtime->incus_project, 'name' => $runtime->container_name,
            'config' => ['limits.cpu' => '4', 'limits.memory' => '8GiB', 'limits.processes' => '4096',
                ...(! array_any($nodeServices, static fn (array $target): bool => ! $target['docker']) && ! isset($clone['proxy_environment']) ? []
                    : array_combine(array_map(static fn (string $key): string => 'environment.'.$key, ['NO_PROXY', 'no_proxy']),
                        array_values(InstanceNodeServiceTargets::proxyEnvironment($gateway, $nodeServices))))],
            'root_disk_pool' => InstanceHostIntent::Pool, 'root_disk_size' => '40GiB',
            'image' => self::Image, 'address' => $runtime->bridge_address, 'network' => InstanceHostIntent::Network,
            'acl' => $runtime->container_name, 'nic_acl' => InstanceHostIntent::NicAcl,
            'bridge_cidr' => ValidatedData::string(Node::query()->whereKey($runtime->node_id)->value('instance_bridge')),
            'bridge_admitted_at' => $admittedAt,
            'bridge_endpoints' => array_map(static fn (array $target): array => ['host' => $target['host'], 'port' => $target['port'], 'docker' => $target['docker']], $access),
            // The common NIC ACL keeps the ways to the model forwarder and egress proxy; this one lets the Node's Caddy reach
            // FrankenPHP, a clone's T3 clients reach T3, and the container reach each attached target.
            'acl_rules' => ['description' => 'Orbit Instance '.$instance->id,
                'egress' => array_map(static fn (array $target): array => ['action' => 'allow', 'destination' => $target['host'].'/32', 'protocol' => 'tcp',
                    'destination_port' => (string) $target['port'], 'state' => 'enabled',
                    ...(($target['node_service'] ?? false) ? ['description' => 'Orbit Node service:'.$target['port']]
                        : ($mail !== null && $target['host'] === $mail['host'] && $target['port'] === $mail['port']
                            ? ['description' => 'Orbit development SMTP'] : []))], $access),
                'ingress' => [['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                    'destination_port' => (string) self::WebPort, 'state' => 'enabled'],
                    ...($vitePort === null ? [] : [['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                        'destination_port' => (string) $vitePort, 'state' => 'enabled']]),
                    ...($clone === null ? [] : [['action' => 'allow', 'protocol' => 'tcp', 'destination_port' => (string) InstanceHostIntent::T3Port, 'state' => 'enabled']]),
                    // An agent's development servers: only the Node's Caddy reaches them, and it serves them only on a private Route.
                    ...($this->servesDevPorts($instance, $runtime) ? [['action' => 'allow', 'source' => $gateway.'/32', 'protocol' => 'tcp',
                        'destination_port' => InstanceDevPorts::First.'-'.InstanceDevPorts::Last, 'state' => 'enabled']] : [])]],
            // UFW on the Node, under one comment prefix per Instance, so its removal takes them all.
            'firewall' => [...($clone === null ? [] : $this->t3Firewall($instance, $runtime)), ...array_column($access, 'rule')],
            'mirror' => self::mirror($instance), 'repository' => $project->repository_url,
            'protocols' => ValidatedData::stringList(config('orbit.git.checkout_protocols', ['https'])),
            'branch' => $branch !== '' ? $branch : ValidatedData::string($project->default_branch), 'commit' => null,
            'workspace' => $workspace, 'environment_file' => $application.'/.env',
            // The deploy commands' whole environment: `orbit`'s home and the runtime folder, the Project's PHP first, the Vite+ home and
            // the egress proxy (docs/reference/development-containers.md#egress-and-dns).
            'environment' => ['HOME=/home/orbit', 'USER=orbit', 'LOGNAME=orbit', 'PATH='.self::path($project),
                ...array_map(static fn (string $name, string $value): string => "{$name}={$value}", array_keys($environment), $environment)],
            ...($nodeServices === [] ? [] : ['node_services' => $nodeServices]),
            'mail' => $mail,
            'mail_units' => $mail === null ? [] : app(DevelopmentMail::class)->units($mail),
            'units' => $this->units($instance, $runtime, $application, $vitePort),
            'web_config' => $this->webConfig($instance, $runtime),
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
        $resetProxy = $source !== null && InstanceNodeServiceTarget::query()->where('instance_id', $source->instance_id)
            ->where('node_id', '!=', $source->node_id)->exists();
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
            ...($resetProxy ? ['proxy_environment' => InstanceNodeServiceTargets::proxyEnvironment($gateway, [])] : []),
            'files' => [
                ...($resetProxy ? $this->proxyFiles($gateway) : []),
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

    /** A clone has no inherited Node service attachments.
     * @return list<array{path:string,content:string,uid:int,gid:int,mode:string}>
     */
    private function proxyFiles(string $gateway): array
    {
        $environment = InstanceNodeServiceTargets::proxyEnvironment($gateway, []);
        $assignments = implode(' ', array_map(static fn (string $key, string $value): string => $key.'='.$value, array_keys($environment), $environment));

        return [
            ['path' => '/etc/systemd/system.conf.d/99-orbit-node-services.conf',
                'content' => "# Managed by Orbit: attached Node service.\n[Manager]\nDefaultEnvironment=".$assignments."\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
            ['path' => '/etc/profile.d/99-orbit-node-services.sh',
                'content' => "# Managed by Orbit: attached Node service.\nexport ".$assignments."\n", 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
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

    /** Attached targets and the Node's development SMTP publication share one endpoint policy.
     * @param  array{host:string,port:int,docker:bool}|null  $mail
     * @param  list<array{id:int,process_id:int,process_name:string,host:string,port:int,docker:bool,host_env_key:string,port_env_key:string}>|null  $nodeServices
     * @return list<array{host:string,port:int,docker:bool,node_service?:bool,rule?:array<string,string>}>
     */
    private function targetAccesses(Instance $instance, InstanceRuntime $runtime, ?DatabaseConnection $primary, ?array $mail, ?array $nodeServices = null): array
    {
        $targets = $this->databaseAccesses($instance, $runtime, $primary);
        $attached = [];
        foreach ($nodeServices ?? InstanceNodeServiceTargets::endpoints($instance) as $endpoint) {
            $attached[$endpoint['host'].':'.$endpoint['port']] = ['host' => $endpoint['host'], 'port' => $endpoint['port'],
                'docker' => $endpoint['docker'], 'node_service' => true,
                ...($endpoint['docker'] ? [] : ['rule' => $this->tcpRule($instance, $runtime, $endpoint['host'], $endpoint['port'], false,
                    'node-service-'.$endpoint['host'].'-'.$endpoint['port'])])];
        }
        $targets = [...$targets, ...array_values($attached)];
        if ($mail !== null && ! in_array($mail, array_map(static fn (array $target): array => array_intersect_key($target, $mail), $targets), true)) {
            $targets[] = $mail;
        }

        return $targets;
    }

    /**
     * All attached server targets, regardless of driver or prefix. The primary create database may not have its target yet.
     * SQLite stays in the workspace. A repeated attachment of the same endpoint needs only one network path.
     *
     * @return list<array{host: string, port: int, docker: bool, rule: array<string, string>}>
     */
    private function databaseAccesses(Instance $instance, InstanceRuntime $runtime, ?DatabaseConnection $primary): array
    {
        $connections = $primary === null ? [] : [$primary->id => $primary];
        foreach (DatabaseConnectionTarget::query()->with('databaseConnection.server')->where('instance_id', $instance->id)
            ->orderByRaw("prefix = 'DB' desc")->orderBy('id')->get() as $target) {
            $connection = $target->databaseConnection;
            if ($connection->driver !== DatabaseDriver::Sqlite) {
                $connections[$connection->id] = $connection;
            }
        }
        $paths = [];
        foreach ($connections as $connection) {
            $access = $this->databaseAccess($instance, $runtime, $connection, $paths === [] ? 'database' : 'database-'.$connection->id);
            $paths[$access['host'].':'.$access['port']] ??= $access;
        }

        return array_values($paths);
    }

    /** The Node's target union, using the same endpoint source as each container's NIC policy.
     *
     * @return list<array{host: string, port: int, docker: bool}>
     */
    public function nodeEndpoints(Node $node): array
    {
        $endpoints = [];
        foreach (InstanceRuntime::query()->where('node_id', $node->id)->with('instance')->orderBy('id')->get() as $runtime) {
            foreach ($this->targetAccesses($runtime->instance, $runtime, null, app(DevelopmentMail::class)->forInstance($runtime->instance),
                InstanceNodeServiceTargets::endpoints($runtime->instance, requireRunning: false)) as $access) {
                $endpoints[$access['host'].':'.$access['port']] = ['host' => $access['host'], 'port' => $access['port'], 'docker' => $access['docker']];
            }
        }
        ksort($endpoints);

        return array_values($endpoints);
    }

    /**
     * The way to the Instance's database server: the server's address and port. A server on the Instance's own Node is reached on the
     * Node; one on another Node through the Node's WireGuard address, as the bridge's NAT sends it.
     *
     * `$name` ends the rule's comment, so an Instance with more than one server keeps a rule for each.
     *
     * @return array{host: string, port: int, docker: bool, rule: array<string, string>}
     */
    private function databaseAccess(Instance $instance, InstanceRuntime $runtime, DatabaseConnection $database, string $name = 'database'): array
    {
        $host = $database->host;
        $port = $database->port;
        if (! is_string($host) || filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false || ! is_int($port)) {
            throw new ResourceOperationException('instance.database_clone_unsupported', 'A container reaches only a database server with an IPv4 address and a port.', 422);
        }
        $local = $database->server?->node_id === $runtime->node_id;

        return ['host' => $host, 'port' => $port, 'docker' => $local && $database->server->process?->runtime === ProcessRuntime::Docker,
            'rule' => $this->tcpRule($instance, $runtime, $host, $port, $local, $name)];
    }

    /** The exact TCP path shared by attached databases and remote Node services.
     * @return array<string,string>
     */
    public function tcpRule(Instance $instance, InstanceRuntime $runtime, string $host, int $port, bool $local, string $name): array
    {
        return ['rule' => 'allow', 'interface_in' => InstanceHostIntent::Network, 'from_ip' => $runtime->bridge_address,
            'to_ip' => $host, 'to_port' => (string) $port, 'proto' => 'tcp', 'comment' => self::firewallPrefix($instance).$name,
            ...($local ? [] : ['route' => 'yes', 'interface_out' => self::WireGuardInterface])];
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
     * The container's validated vp-dev port also directs PHP's development SSR requests to its own Vite server.
     *
     * @return list<array{name: string, content: string}>
     */
    public function units(Instance $instance, InstanceRuntime $runtime, string $application, ?int $vitePort): array
    {
        $project = $instance->project;
        $version = $project->php_version;
        if ($this->webConfig($instance, $runtime) === null) {
            return [];
        }
        $path = self::path($project);
        $php = InstanceHostIntent::RuntimeFolder.'/current/php/'.$version.'/bin';
        $config = self::FrankenPhpConfig;
        $hotUrl = $vitePort !== null && $instance->placementEnvironment() === 'development'
            ? "\nEnvironment=INERTIA_SSR_HOT_URL=http://127.0.0.1:{$vitePort}" : '';

        return [['name' => self::FrankenPhpUnit, 'content' => <<<UNIT
            # Managed by Orbit.
            [Unit]
            Description=FrankenPHP
            After=network.target

            [Service]
            Type=simple
            User=orbit
            Group=orbit
            UMask=0007
            WorkingDirectory={$application}
            Environment=HOME=/home/orbit
            Environment=PATH={$path}{$hotUrl}
            ExecStart={$php}/frankenphp run --config {$config} --adapter caddyfile
            Restart=always
            RestartSec=2

            [Install]
            WantedBy=multi-user.target

            UNIT]];
    }

    /**
     * One server configuration for create, clone, deploy and migrate. FrankenPHP's native CGI variables describe the plain upstream
     * connection, even with trusted_proxies; translate only HTTPS requests from the Node, leaving every other request untouched.
     *
     * @return array{path: string, content: string}|null
     */
    public function webConfig(Instance $instance, InstanceRuntime $runtime): ?array
    {
        $project = $instance->project;
        if (! $project->type->isWebServing() || ! is_string($project->php_version)) {
            return null;
        }
        $gateway = Ipv4Subnet::from(ValidatedData::string(Node::query()->whereKey($runtime->node_id)->value('instance_bridge')))->usableRange()[0];
        $template = file_get_contents(resource_path('instances/frankenphp.Caddyfile'));
        if (! is_string($template)) {
            throw new RuntimeException('The Instance FrankenPHP configuration is missing.');
        }
        $root = InstanceHostIntent::Workspace.'/'.trim($instance->root ?? $project->root ?? 'public', '/');

        return ['path' => self::FrankenPhpConfig, 'content' => strtr($template, [
            '__ORBIT_PROXY__' => $gateway, '__ORBIT_PORT__' => (string) self::WebPort,
            '__ORBIT_ROOT__' => addcslashes($root, '\\"'),
        ])];
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
