<?php

declare(strict_types=1);

namespace App\Domain\Instances\Deployment;

use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Logs\LogRedactor;
use App\Domain\Operations\DirectOperation;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerIntent;
use App\Domain\Tools\ToolManagerName;
use App\Models\Instance;
use App\Models\Operation;
use App\Support\ValidatedData;

/** Protected values are resolved only after the exact descriptor has been authorized. */
final readonly class InstanceDeploymentSecrets implements OperationSecretSource
{
    public function __construct(
        private RepositoryReadAccess $repositories,
        private LogRedactor $redactor,
    ) {}

    public function name(): string
    {
        return 'instance.deployment';
    }

    /** @return list<string> */
    public function targetTypes(): array
    {
        return ['instance'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return in_array($operation->kind, ['instance.deploy', 'instance.rollback'], true) && $operation->target_type === 'instance'
            ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $instance = Instance::query()->with('project')->findOrFail($id);
        if ($this->ownerId($operation) !== $id || $operation->node_id !== $instance->node_id) {
            throw $this->forbidden();
        }
        $admitted = ValidatedData::object($operation->input['deployment']);
        if ($admitted['variant'] === 'development') {
            throw $this->forbidden();
        }
        if ($admitted['project_id'] !== $instance->project_id
            || $admitted['repository'] !== $instance->project->repository_url
            || $admitted['node_user'] !== $instance->node->user) {
            throw $this->forbidden();
        }
        if ($admitted['user'] !== $instance->production_user || $admitted['home'] !== $instance->production_home) {
            throw $this->forbidden();
        }
        if ($parameter === 'vp_materialize' && DirectOperation::owns($operation)) {
            return new OperationSecretValue(InstanceDeploymentIntent::admitted($operation)['needs_vp']
                ? (app(ToolManagerIntent::class)->input($instance->node, ToolManagerName::Vp)['materialize'] ?? []) : []);
        }
        if ($parameter === 'environment_material') {
            return new OperationSecretValue(app(InstanceDeploymentEnvironmentMaterial::class)->read($operation));
        }
        if ($parameter === 'definitions_material') {
            return new OperationSecretValue(app(InstanceRuntimeDefinitions::class)->material($operation));
        }
        if ($parameter === 'git_read') {
            $read = $this->repositories->for($instance->project->repository_url, $instance->project->source_access);

            return new OperationSecretValue(DirectOperation::owns($operation) ? $read->githubToken() : $read);
        }
        if ($parameter === 'secrets') {
            return new OperationSecretValue($this->redactor->valuesFor($instance));
        }
        if ($parameter === 'script') {
            $steps = InstanceDeploymentIntent::admitted($operation)['steps'];
            foreach ($steps as $step) {
                if ($key !== $step['phase'].'.'.$step['name']) {
                    continue;
                }
                $row = $instance->deploySteps()->where('name', $step['name'])->first();
                if ($row === null) {
                    throw $this->forbidden();
                }

                return OperationSecretValue::script($row->command, $step['command_digest']);
            }
            throw $this->forbidden();
        }

        throw $this->forbidden();
    }

    private function forbidden(): ResourceOperationException
    {
        return new ResourceOperationException('operation.execution_forbidden', 'The protected value does not belong to this admitted deployment.', 422);
    }
}
