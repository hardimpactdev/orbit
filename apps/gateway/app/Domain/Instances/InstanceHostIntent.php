<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Actions\Nodes\AllocateInstanceBridgeAction;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;
use App\Models\TaskVm;

/**
 * The container branch of the `app-dev` role converge (docs/reference/development-containers.md#nodes): the Incus project on the
 * Node's pool, the Instance bridge, the image and the two shared folders. Only a Node on the build list gets it, and never a task
 * Node: a task VM keeps its checkout workspace. The first intent allocates the Node's bridge, which the Node then keeps. Null for
 * every other Node, whose converge does what it did before.
 */
final readonly class InstanceHostIntent
{
    public const string Project = 'orbit-instances';

    public const string Network = 'orbitinst0';

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

    public function __construct(
        private ContainerNodes $nodes,
        private AllocateInstanceBridgeAction $bridges,
        private RecipeImageBuilds $recipes,
    ) {}

    /** @return array<string, mixed>|null */
    public function for(Node $node): ?array
    {
        if (! $this->nodes->includes($node) || TaskVm::query()->where('node_id', $node->id)->exists()) {
            return null;
        }
        $architecture = MachineRecipe::architecture($node->architecture)
            ?? throw new ResourceOperationException('instance.host_unavailable', 'The Node architecture is unsupported.', 409);
        $recipe = $this->recipes->currentRecipe();
        $incus = $recipe->incus($architecture)
            ?? throw new ResourceOperationException('instance.host_unavailable', 'The recipe holds no Incus package for this architecture.', 409);
        $folders = $recipe->instances
            ?? throw new ResourceOperationException('instance.host_unavailable', 'The recipe holds no shared Instance folders.', 409);
        $cidr = $this->bridges->execute($node);
        $subnet = Ipv4Subnet::from($cidr);
        [$gateway, $last] = $subnet->usableRange();

        return [
            'project' => self::Project, 'network' => self::Network, 'pool' => self::Pool, 'cidr' => $cidr,
            'address' => $gateway.'/'.$subnet->prefixLength(),
            // The image build container takes the bridge's last address, which no Instance gets, and only it reaches the internet.
            'build_address' => $last,
            'stock' => self::StockAlias,
            'ids' => (self::Containers + 1) * self::IdMapSize,
            'incus' => $incus,
            'image' => InstanceImage::for($gateway),
            'folders' => InstanceFolderPlan::for($folders),
        ];
    }
}
