<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Operations\OperationSecretSource;
use App\Domain\Operations\OperationSecretValue;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Kit v2 Gateway-only sources. Secret bytes never enter the Operation or Swamp. */
final readonly class ProcessOperationSecrets implements OperationSecretSource
{
    public function __construct(private ProcessTargetResolver $targets, private SystemdProcessRenderer $systemd, private DockerProcessRenderer $docker, private ManagedUserAccountResolver $accounts, private string $source) {}

    public function name(): string
    {
        return $this->source;
    }

    public function targetTypes(): array
    {
        return ['process'];
    }

    public function ownerId(Operation $operation): ?int
    {
        return $operation->target_type === 'process' && $operation->target_id === ($operation->input['process_id'] ?? null)
            ? $operation->target_id : null;
    }

    public function resolve(Operation $operation, int $id, string $key, string $parameter): OperationSecretValue
    {
        $process = Process::query()->findOrFail($id);
        $target = $this->targets->forInstallation($process);
        if ($process->id !== $operation->target_id || $operation->target_type !== 'process' || $process->id !== ($operation->input['process_id'] ?? null) || $target->node->id !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The secret reference does not belong to this Process.', 422);
        }

        if ($this->source === 'process.unit_content') {
            $value = $this->systemd->render($process, $target, $target->certificateScope === null ? null : $this->accounts->resolve($target->node));
            if (! hash_equals(ValidatedData::string($operation->input['content_hash']), hash('sha256', $value))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Process unit changed after admission.', 422);
            }

            return new OperationSecretValue($value, (new OperationSecretValue($process->runtime_config))->strings());
        }
        if (! hash_equals(ValidatedData::string($operation->input['spec']), $this->docker->specHash($process, $target))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process definition changed after admission.', 422);
        }

        $value = match ($this->source) {
            'process.unit_content' => $this->systemd->render($process, $target, $target->certificateScope === null ? null : $this->accounts->resolve($target->node)),
            'process.environment' => $this->docker->containerInput($process, $target)['environment'],
            'process.command' => $this->docker->containerInput($process, $target)['command'],
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown Process secret source.', 422),
        };

        return new OperationSecretValue($value, [json_encode($value, JSON_THROW_ON_ERROR)]);
    }
}
