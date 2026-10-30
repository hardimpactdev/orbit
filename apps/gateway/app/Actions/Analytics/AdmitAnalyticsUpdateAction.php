<?php

declare(strict_types=1);

namespace App\Actions\Analytics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AgentCommands\AgentDelivery;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Operation;
use App\Models\Process;

final readonly class AdmitAnalyticsUpdateAction
{
    public function __construct(private StartOperationAction $operations, private AgentDelivery $agents) {}

    public function execute(string $version): Operation
    {
        $assignment = NodeRole::query()->where('role', RoleName::Analytics)->where('status', LifecycleStatus::Active)->with('node')->first();
        if (! $assignment instanceof NodeRole) {
            throw new ResourceOperationException('analytics.role_missing', 'No Node has an active analytics role.', 409);
        }
        $node = $assignment->node;
        $process = Process::query()->where('owner_type', Node::class)->where('owner_id', $node->id)->where('name', 'plausible')->first();
        if (! $process instanceof Process) {
            throw new ResourceOperationException('analytics.runtime_missing', 'The analytics role has no Plausible Process.', 409);
        }

        return $this->operations->execute('analytics.update', 'node', $node->id, $node->id,
            ['version' => $version, 'process_id' => $process->id, 'agent_sha256' => $this->agents->pin($node)],
            'operator', request()->attributes->getString('orbit.request_id'), expectedWriter: 'direct');
    }
}
