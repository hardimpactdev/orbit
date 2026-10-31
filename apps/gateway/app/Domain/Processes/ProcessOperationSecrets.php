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

/** Owner-bound private sources. Bytes never enter public intent or native arguments. */
final readonly class ProcessOperationSecrets implements OperationSecretSource
{
    public function __construct(private ProcessTargetResolver $targets, private SystemdProcessRenderer $systemd, private DockerProcessRenderer $docker, private ManagedUserAccountResolver $accounts) {}

    public function name(): string
    {
        return 'process.installation';
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

        if (! in_array($operation->kind, ['process.create', 'process.update'], true)
            || ($operation->input['runtime'] ?? null) !== $process->runtime->value) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The installation runtime does not match its intent.', 422);
        }

        if ($process->runtime === ProcessRuntime::Systemd) {
            $value = $this->systemd->render($process, $target, $target->certificateScope === null ? null : $this->accounts->resolve($target->node));
            if (! hash_equals(ValidatedData::string($operation->input['content_hash']), hash('sha256', $value))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Process unit changed after admission.', 422);
            }

            return new OperationSecretValue(['runtime' => 'systemd', 'content' => $value], new OperationSecretValue($process->runtime_config)->strings());
        }
        if (! hash_equals(ValidatedData::string($operation->input['spec']), $this->docker->specHash($process, $target))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process definition changed after admission.', 422);
        }

        $value = ['runtime' => 'docker', 'spec' => $operation->input['spec'], 'definition' => $this->docker->containerInput($process, $target)];

        return new OperationSecretValue($value);
    }
}
