<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Processes\ProcessRuntime;
use App\Domain\Processes\ProcessTargetResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Existing Process definitions, confined to the exact admitted Node and hashes. */
final readonly class DockerMigrationProcesses implements OperationSecretSource
{
    public function __construct(private ProcessTargetResolver $targets, private DockerProcessRenderer $docker) {}

    public function name(): string
    {
        return 'fleet.docker_processes';
    }

    public function targetTypes(): array
    {
        return ['node'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->kind === 'fleet.docker_migrate' && $operation->target_type === 'node' && $operation->target_id === $operation->node_id ? $operation->node_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        if ($this->ownerId($operation) !== $id || $parameter !== 'processes') {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Docker definitions do not belong to this Node.', 422);
        }
        $definitions = [];
        $migration = ValidatedData::object($operation->input['migration']);
        $specs = (array) $migration['process_specs'];
        $states = (array) $migration['process_states'];
        foreach (ValidatedData::stringList($migration['process_ids']) as $processId) {
            $process = Process::query()->findOrFail((int) $processId);
            $target = $this->targets->forInstallation($process);
            $spec = (array) $specs[$processId];
            if ($target->node->id !== $id || $process->runtime !== ProcessRuntime::Docker
                || $process->desired_state->value !== $states[$processId]
                || ! hash_equals(ValidatedData::string($spec['hash']), $this->docker->specHash($process, $target))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Docker definition changed after admission.', 422);
            }
            $definitions[] = ['process_id' => $process->id, 'container' => $this->docker->containerName($process),
                'desired' => $states[$processId], 'spec' => $spec['hash'], ...$this->docker->containerInput($process, $target)];
        }

        return new OperationSecretValue($definitions);
    }
}
