<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Operations\OperationChildren;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** B verifies the exact parent intent before this owning adapter creates any effect. */
final readonly class MetricsEffectAdmission implements OperationChildAdmission
{
    public function __construct(private StartOperationAction $operations, private OperationChildren $children) {}

    public function admit(Operation $parent, array $intent): Operation
    {
        $kind = ValidatedData::string($intent['kind']);
        $nodeId = ValidatedData::integer($intent['node_id']);
        $input = ValidatedData::object($intent['input']);
        $node = Node::query()->findOrFail($nodeId);
        if ($parent->kind !== 'metrics.reconcile' || $node->platform !== 'linux' || ! in_array($kind, MetricsReceiptCommand::Effects, true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'This is not an admitted Metrics reconciliation effect.', 422);
        }
        if ($kind === 'metrics.service_caddy_restore') {
            $restored = $this->children->child($parent, ValidatedData::string($input['restore_key']));
            if ($restored->kind !== 'metrics.service_restore' || $restored->node_id !== $nodeId || ! $restored->status->terminal()) {
                throw new ResourceOperationException('operation.child_unproven', 'The service restoration is not settled on this Node.', 409);
            }
            $input = [...$input, 'effects' => ['integration-receipt' => ['key' => 'integration-receipt', 'step' => 'integration-receipt',
                'transport' => 'gateway', 'name' => 'metrics.receipt', 'input' => ['backend' => 'active'], 'resumable' => true, 'required' => true]],
                'flow' => [['type' => 'child', 'key' => 'service-caddy-restore', 'kind' => 'route.caddy_converge', 'node_id' => $nodeId, 'input' => ['reason' => 'metrics service rollback']],
                    ['type' => 'run', 'key' => 'integration-receipt']]];
        }

        return $this->operations->execute($kind, 'metrics_effect', $nodeId, $nodeId, $input, 'integration', null);
    }
}
