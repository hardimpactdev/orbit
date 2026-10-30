<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\LaravelApplicationKey;
use App\Domain\Operations\OperationSecretOutputStore;
use App\Domain\Projects\ProjectType;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Operation;

/** Seeds encrypted values from native template output, never from a Swamp completion body. */
final readonly class InstanceCreateEnvironmentSeed implements OperationSecretOutputStore
{
    public function name(): string
    {
        return 'instance.create_environment';
    }

    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'instance.create' && $operation->target_type === 'instance'
            && ($operation->input['instance_id'] ?? null) === $operation->target_id ? $operation->target_id : null;
    }

    public function store(Operation $operation, int $ownerId, #[\SensitiveParameter] mixed $value): void
    {
        if ($ownerId !== $this->ownerId($operation) || ! is_string($value) || strlen($value) > 131072) {
            throw new ResourceOperationException('operation.secret_output_unproven', 'The create template does not belong to this Instance.', 422);
        }
        $instance = app(InstanceLifecycleState::class)->instance($operation);
        $context = app(InstanceEnvironmentContextResolver::class)->resolve($instance, true, createOperationId: $operation->id);
        $store = app(InstanceEnvironmentStore::class);
        $template = app(InstanceEnvironmentImporter::class)->parse($value);
        $values = [...$template, ...$store->values($instance)];
        $symfony = $instance->project->type === ProjectType::SymfonyApp;
        $values += ['APP_NAME' => $instance->project->name, 'APP_ENV' => $symfony ? 'dev' : 'development', 'APP_DEBUG' => 'true'];
        if ($context->routeDomain !== null) {
            $values['APP_URL'] = 'https://{{instance.domain}}';
        }
        if ($context->laravel && ($values['APP_KEY'] ?? '') === '') {
            $values['APP_KEY'] = LaravelApplicationKey::generate();
        }
        if ($symfony && ($values['APP_SECRET'] ?? '') === '') {
            $values['APP_SECRET'] = bin2hex(random_bytes(16));
        }
        $store->putMany($context, $values, 'create');
    }
}
