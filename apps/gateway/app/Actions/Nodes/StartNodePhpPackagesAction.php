<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

final readonly class StartNodePhpPackagesAction
{
    public function __construct(private StartOperationAction $operations, private ManagedNodeEligibility $eligibility) {}

    /** @param list<string> $versions */
    public function execute(Node $node, array $versions): Operation
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.php_packages_unsupported', 'PHP package preparation requires an active managed Linux Node.', 422);
        }

        return $this->operations->execute('node.prepare_php_packages', 'node', $node->id, $node->id,
            ['versions' => $versions, 'profile' => 'app-prod'], 'system', null);
    }
}
