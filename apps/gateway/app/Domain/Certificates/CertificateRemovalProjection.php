<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Operations\OperationStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppDev\DevelopmentSite;
use App\Infrastructure\AppDev\DevelopmentSiteRepository;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Stored removal intent and bounded absence projection; no Node execution. */
final readonly class CertificateRemovalProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct, private DevelopmentSiteRepository $sites) {}

    public function input(Operation $operation): array
    {
        if ($operation->kind !== 'certificate.remove' || $operation->target_type !== 'node_certificate'
            || $operation->node_id === null || $operation->target_id !== $operation->node_id) {
            throw new ResourceOperationException('operation.direct_invalid', 'A Node-bound certificate removal is required.', 422);
        }
        $scope = ValidatedData::string($operation->input['scope']);

        return [...Arr::only($operation->input, ['scope', 'home', 'agent_sha256']),
            'in_use' => $this->sites->forNode(Node::query()->findOrFail($operation->node_id))->contains(
                static fn (DevelopmentSite $site): bool => $site->loadsCertificate($scope))];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        $status = OperationStatus::from(ValidatedData::string($data['status']));
        $result = Arr::only(ValidatedData::object($data['result'] ?? []), ['present', 'error_code']);
        $code = $data['error_code'] ?? null;
        if (($status === OperationStatus::Succeeded && $result !== ['present' => false])
            || ($status === OperationStatus::Failed && (! is_string($code)
                || preg_match('/\A[a-z0-9_.-]{1,100}\z/D', $code) !== 1 || $result !== ['error_code' => $code]))
            || ($status !== OperationStatus::Failed && $code !== null)
            || (in_array($status, [OperationStatus::Running, OperationStatus::Cancelled], true) && $result !== [])) {
            throw new ResourceOperationException('operation.result_invalid', 'Certificate removal needs verified absence or a bounded refusal.', 422);
        }

        return $this->direct->project($operation, $data, $result, 'certificate-remove');
    }
}
