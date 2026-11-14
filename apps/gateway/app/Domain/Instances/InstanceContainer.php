<?php

declare(strict_types=1);

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
final readonly class InstanceContainer
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

    /** The description of the NIC ACL egress rule to an attached websocket app's private endpoint; `env:sync` withdraws a stale one by it. */
    public const string WebSocketEgressDescription = 'Orbit websocket app';

    public const int T3PortLast = 3999;

    /** The Node's WireGuard interface, where T3's clients come in. */
    public const string WireGuardInterface = 'orbit';

    /** The file whose presence starts T3: the Instance, its Project and its URL. */
    public const string InstanceFile = '/etc/orbit/instance';

    /** The placeholder model key and the model origin, which T3's unit hands to Claude Code and Codex. */
    public const string ModelEnvironment = '/home/agent/.config/orbit/model.env';

    /** T3's command in a container, from the agent release its image holds. */
    public const string T3 = InstanceHostIntent::AgentFolder.'/current/agent/bin/t3';

    public function __construct(private RecipeImageBuilds $recipes) {}

    public static function name(Instance $instance): string
    {
        return 'instance-'.$instance->id;
    }

    /**
     * The workspace's path on the Node, inside the container's root filesystem, in the Incus project its runtime records. It keeps
     * the Instance's checkout path unique on its Node; Orbit never works in it from the Node. An Instance without a runtime has no
     * container: its path names the normal project and only ever fails a comparison with a real checkout path.
     */
    public static function workspaceOnNode(Instance $instance): string
    {
        $project = $instance->runtime()->value('incus_project');

        return '/var/lib/incus/storage-pools/'.InstanceHostIntent::Pool.'/containers/'.(is_string($project) ? $project : InstanceHostIntent::Project).'_'.self::name($instance)
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
        // The one placement decision, recorded once; a clone lives in the project its default's runtime records.
        $placed = InstancePlacement::place($instance->project()->firstOrFail(), $node, true);
        $incusProject = $source?->runtime()->firstOrFail()->incus_project ?? $placed;
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
            $port = array_first(array_diff(range(self::T3PortFirst, self::T3PortLast), $ports))
                ?? throw new ResourceOperationException('instance.host_unavailable', 'The Node has no free T3 port.', 409);
        }
        $plan = InstanceFolderPlan::for($folders);

        return InstanceRuntime::query()->create([
            'instance_id' => $instance->id, 'node_id' => $node->id, 'incus_project' => $incusProject,
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
        // Only a container in the nested project holds an inner fleet; its own CLI reaches the nested Gateway without the proxy.
        $nestedFleet = $runtime->incus_project === InstancePlacement::NestedProject;
        $access = $this->targetAccesses($instance, $runtime, $database, $mail, $nodeServices);
        $privateNames = self::privateNames($access)['private_names'] ?? [];
        $proxy = InstanceNodeServiceTargets::proxyEnvironment($gateway, $nodeServices, $nestedFleet, $privateNames);
        // One rule for Node services, an inner fleet and private names alike: a value other than the base is written into the container.
        $ownProxy = $proxy !== InstanceNodeServiceTargets::proxyEnvironment($gateway, [], false, []);
        $environment = [...$environment, ...$proxy];
        $clone = $runtime->t3_port === null ? null : $this->clone($instance, $runtime, $gateway, $url);
        ['vite' => $vitePort, 'server' => $devServerPort] = app(ContainerProcesses::class)->ports($instance);

        return [
            'instance_id' => $instance->id, 'project' => $runtime->incus_project, 'name' => $runtime->container_name,
            'config' => ['limits.cpu' => '4', 'limits.memory' => '8GiB', 'limits.processes' => '4096',
                ...InstancePlacement::config($runtime->incus_project),
                ...(! $ownProxy && ! isset($clone['proxy_environment']) ? []
                    : array_combine(array_map(static fn (string $key): string => 'environment.'.$key, ['NO_PROXY', 'no_proxy']), array_values($proxy)))],
            'root_disk_pool' => InstanceHostIntent::Pool, 'root_disk_size' => '40GiB',
            // A runtime recorded in the nested-fleet project starts from the nested-fleet image; every other one from the normal image.
            'image' => $runtime->incus_project === InstancePlacement::NestedProject ? InstanceImage::NestedAlias : self::Image,
            'address' => $runtime->bridge_address, 'network' => InstanceHostIntent::Network,
            ...($runtime->incus_project === InstancePlacement::NestedProject
                ? ['allowed_devices' => $this->allowedDevices($instance, $runtime), 'club_key' => NestedFleetClubKey::intent()] : []),
            'acl' => $runtime->container_name, 'nic_acl' => InstanceHostIntent::NicAcl,
            'bridge_cidr' => ValidatedData::string(Node::query()->whereKey($runtime->node_id)->value('instance_bridge')),
            'bridge_admitted_at' => $admittedAt,
            'bridge_endpoints' => array_map(static fn (array $target): array => ['host' => $target['host'], 'port' => $target['port'], 'docker' => $target['docker']], $access),
            // The common NIC ACL keeps the ways to the model forwarder and egress proxy; this one lets the Node's Caddy reach
            // FrankenPHP, a clone's T3 clients reach T3, and the container reach each attached target.
            'acl_rules' => ['description' => 'Orbit Instance '.$instance->id,
                'egress' => array_map(static fn (array $target): array => [...self::egressRule($target),
                    ...(($target['node_service'] ?? false) ? ['description' => 'Orbit Node service:'.$target['port']]
                        : (isset($target['description']) ? ['description' => $target['description']]
                        : ($mail !== null && $target['host'] === $mail['host'] && $target['port'] === $mail['port']
                            ? ['description' => 'Orbit development SMTP'] : [])))], $access),
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
            ...self::privateNames($access),
            // The units and login shells read it from the one guest writer at their next start (instance_node_services.py `proxy`).
            ...($ownProxy ? ['proxy_environment' => $proxy] : []),
            'mail' => $mail,
            'mail_units' => $mail === null ? [] : app(DevelopmentMail::class)->units($mail),
            'units' => $this->units($instance, $runtime, $application, $devServerPort),
            'web_config' => $this->webConfig($instance, $runtime),
            // `php` on every PATH in the container is the Project's static build, so `composer` runs for `agent` and `orbit` alike.
            'php' => $this->php($project),
            'clone' => $clone,
            // The Project's development Processes; a deployment's new seed restarts the running ones on the new commit.
            'processes' => app(ContainerProcesses::class)->units($instance),
        ];
    }

    /**
     * The exact effective devices that the start guard of a nested-fleet container permits: its root disk, NIC, the two read-only
     * shared folders and a clone's T3 proxy (docs/reference/development-containers.md#the-container).
     *
     * @return array<string, array<string, string>>
     */
    private function allowedDevices(Instance $instance, InstanceRuntime $runtime): array
    {
        return [
            'root' => ['type' => 'disk', 'path' => '/', 'pool' => InstanceHostIntent::Pool, 'size' => '40GiB'],
            'eth0' => ['type' => 'nic', 'name' => 'eth0', 'network' => InstanceHostIntent::Network,
                'ipv4.address' => $runtime->bridge_address, 'security.port_isolation' => 'true',
                'security.acls' => InstanceHostIntent::NicAcl.','.$runtime->container_name,
                'security.acls.default.egress.action' => 'reject', 'security.acls.default.ingress.action' => 'reject'],
            'orbit-runtime' => ['type' => 'disk', 'source' => InstanceHostIntent::RuntimeFolder, 'path' => InstanceHostIntent::RuntimeFolder, 'readonly' => 'true', 'shift' => 'true'],
            'orbit-agent' => ['type' => 'disk', 'source' => InstanceHostIntent::AgentFolder, 'path' => InstanceHostIntent::AgentFolder, 'readonly' => 'true', 'shift' => 'true'],
            ...($runtime->t3_port === null ? [] : [self::T3Device => ['type' => 'proxy',
                'listen' => 'tcp:'.ValidatedData::string($instance->node->wireguard_ip).':'.$runtime->t3_port,
                'connect' => 'tcp:'.$runtime->bridge_address.':'.InstanceHostIntent::T3Port, 'nat' => 'true']]),
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
        $nestedFleet = $runtime->incus_project === InstancePlacement::NestedProject;
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
                't3' => self::T3, 'project_mark' => '/home/agent/.config/orbit/t3-project'],
            'model_environment' => ['path' => self::ModelEnvironment, 'uid' => $agent, 'gid' => $agent, 'mode' => '0600'],
            // A copy of a default with a Node service elsewhere drops that host, and a nested-fleet copy keeps its inner fleet.
            ...($resetProxy || $nestedFleet ? ['proxy_environment' => InstanceNodeServiceTargets::proxyEnvironment($gateway, [], $nestedFleet, [])] : []),
            // The container phase writes these before a copy's first start, so its first boot, T3 included, reads them: the files every
            // container needs, which a seed taken before them lacks, then the proxy value of a copy whose default had attached Node
            // services or that runs a nested fleet. A container that a migrate builds from the image has the files already.
            ...($source === null ? [] : ['boot_files' => [...self::bootFiles(), ...($resetProxy || $nestedFleet ? $this->proxyFiles($gateway, $nestedFleet) : [])]]),
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
     * The image's files that every container needs (InstanceImage::shared) and an empty linker cache. The stock `ldconfig.service`
     * runs at boot when the cache is empty, so the copy's first start builds a cache that knows the runtime folder's `lib`; the
     * dynamic linker ignores an empty cache until then (docs/reference/development-containers.md#existing-containers). Last, the
     * Node's Orbit root CA: the clone script reads it on the Node and adds its hashed name (docs/reference/development-containers.md#trust).
     *
     * @return list<array<string, int|string>>
     */
    private static function bootFiles(): array
    {
        return [
            ...array_map(static fn (array $file): array => ['path' => $file['path'], 'content' => $file['content'], 'uid' => $file['uid'],
                'gid' => $file['gid'], 'mode' => $file['mode']], InstanceImage::shared()),
            ['path' => '/etc/ld.so.cache', 'content' => '', 'uid' => 0, 'gid' => 0, 'mode' => '0644'],
            ['source' => InstanceHostIntent::TrustSource, 'path' => InstanceHostIntent::TrustFile, 'hash_directory' => InstanceHostIntent::TrustDirectory,
                'uid' => 0, 'gid' => 0, 'mode' => '0644'],
        ];
    }

    /** A clone has no inherited Node service attachments.
     * @return list<array{path:string,content:string,uid:int,gid:int,mode:string}>
     */
    private function proxyFiles(string $gateway, bool $nestedFleet): array
    {
        $environment = InstanceNodeServiceTargets::proxyEnvironment($gateway, [], $nestedFleet, []);
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
     * @return list<array{host:string,port:int,docker:bool,node_service?:bool,name?:string,description?:string,rule?:array<string,string>}>
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
        $websocket = $this->websocketAccess($instance, $runtime);
        if ($websocket !== null && ! isset($attached[$websocket['host'].':'.$websocket['port']])) {
            $targets[] = $websocket;
        }
        if ($mail !== null && ! in_array($mail, array_map(static fn (array $target): array => array_intersect_key($target, $mail), $targets), true)) {
            $targets[] = $mail;
        }

        return $targets;
    }

    /**
     * The network paths of the Instance's attached database connections only, as `intent()` writes them: each server's ACL egress
     * rule, bridge endpoint and UFW rule. `env:sync` on a running container adds these and removes nothing.
     *
     * @return array{egress: list<array<string, string>>, bridge_endpoints: list<array{host: string, port: int, docker: bool}>, firewall: list<array<string, string>>}
     */
    public function connectionPaths(Instance $instance, InstanceRuntime $runtime): array
    {
        $access = $this->databaseAccesses($instance, $runtime, null);
        $websocket = $this->websocketAccess($instance, $runtime);

        return ['egress' => array_map(self::egressRule(...), $access),
            'bridge_endpoints' => array_map(static fn (array $target): array => ['host' => $target['host'], 'port' => $target['port'], 'docker' => $target['docker']], $access),
            'firewall' => array_column($access, 'rule'),
            // The websocket path is reconciled, not only added: a removed app closes it in the running container.
            'websocket' => $websocket === null ? null : ['egress' => [...self::egressRule($websocket), 'description' => self::WebSocketEgressDescription],
                'bridge_endpoint' => ['host' => $websocket['host'], 'port' => $websocket['port'], 'docker' => false], 'firewall' => $websocket['rule']],
            'websocket_comment' => self::firewallPrefix($instance).'websocket',
            'private_names' => $this->privateNamesOf($instance, $runtime)];
    }

    /**
     * The plan's private names of a container Instance, for each caller of the one NO_PROXY composer outside `intent()`.
     *
     * @return list<array{name: string, host: string, port: int}>
     */
    public function privateNamesOf(Instance $instance, InstanceRuntime $runtime): array
    {
        $websocket = $this->websocketAccess($instance, $runtime);

        return self::privateNames($websocket === null ? [] : [$websocket])['private_names'] ?? [];
    }

    /**
     * The exact path to the private endpoint of the Instance's websocket app, or null without one (InstanceWebSocketTarget).
     *
     * @return array{host: string, port: int, docker: false, name: string, description: string, rule: array<string, string>}|null
     */
    private function websocketAccess(Instance $instance, InstanceRuntime $runtime): ?array
    {
        $endpoint = InstanceWebSocketTarget::endpoint($instance);

        return $endpoint === null ? null : ['host' => $endpoint['host'], 'port' => $endpoint['port'], 'docker' => false, 'name' => $endpoint['name'],
            'description' => self::WebSocketEgressDescription,
            'rule' => $this->tcpRule($instance, $runtime, $endpoint['host'], $endpoint['port'], false, 'websocket')];
    }

    /**
     * The fleet private names of the plan's targets, each with the exact address and port the plan admits for it. The container has no
     * DNS server, so Orbit writes each name into the container's hosts file, and the migrate's env check admits each name at that port.
     * Absent when no target has a name, so the plan of every other Instance stays as it was.
     *
     * @param  list<array<string, mixed>>  $access
     * @return array{}|array{private_names: list<array{name: string, host: string, port: int}>}
     */
    public static function privateNames(array $access): array
    {
        $names = array_values(array_map(static fn (array $target): array => ['name' => ValidatedData::string($target['name']),
            'host' => ValidatedData::string($target['host']), 'port' => ValidatedData::integer($target['port'])],
            array_filter($access, static fn (array $target): bool => isset($target['name']))));

        return $names === [] ? [] : ['private_names' => $names];
    }

    /**
     * The ACL egress rule that lets the container reach one TCP target.
     *
     * @param  array{host: string, port: int}  $target
     * @return array<string, string>
     */
    private static function egressRule(array $target): array
    {
        return ['action' => 'allow', 'destination' => $target['host'].'/32', 'protocol' => 'tcp',
            'destination_port' => (string) $target['port'], 'state' => 'enabled'];
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

        return self::runsT3($runtime) && $route instanceof Route && $route->publication !== RoutePublication::Public;
    }

    /** Every container but the Project's default runs T3 and has development ports: a clone, and a checkout that moved into a container. */
    public static function runsT3(InstanceRuntime $runtime): bool
    {
        return $runtime->t3_port !== null;
    }

    /** Every UFW rule of a container Instance carries this comment prefix. */
    public static function firewallPrefix(Instance $instance): string
    {
        return 'orbit:instance-'.$instance->id.'-';
    }

    /**
     * FrankenPHP, in classic mode, as `orbit` from the runtime folder, for a web-serving Project with a PHP version.
     * The port of the container's dev-server preset, `vp-dev` or `vite`, also directs PHP's development SSR requests to its own Vite server.
     *
     * @return list<array{name: string, content: string}>
     */
    public function units(Instance $instance, InstanceRuntime $runtime, string $application, ?int $devServerPort): array
    {
        $project = $instance->project;
        $version = $project->php_version;
        if ($this->webConfig($instance, $runtime) === null) {
            return [];
        }
        $path = self::path($project);
        $php = InstanceHostIntent::RuntimeFolder.'/current/php/'.$version.'/bin';
        $config = self::FrankenPhpConfig;
        $hotUrl = $devServerPort !== null && $instance->placementEnvironment() === 'development'
            ? "\nEnvironment=INERTIA_SSR_HOT_URL=http://127.0.0.1:{$devServerPort}" : '';

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
