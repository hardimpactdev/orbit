<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects native Swamp run status. No ownership lease or execution decisions. */
final readonly class RoutePublicationProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private OperationExecutionContract $contract) {}

    public function input(Operation $operation): array
    {
        app(DirectRoutePublication::class)->assertOperation($operation);

        return $operation->kind === 'route.caddy_converge'
            ? ['caddy_publication' => CaddyPublicationPlan::forNode(Node::query()->findOrFail($operation->node_id))] : [];
    }

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        app(DirectRoutePublication::class)->assertOperation($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $result = $this->contract->result($operation, ValidatedData::object($data['result'] ?? []));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ($status === OperationStatus::Succeeded && ($result['ready'] ?? false) !== true) {
            throw new ResourceOperationException('operation.result_invalid', 'The publication has no successful Swamp result.', 422);
        }
        if ($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code)) {
            throw new ResourceOperationException('operation.result_invalid', 'The failed publication needs its refusal code.', 422);
        }
        if (isset($result['stage']) && ! in_array($result['stage'], ['render', 'validate'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'Only a known Caddy refusal stage can be projected.', 422);
        }

        return $this->direct->project($operation, $data, $result, $operation->kind === 'route.caddy_converge' ? 'caddy-publish' : 'private-dns-publish');
    }
}
