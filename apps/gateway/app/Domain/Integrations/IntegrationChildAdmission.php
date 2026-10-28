<?php

declare(strict_types=1);

namespace App\Domain\Integrations;

use App\Actions\Operations\StartOperationAction;
use App\Domain\Operations\OperationChildAdmission;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Admits only this family's narrow effects after B checks the parent's exact recorded key. */
final readonly class IntegrationChildAdmission implements OperationChildAdmission
{
    public function __construct(private StartOperationAction $operations) {}
    public function admit(Operation $parent, array $intent): Operation
    {
        $kind = ValidatedData::string($intent['kind']);
        $nodeId = ValidatedData::integer($intent['node_id']);
        $input = ValidatedData::object($intent['input']);
        $node = Node::query()->findOrFail($nodeId);
        if ($node->platform !== 'linux') { throw new ResourceOperationException('operation.execution_forbidden', 'Integration effects require their serving Linux Node.', 422); }
        $target = match (true) {
            preg_match('/\Ametrics\.(?:node_exporter|cadvisor)_(?:snapshot|converge|remove|restore)\z/D', $kind) === 1,
            in_array($kind, ['metrics.service_snapshot', 'metrics.service_converge', 'metrics.service_restore', 'metrics.runtime_converge', 'metrics.runtime_remove', 'metrics.runtime_verify', 'metrics.certificate_publish', 'metrics.certificate_remove', 'metrics.publication_firewall'], true) => 'metrics_effect',
            $kind === 'analytics.clickhouse_converge' => 'analytics_clickhouse',
            $kind === 'ai_router.collector_converge' && $input === [] => 'ai_router',
            default => throw new ResourceOperationException('operation.execution_forbidden', 'This is not an owned integration effect.', 422),
        };
        return $this->operations->execute($kind, $target, $nodeId, $nodeId, $input, 'integration', null);
    }
}
