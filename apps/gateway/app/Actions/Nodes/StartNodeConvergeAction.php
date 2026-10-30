<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\ManagedNodeEligibility;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeWorkerEnrollment;
use App\Models\Node;
use App\Models\Operation;

/** Admits the same footprint kind used as a child by fleet rollout. */
final readonly class StartNodeConvergeAction
{
    public function __construct(private ManagedNodeEligibility $eligibility, private StartOperationAction $operations) {}

    /** @param array{enrollment_token:string,server_token:string}|null $workerCredentials */
    public function execute(Node $node, bool $force = false, bool $firstVisit = false, ?string $requestId = null, #[\SensitiveParameter] ?array $workerCredentials = null): Operation
    {
        if (! $this->eligibility->allows($node)) {
            throw new ResourceOperationException('node.converge_unsupported', 'Only an active, managed Linux Node has a Gateway-rendered footprint.', 422);
        }

        $reference = $workerCredentials === null ? null : app(NodeWorkerEnrollment::class)->stage($node, $workerCredentials);

        return $this->operations->execute('node.converge', 'node', $node->id, $node->id,
            ['force_artifacts' => $force, 'first_visit' => $firstVisit, ...($reference === null ? [] : ['_worker_enrollment' => $reference])], 'operator', $requestId);
    }
}
