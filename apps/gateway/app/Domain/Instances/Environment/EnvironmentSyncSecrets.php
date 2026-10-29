<?php

declare(strict_types=1);

namespace App\Domain\Instances\Environment;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;

final readonly class EnvironmentSyncSecrets implements OperationSecretSource
{
    public function __construct(private EnvironmentSyncMaterial $material) {}

    public function name(): string
    {
        return 'instance.environment_sync';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'instance.sync_environment' && $operation->target_type === 'instance'
            ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) === $id && str_starts_with($key, 'route.url.') && in_array($parameter, ['app_key', 'app_name', 'app_name_stored'], true)) {
            $this->material->context($operation);
            if (! array_any($this->material->routeUrls($operation), static fn (array $descriptor): bool => $descriptor['key'] === $key)) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Route URL does not belong to this environment intent.', 422);
            }
            $instance = Instance::query()->with('project')->findOrFail($id);
            if ($parameter === 'app_key') {
                $value = LaravelApplicationKey::generate();

                return new OperationSecretValue('APP_KEY='.InstanceEnvironmentRenderer::quote($value), [$value]);
            }
            $name = $instance->environmentValues()->where('env_key', 'APP_NAME')->first()?->env_value;
            $stored = is_string($name) && $name !== '' && ! str_contains($name, '{{');
            if ($parameter === 'app_name_stored') {
                return new OperationSecretValue($stored);
            }
            $value = $stored ? $name : $instance->project->name;

            return new OperationSecretValue('APP_NAME='.InstanceEnvironmentRenderer::quote($value), [$value]);
        }
        if ($this->ownerId($operation) !== $id || $parameter !== 'content' || ! in_array($key, ['environment.write', 'environment.testing'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The protected environment reference is not admitted.', 422);
        }
        $rendered = $this->material->render($operation, $key === 'environment.testing');

        return new OperationSecretValue($rendered['contents'], array_values($rendered['values']));
    }
}
