<?php

declare(strict_types=1);

namespace App\Actions\Tools;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationIdempotency;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\AptSourceTrust;
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

    /** @param list<int>|null $toolIds */
    public function execute(Node $node, string $requestId, ?array $toolIds = null): Operation
    {
        if (($existing = $this->idempotency->replay('tool.converge')) !== null) {
            return $existing;
        }

        return DB::transaction(function () use ($node, $requestId, $toolIds): Operation {
            $node = Node::query()->lockForUpdate()->findOrFail($node->id);
            if ($node->status !== LifecycleStatus::Active || ! $this->eligibility->allows($node)) {
                throw new ResourceOperationException('tool.node_unmanaged', 'Tool convergence requires an active, SSH-managed Node.', 409);
            }
            $tools = $this->intent->capture($node, ids: $toolIds);
            if ($tools === []) {
                return $this->start->record('tool.converge', 'node_tools', $node->id, $node->id, ['tools' => []],
                    ['changed' => 0, 'tools' => [], 'outcome' => 'unchanged', 'cleanup_complete' => true], 'operator', $requestId);
            }
            $scopes = implode('+', ToolConvergeIntent::scopes($tools));
            $seconds = ToolConvergeIntent::phaseSeconds(count($tools));
            $intent = ['node_id' => $node->id, 'user' => $node->user, 'tools' => $tools, 'tools_seconds' => $seconds, 'ssh' => $this->ssh->forNode($node)];

            $operation = $this->start->execute('tool.converge', 'node_tools', $node->id, $node->id, ['intent' => $intent, 'run_budget_seconds' => $seconds + 200], 'operator', $requestId, lockKey: "node_tools:{$node->id}:converge:{$scopes}");
            if ($operation->wasRecentlyCreated) {
                AptSourceTrust::suspectPlans($node->id, $tools);
            }

            return $operation;
        });
    }
}
