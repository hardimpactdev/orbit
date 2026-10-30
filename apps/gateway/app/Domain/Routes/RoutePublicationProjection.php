<?php

declare(strict_types=1);

namespace App\Domain\Routes;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationExecutionContract;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentDnsConfigRenderer;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Projects native Swamp run status. No ownership lease or execution decisions. */
final readonly class RoutePublicationProjection implements DirectOperationProjection
{
    public const array Kinds = ['route.caddy_converge', 'route.private_dns_publish'];

    public function __construct(private DirectOperation $direct, private OperationExecutionContract $contract) {}

    public function input(Operation $operation): array
    {
        $this->assertOperation($operation);
        $node = Node::query()->findOrFail($operation->node_id);
        if ($operation->kind === 'route.caddy_converge') {
            return ['caddy_publication' => CaddyPublicationPlan::forNode($node)];
        }
        if (! PrivateDnsPublicationNodes::query()->whereKey($node->id)->exists()) {
            throw new ResourceOperationException('operation.execution_forbidden', 'Only an active VPN Node publishes private DNS.', 422);
        }
        $renderer = app(DevelopmentDnsConfigRenderer::class);
        $catalog = json_encode(['requesters' => $renderer->registeredRequesters(), ...$renderer->catalog()->toPublished()], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";

        return ['dns_publication' => ['node_id' => $node->id, 'records' => $renderer->render(), 'catalog' => $catalog, 'digest' => hash('sha256', $catalog)]];
    }

    /** @param array<string, mixed> $data */
    public function apply(Operation $operation, array $data): Operation
    {
        $this->assertOperation($operation);
        $status = OperationStatus::from(ValidatedData::string($data['status'] ?? 'running'));
        $result = $this->contract->result($operation, ValidatedData::object($data['result'] ?? []));
        $code = ValidatedData::nullableString($data['error_code'] ?? null);
        if ($status === OperationStatus::Succeeded && ($result['ready'] ?? false) !== true) {
            throw new ResourceOperationException('operation.result_invalid', 'The publication has no successful Swamp result.', 422);
        }
        $digest = data_get($result, 'data.digest');
        if ($status === OperationStatus::Succeeded && $operation->kind === 'route.private_dns_publish'
            && (($result['outcome'] ?? null) !== 'published' || data_get($result, 'data.ready') !== true
                || ! is_string($digest) || preg_match('/\A[0-9a-f]{64}\z/D', $digest) !== 1)) {
            throw new ResourceOperationException('operation.result_invalid', 'Private DNS needs its confirmed catalog digest.', 422);
        }
        if ($status === OperationStatus::Failed && ($code === null || ($result['error_code'] ?? null) !== $code)) {
            throw new ResourceOperationException('operation.result_invalid', 'The failed publication needs its refusal code.', 422);
        }
        if (isset($result['stage']) && ! in_array($result['stage'], ['render', 'validate'], true)) {
            throw new ResourceOperationException('operation.result_invalid', 'Only a known Caddy refusal stage can be projected.', 422);
        }

        return $this->direct->project($operation, $data, $result, $operation->kind === 'route.caddy_converge' ? 'caddy-publish' : 'private-dns-publish');
    }

    private function assertOperation(Operation $operation): void
    {
        if (! in_array($operation->kind, self::Kinds, true) || ! DirectOperation::owns($operation) || $operation->node_id === null) {
            throw new ResourceOperationException('route.publication_invalid', 'A Node-bound Route publication is required.', 422);
        }
    }
}
