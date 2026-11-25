<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolConvergeIntent;
use App\Domain\Tools\ToolNodeEligibility;
use App\Infrastructure\Swamp\SwampSshExecutionInput;
use App\Models\Node;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

final readonly class StartToolConvergeAction
{
    public function __construct(private ToolConvergeIntent $intent, private ToolNodeEligibility $eligibility,
        private SwampSshExecutionInput $ssh, private StartOperationAction $start, private OperationIdempotency $idempotency) {}

    public function execute(Node $node, string $requestId): Operation
    {
        if (($existing = $this->idempotency->replay('tool.converge')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($node, $requestId): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->status !== LifecycleStatus::Active || ! $this->eligibility->allows($node)) {
                throw new ResourceOperationException('tool.node_unmanaged', 'Tool convergence requires an active, SSH-managed Node.', 409);
            }
            $tools = $this->intent->capture($node);
            $lock = "node_tools:{$node->id}:all";
            if ($tools === []) {
                return $this->start->record('tool.converge', 'node_tools', $node->id, $node->id, ['tools' => []],
                    ['changed' => 0, 'tools' => [], 'outcome' => 'unchanged', 'cleanup_complete' => true], 'operator', $requestId, $lock);
            }
            $intent = ['node_id' => $node->id, 'user' => $node->user, 'tools' => $tools, 'ssh' => $this->ssh->forNode($node)];

            return $this->start->execute('tool.converge', 'node_tools', $node->id, $node->id, ['intent' => $intent], 'operator', $requestId, lockKey: $lock);
        });
    }
}
