<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Data\Nodes\NodeTaskVmContainersData;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Infrastructure\WireGuard\PeerDnsPolicy;
use App\Models\InstanceRuntime;
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

    /**
     * The Instance bridge's gateway address, its first usable address, or null while the Node has no bridge. It reads the stored
     * bridge, so a caller with an older copy of the Node still gets the bridge that the admission allocated.
     */
    public static function gateway(Node $node): ?string
    {
        $cidr = $node->exists ? Node::query()->whereKey($node->id)->value('instance_bridge') : null;

        return is_string($cidr) ? Ipv4Subnet::from($cidr)->usableRange()[0] : null;
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
        $settings = app(NodeSettingsNormalizer::class)->fromStored($node->settings);
        $taskHost = $settings?->task_vms;
        // Each nested-fleet container takes one task-place-sized map. A switched-on Node keeps two spare maps for the next ones.
        $nested = $settings?->nested_fleet === true;
        $nestedRuntimes = InstanceRuntime::query()->where('node_id', $node->id)->where('incus_project', InstancePlacement::NestedProject)->count();
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
            'ids' => (self::Containers + 1) * self::IdMapSize + $taskIds + ($nestedRuntimes + ($nested ? 2 : 0)) * self::TaskPlaceIds,
            // A task VM host sizes the ARC for its task side, or keeps the Node's own (task-vm-host-prepare.yml): the Instance side
            // never changes it there.
            'arc' => $taskHost === null,
            'incus' => $incus,
            'image' => InstanceImage::for($gateway),
            // Only a switched-on Node prepares the nested-fleet project and builds its image there
            // (docs/reference/node-settings.md#nested-fleet-instance-hosting).
            ...($nested ? ['nested' => ['project' => InstancePlacement::NestedProject, 'image' => InstanceImage::for($gateway, $incus)]] : []),
            // The shared-folder refresh restarts running containers in every project that the Node's runtimes record.
            ...($nestedRuntimes > 0 ? ['runtime_projects' => InstancePlacement::projects()] : []),
            'folders' => InstanceFolderPlan::for($folders),
            // The redirect of agent development ports on the WireGuard address to Caddy's development listener.
            'dev_ports' => ['table' => InstanceDevPorts::Table, 'unit' => InstanceDevPorts::TableUnit,
                'ruleset' => app(InstanceDevPorts::class)->ruleset($node)],
            // The bridge ACL and UFW admit a container to its own site on the bridge's gateway address (InstanceOwnSite).
            'own_site' => InstanceOwnSite::for($gateway),
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
