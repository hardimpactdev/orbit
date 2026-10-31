<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Nodes\NodePhpPackagesInput;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class StartNodePhpPackagesAction
{
    public function __construct(private StartOperationAction $operations, private ManagedNodeEligibility $eligibility, private NodePhpPackagesInput $packages) {}

    /** @param array<array-key,mixed> $versions */
    public function execute(Node $node, array $versions): Operation
    {
        if ($node->platform !== 'linux' || ! $this->eligibility->isManagedForObservation($node)
            || ! in_array($node->status, [LifecycleStatus::Active, LifecycleStatus::Failed], true)) {
            throw new ResourceOperationException('app-prod.php_package_source_unavailable', 'PHP package preparation requires a managed Linux Node.', 422);
        }

        return $this->operations->execute('node.prepare_php_packages', 'node', $node->id, $node->id,
            ['packages' => $this->packages->forNode($node->load('roles'), $versions)], 'system', null);
    }
}
