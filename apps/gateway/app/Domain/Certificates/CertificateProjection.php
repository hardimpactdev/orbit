<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\DirectOperationProjection;
use App\Domain\Routes\NativeCaddyPublicationInput;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;
use Illuminate\Support\Arr;

/** Admitted input and bounded public results. Swamp owns execution and its proof. */
final readonly class CertificateProjection implements DirectOperationProjection
{
    public function __construct(private DirectOperation $direct) {}

    public function input(Operation $operation): array
    {
        return ['intent' => ['leaf' => $operation->input,
            ...app(NativeCaddyPublicationInput::class)->connectionForNode(Node::query()->findOrFail($operation->node_id))],
            'execution_secrets' => []];
    }

    public function apply(Operation $operation, array $data): Operation
    {
        return $this->direct->project($operation, $data, Arr::only(ValidatedData::object($data['result'] ?? []), ['present', 'error_code']));
    }
}
