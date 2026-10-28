<?php

declare(strict_types=1);

namespace App\Domain\Certificates;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Hydrates public PEM only at the Node transport boundary, from its proven signing receipt. */
final readonly class CertificateLeafSource implements OperationSecretSource
{
    public function name(): string
    {
        return 'certificate.leaf';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['node_certificate'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'certificate.converge' ? $operation->id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || ! in_array($parameter, ['certificate', 'root_certificate'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The signed certificate does not belong to this Node and scope.', 422);
        }
        $encoded = data_get($operation->state, 'executor_runs.certificate-sign.result.'.$parameter.'_base64');
        $value = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (! is_string($value) || ! str_contains($value, '-----BEGIN CERTIFICATE-----')) {
            throw new ResourceOperationException('certificate.receipt_invalid', 'The signing receipt is invalid.', 409);
        }

        return new OperationSecretValue($value);
    }
}
