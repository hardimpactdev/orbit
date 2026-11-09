<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Domain\Shared\ResourceOperationException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\TaskVms\RecipeImageBuilds;
use App\Models\Node;

/**
 * The task container side of a host in the intent of its host phase (docs/reference/compute-drivers.md#prepare-a-container-host): the
 * project, the bridge with its address, the stock alias, the place count that sizes the subordinate IDs, and the recipe's Incus line
 * and packages for the host's architecture, which a host without Incus installs. Null on a host without `containers`.
 */
final readonly class TaskContainerHostIntent
{
    public function __construct(private RecipeImageBuilds $recipes) {}

    /**
     * @return array{project: string, network: string, cidr: string, address: string, alias: string, places: int,
     *     incus: array{line: string, packages: list<array{name: string, sha256: string, urls: list<string>}>}}|null
     */
    public function for(Node $host, TaskVmHost $location): ?array
    {
        $containers = $location->containers;
        if ($containers === null) {
            return null;
        }
        $architecture = MachineRecipe::architecture($host->architecture)
            ?? throw new ResourceOperationException('task_vm.converge_unavailable', 'The host architecture is unsupported.', 409);
        $incus = $this->recipes->currentRecipe()->incus($architecture)
            ?? throw new ResourceOperationException('task_vm.converge_unavailable', 'The recipe holds no Incus package for this architecture.', 409);
        $subnet = Ipv4Subnet::from($containers->cidr);
        [$address] = $subnet->usableRange();

        return [
            'project' => $containers->project, 'network' => $containers->network, 'cidr' => $containers->cidr,
            'address' => $address.'/'.$subnet->prefixLength(), 'alias' => TaskVmContainers::SourceImage,
            'places' => $containers->taskPlaces + $containers->ciPlaces,
            'incus' => $incus,
        ];
    }
}
