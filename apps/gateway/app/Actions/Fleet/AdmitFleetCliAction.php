<?php

declare(strict_types=1);

namespace App\Actions\Fleet;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Fleet\CliConvergePlan;
use App\Domain\Fleet\FleetKitCapabilities;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;

/** Record a pinned CLI intent; Node effects belong to Swamp. */
final readonly class AdmitFleetCliAction
{
    public function __construct(private StartOperationAction $start, private CliConvergePlan $plan) {}

    public function execute(Node $node, ?string $triggeredBy = null, ?string $requestId = null): Operation
    {
        if (! FleetKitCapabilities::cliSupported()) {
            throw new ResourceOperationException('operation.kind_unavailable', 'CLI children require shared child admission and target receipts.', 409);
        }

        return $this->start->execute('fleet.cli_converge', 'node', $node->id, $node->id, $this->plan->input($node), $triggeredBy, $requestId);
    }
}
