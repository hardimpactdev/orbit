<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\ServiceMetricsProjection;
use App\Models\Node;
use App\Models\Operation;

final readonly class MetricsRuntimeSecrets implements OperationSecretSource
{
    public function __construct(private MetricsCredentialManager $credentials, private MetricsConfigurationRenderer $configurations, private ServiceMetricsProjection $services) {}
    public function name(): string { return 'metrics.runtime'; }
    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'metrics.runtime_converge' ? $operation->node_id : null;
    }
    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $parameter !== 'action') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Metrics runtime has another owner.', 422);
        }
        $node = Node::query()->findOrFail($id);
        $password = $this->credentials->passwordForConvergence($node);
        $targets = $operation->input['targets'];
        $services = array_values(array_filter($this->services->forFleet($node), static fn ($target): bool => in_array($target->node->id, $operation->input['service_node_ids'], true)));
        $bundle = $this->configurations->render($targets, $password, $services);
        $files = [];
        foreach ($bundle->files as $file) {
            $input = $file->contents->input();
            try { $files[$file->path] = stream_get_contents($input->stream()); }
            finally { $input->close(); }
        }
        return new OperationSecretValue(['operation' => 'converge', 'assignment' => $operation->input['assignment_id'], 'configuration' => $files], [$password]);
    }
}
