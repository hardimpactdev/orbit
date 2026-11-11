<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Deployment\AutomaticDeployPolicyWriter;
use App\Domain\Nodes\NodeAccessAuthorizer;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Database\Eloquent\Collection;

final readonly class ListInstancesAction
{
    public function __construct(
        private NodeAccessAuthorizer $access,
        private AutomaticDeployPolicyWriter $writer,
    ) {}

    /** @return Collection<int, Instance> */
    public function handle(Node $consumer, bool $automaticDeploy = false): Collection
    {
        return Instance::query()
            ->with(['project', 'node.roles', 'routes.targets'])
            ->when(
                ! $this->access->hasGatewayAuthority($consumer),
                fn ($query) => $query->whereIn('node_id', $this->access->accessibleNodeIds($consumer)),
            )
            // Alphabetical by name so a picker reads predictably; ids keep same-named instances of different Projects stable.
            ->orderBy('name')
            ->orderBy('id')
            ->get()
            ->when($automaticDeploy, fn (Collection $instances): Collection => $instances->filter(fn (Instance $instance): bool => $this->writer->eligibilityRefusal($instance) === null)->values());
    }
}
