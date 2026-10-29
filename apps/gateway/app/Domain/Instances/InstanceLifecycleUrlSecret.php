<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\Operation;
use Illuminate\Support\Facades\DB;

/** Laravel configuration lines never enter an Operation, descriptor, or Swamp resource. */
final readonly class InstanceLifecycleUrlSecret implements OperationSecretSource
{
    public function name(): string
    {
        return 'instance.lifecycle_url';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        $id = $operation->input['instance_id'] ?? null;

        return $operation->target_type === 'instance' && is_int($id) && $id === $operation->target_id ? $id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($id !== $this->ownerId($operation) || ! in_array($parameter, ['app_key', 'app_name'], true)) {
            throw $this->forbidden();
        }
        $instanceId = $id;
        if ($operation->kind === 'instance.register') {
            if (preg_match('/\Aregistration-([1-9][0-9]*)-configuration\z/D', $key, $match) !== 1) {
                throw $this->forbidden();
            }
            $instanceId = (int) $match[1];
            if (! in_array($instanceId, array_column(InstanceLifecycleInput::members($operation->input), 'instance_id'), true)) {
                throw $this->forbidden();
            }
        } elseif ($operation->kind !== 'instance.create' || $key !== 'source-configuration') {
            throw $this->forbidden();
        }
        $instance = Instance::query()->with(['project', 'node'])->findOrFail($instanceId);
        if ($instance->project_id !== $operation->input['project_id'] || $instance->node_id !== $operation->node_id) {
            throw $this->forbidden();
        }
        if ($parameter === 'app_key') {
            $value = DB::transaction(function () use ($instance): string {
                $locked = Instance::query()->lockForUpdate()->findOrFail($instance->id);
                $stored = LaravelApplicationKey::stored($locked);
                if ($stored !== null) {
                    return $stored;
                }
                $generated = LaravelApplicationKey::generate();
                $locked->environmentValues()->updateOrCreate(['env_key' => 'APP_KEY'], ['env_value' => $generated]);

                return $generated;
            });

            return new OperationSecretValue('APP_KEY='.InstanceEnvironmentRenderer::quote($value), [$value]);
        }
        $name = $instance->environmentValues()->where('env_key', 'APP_NAME')->first()?->env_value;
        $value = is_string($name) && $name !== '' && ! str_contains($name, '{{') ? $name : $instance->project->name;

        $quoted = InstanceEnvironmentRenderer::quote($value);

        return new OperationSecretValue('APP_NAME='.$quoted, [$value, $quoted, substr($quoted, 1, -1)]);
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The configuration reference is not owned by this lifecycle intent.', 422);
    }
}
