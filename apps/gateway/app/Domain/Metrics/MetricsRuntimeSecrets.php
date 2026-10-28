<?php

declare(strict_types=1);

namespace App\Domain\Metrics;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsConfigurationRenderer;
use App\Infrastructure\Metrics\ServiceMetricsNode;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Operation;

final readonly class MetricsRuntimeSecrets implements OperationSecretSource
{
    public function __construct(private MetricsCredentialManager $credentials, private MetricsConfigurationRenderer $configurations) {}

    public function name(): string
    {
        return 'metrics.runtime';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['metrics_effect'];
    }

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
        $targets = MetricsIntent::targets($operation);
        $services = [];
        foreach (MetricsIntent::fleet($operation, 'services') as $admitted) {
            $serving = Node::query()->findOrFail($admitted['node_id']);
            $instances = [];
            foreach ($admitted['instance_ids'] as $instanceId) {
                $instance = Instance::query()->findOrFail($instanceId);
                if ($instance->node_id !== $serving->id) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The admitted service Instance changed Node owner.', 422);
                }
                $instances[] = $instance;
            }
            $serving->wireguard_ip = $admitted['address'];
            $serving->name = $admitted['name'];
            $services[] = new ServiceMetricsNode($serving, $admitted['caddy'], $admitted['fpm'], $instances, $admitted['hosts']);
        }
        $bundle = $this->configurations->render($targets, $password, $services);
        $files = [];
        foreach ($bundle->files as $file) {
            $input = $file->contents->input();
            try {
                $files[$file->path] = stream_get_contents($input->stream());
            } finally {
                $input->close();
            }
        }

        return new OperationSecretValue(['operation' => 'converge', 'assignment' => $operation->input['assignment_id'], 'configuration' => $files], [$password]);
    }
}
