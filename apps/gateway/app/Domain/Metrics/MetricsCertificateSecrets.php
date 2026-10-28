<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Certificates\GatewayCertificateIssuer;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Node;
use App\Models\Operation;
use App\Support\ValidatedData;

final readonly class MetricsCertificateSecrets implements OperationSecretSource
{
    public function __construct(private GatewayCertificateIssuer $certificates) {}

    public function name(): string
    {
        return 'metrics.certificate';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['metrics_effect'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'metrics.certificate_publish' ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || ! in_array($parameter, ['certificate', 'key'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Metrics certificate has another owner.', 422);
        }
        $node = Node::query()->findOrFail($id);
        $paths = $this->certificates->issue('metrics.orbit', ValidatedData::string($node->wireguard_ip));
        $contents = file_get_contents($parameter === 'key' ? $paths->privateKeyPath : $paths->certificatePath);
        if (! is_string($contents) || $contents === '') {
            throw new ResourceOperationException('metrics.certificate_read_failed', 'The admitted certificate is unreadable.', 422);
        }

        return new OperationSecretValue($contents);
    }
}
