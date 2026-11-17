<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Proof\ProofCopyHosts;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\InstanceFolders;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\InstanceRuntime;
use App\Models\Node;
use LogicException;

/**
 * The container branch of the `app-dev` role converge (docs/reference/development-containers.md#nodes): the Incus project on the
 * Node's pool, the Instance bridge, the image and the two shared folders. Only a Node on the build list gets it, and never a task
 * VM's own Node: a task VM keeps its checkout workspace. A CI-only Node (ProofCopyHosts::ciOnly) gets the same branch through its
 * `ci-runner` converge, with its nested part and without the parts that its Caddy would serve: it runs no Caddy
 * (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node). A task VM host does get it, beside its task side: the two sides keep their
 * own Incus projects, bridges, pools and firewall rules, and the task side keeps the ZFS ARC. The admission that writes allocates
 * the Node's bridge first (`prepare`), and the Node keeps it; the intent only reads it. Null for every other Node, whose converge
 * does what it did before.
 */
final readonly class InstanceHostIntent
{
    public const string Project = 'orbit-instances';

    public const string Network = 'orbitinst0';

    public const string NicAcl = self::Network.'-proxy';

    /**
     * The address filters of every Instance NIC: it accepts only its own IPv4 and MAC address. Swamp holds the same keys in
     * `instance_network.py` (Python) and `instance-nic-filters.json` (Ansible); `swamp/tests/instance_clone_test.py` keeps the three
     * equal (docs/reference/development-containers.md#network).
     *
     * @var array<string, string>
     */
    public const array NicFilters = ['security.ipv4_filtering' => 'true', 'security.mac_filtering' => 'true'];

    /** The Incus storage pool the Node provides: ZFS, so a copy is a clone, or `dir` on a nested proof Node. */
    public const string Pool = 'orbit-instances';

    public const string StockAlias = 'ubuntu-26.04-instance-stock';

    public const string RuntimeFolder = '/opt/orbit-runtime';

    public const string AgentFolder = '/opt/orbit-agent';

    public const string Workspace = '/srv/workspace';

    /**
     * The Orbit root CA that every Node's base converge writes for its agent: the one Node file that a container gets
     * (docs/reference/development-containers.md#trust).
     */
    public const string TrustSource = '/etc/orbit/agent/ca.pem';

    /** Where a container holds that root, as a local CA file; its links in `TrustDirectory` are the ones `update-ca-certificates` makes. */
    public const string TrustFile = '/usr/local/share/ca-certificates/orbit-managed-root-ca.crt';

    public const string TrustDirectory = '/etc/ssl/certs';

    /**
     * The runtime folder's PKCS#11 trust module, relative to a folder version: Chromium reads local roots only from the user's NSS
     * store, and this module gives that store the system bundle (docs/reference/development-containers.md#trust). It sits outside
     * `lib`, which the dynamic linker reads, so its name never enters a container's linker cache.
     */
    public const string TrustModule = 'pkcs11/p11-kit-trust.so';

    public const int AgentUid = 2750;

    /**
     * The app account of a container (development-containers.md#the-app-users-name): the stock Debian `www-data`, uid and gid 33, with its
     * stock home. FrankenPHP, every Process and Schedule run as it; `agent` is in its group.
     */
    public const string AppUser = 'www-data';

    public const string AppHome = '/var/www';

    public const int AppUid = 33;

    public const int AppGid = 33;

    /**
     * The nested-fleet container's `orbit` and `work`: its inner guests are Nodes whose `orbit` the ID map puts on these numbers, and
     * the inner fleet runs as them over the shared checkout (ADR 0268, second amendment).
     */
    public const int OrbitUid = 1000;

    public const int WorkGid = 2760;

    public const int T3Port = 3773;

    /** The model forwarder on the bridge's gateway address, which the model route PR builds. */
    public const int ModelForwarderPort = 8319;

    /** One isolated ID map per container, and at most one container per T3 port, 3800 to 3999. */
    public const int Containers = 200;

    public const int IdMapSize = 65536;

    /**
     * The isolated range of each task container place and the base on a task container host (compute-drivers.md#the-host-system). A
     * new nested-fleet default is made with it too, until its conversion (development-containers.md#the-id-map-size).
     */
    public const int TaskPlaceIds = 101000000;

    /**
     * The size of a nested-fleet container whose `nested-app-dev` hosts Instances. The inner Incus takes its range from its own map:
     * 1,000,000 up to this size. `nested-app-dev`'s own Incus then keeps 934,464 IDs above base 65,536: 13 isolated Instances. A size whose
     * inner range reaches 1,065,536 again, such as 2,165,536, moves that base to 1,000,000 and leaves room for one
     * (development-containers.md#instance-host-in-the-fleet).
     */
    public const int NestedIdMapSize = 2000000;

    /**
     * The converted size before it: 165,536 inner IDs, no isolated Instance on `nested-app-dev`, and the rollback of its window. The floor is
     * 1,065,536 (development-containers.md#the-id-map-size).
     */
    public const int ConvertedIdMapSize = 1165536;

    public function __construct(
        private ContainerNodes $nodes,
        private AllocateInstanceBridgeAction $bridges,
        private RecipeImageBuilds $recipes,
        private PeerDnsPolicy $privateDns,
    ) {}

    /** Allocates the Node's bridge in the admission transaction of a role add or converge, before the intent is captured. */
    public function prepare(Node $node): void
    {
        if ($this->role($node) !== null) {
            $this->bridges->execute($node);
        }
    }

    /**
     * The role whose converge runs the branch on the Node: `ci-runner` on a CI-only Node (ProofCopyHosts::ciOnly), `app-dev` on a Node of
     * the build list, and null for every other Node.
     */
    public function role(Node $node): ?RoleName
    {
        if (ProofCopyHosts::ciOnly($node)) {
            return RoleName::CiRunner;
        }

        return $this->nodes->includes($node) ? RoleName::AppDev : null;
    }

    /**
     * The Instance bridge's gateway address, its first usable address, or null while the Node has no bridge. It reads the stored
     * bridge, so a caller with an older copy of the Node still gets the bridge that the admission allocated.
     */
    public static function gateway(Node $node): ?string
    {
        $cidr = $node->exists ? Node::query()->whereKey($node->id)->value('instance_bridge') : null;

        return is_string($cidr) ? Ipv4Subnet::from($cidr)->usableRange()[0] : null;
    }

    /**
     * The digest of the Node's branch intent as the converge would send it, without the admission time: what the nested-fleet refresh
     * compares to rebuild the Instance host on nested-app-dev only when the image, the folders, Incus or the rest changed
     * (docs/reference/development-containers.md#instance-host-in-the-fleet). Null for a Node without the branch, or before the admission
     * allocated its bridge.
     */
    public function digest(Node $node): ?string
    {
        try {
            $intent = $this->for($node);
        } catch (ResourceOperationException) {
            return null;
        }

        return $intent === null ? null
            : hash('sha256', json_encode(array_diff_key($intent, ['bridge_admitted_at' => true]), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /** @return array<string, mixed>|null */
    public function for(Node $node): ?array
    {
        $admittedAt = (int) now()->format('Uu');
        $role = $this->role($node);
        if ($role === null) {
            return null;
        }
        $ci = $role === RoleName::CiRunner;
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
        // Instance containers and the CI places of the same host take their isolated ID maps from root's one range.
        $settings = app(NodeSettingsNormalizer::class)->fromStored($node->settings);
        // Each nested-fleet container takes the map its runtime records. A switched-on Node keeps two spare maps of a new default's size.
        // A CI Node hosts nested-fleet copies without the switch.
        $nested = $ci || $settings?->nested_fleet === true;
        $nestedMaps = InstanceRuntime::query()->where('node_id', $node->id)->where('incus_project', InstancePlacement::NestedProject)->pluck('idmap_size');
        $nestedRuntimes = $nestedMaps->count();
        $nestedIds = $nestedMaps->sum(static fn (mixed $size): int => is_int($size) ? $size : throw new LogicException('A nested-fleet runtime records its ID map size.'));
        // Each CI place gets one full map, and the base one more (docs/reference/node-settings.md#nested-fleet-instance-hosting).
        $ciPlaces = $settings?->ci()?->places;
        $taskIds = $ciPlaces === null ? 0 : ($ciPlaces + 1) * self::TaskPlaceIds;
        $subnet = Ipv4Subnet::from($cidr);
        [$gateway, $last] = $subnet->usableRange();
        $fleetNode = ContainerNodes::fleetNode($node);
        // An Instance fleet Node goes out only through the fleet's egress proxy; its own proxy must never go direct
        // (docs/reference/development-containers.md#instance-host-in-the-fleet).
        $parent = $fleetNode ? (InstanceFleetGateway::egressProxy()
            ?? throw new ResourceOperationException('instance.fleet_egress_unavailable', 'The nested Gateway reads no egress proxy from HTTPS_PROXY in '
                .config()->string('instances.fleet_environment').', so the Instance fleet Node\'s egress proxy would have no way out.', 409)) : null;

        return [
            'project' => self::Project, 'network' => self::Network, 'nic_acl' => self::NicAcl, 'pool' => self::Pool, 'cidr' => $cidr,
            // A CI-only Node's converge creates the pool; every other Node's operator provides it
            // (docs/reference/node-provisioning.md#proof-copies-on-a-ci-node).
            ...($ci ? ['create_pool' => true] : []),
            // An Instance fleet Node installs Incus and creates a directory pool first
            // (docs/reference/development-containers.md#instance-host-in-the-fleet).
            ...($fleetNode ? ['fleet_node' => true] : []),
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
            'egress' => InstanceEgress::for(self::Network, $gateway, $cidr, $this->privateDns->domains(), $parent),
            // A CI Node needs a map of the proof default's size for each CI place, its seed holder and a snapshot that a seed copy adds
            // (docs/reference/node-settings.md#a-ci-node).
            'ids' => (self::Containers + 1) * self::IdMapSize + ($ci
                ? (ProofCopyHosts::places($node) + 2) * ProofCopyHosts::idMapSize()
                : $taskIds + $nestedIds + ($nested ? 2 : 0) * self::TaskPlaceIds),
            'incus' => $incus,
            'image' => InstanceImage::for($gateway),
            // Only a switched-on Node prepares the nested-fleet project and builds its image there
            // (docs/reference/node-settings.md#nested-fleet-instance-hosting).
            ...($nested ? ['nested' => ['project' => InstancePlacement::NestedProject, 'image' => InstanceImage::for($gateway, $incus)]] : []),
            // The shared-folder refresh restarts running containers in every project that the Node's runtimes record.
            ...($nestedRuntimes > 0 ? ['runtime_projects' => InstancePlacement::projects()] : []),
            'folders' => InstanceFolderPlan::for($folders, InstanceFolderPlan::shift($node)),
            // The presented machine name of each container on the Node, which the converge writes into its files (InstanceMachineName).
            'machine_names' => InstanceMachineName::forNode($node),
            // The drop-ins of T3's unit in each container with a T3, which the converge rewrites when their text changed and then restarts
            // T3 in a running one (docs/reference/development-containers.md#existing-containers).
            't3_files' => self::t3Files($node, $folders),
            // What the Node's Caddy serves, which a CI Node does not run: the redirect of agent development ports on the WireGuard
            // address to Caddy's development listener, and a container's own site on the bridge's gateway address (InstanceOwnSite),
            // which the bridge ACL and UFW admit. Without them the converge installs no wake of frozen containers either.
            ...($ci ? [] : [
                'dev_ports' => ['table' => InstanceDevPorts::Table, 'unit' => InstanceDevPorts::TableUnit,
                    'ruleset' => app(InstanceDevPorts::class)->ruleset($node)],
                'own_site' => InstanceOwnSite::for($gateway),
            ]),
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
     * Each container on the Node with a T3, a clone or a moved checkout, and its unit drop-ins as its create writes them
     * (InstanceContainer::t3DropIns). A default runs no T3.
     *
     * @return list<array{project: string, container: string, files: list<array{path: string, content: string, mode: string}>}>
     */
    private static function t3Files(Node $node, InstanceFolders $folders): array
    {
        return array_values(InstanceRuntime::query()->where('node_id', $node->id)->whereNotNull('t3_port')->orderBy('incus_project')->orderBy('container_name')->get()
            ->map(static fn (InstanceRuntime $runtime): array => ['project' => $runtime->incus_project, 'container' => $runtime->container_name,
                'files' => InstanceContainer::t3DropIns($runtime, $folders)])->all());
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
}
