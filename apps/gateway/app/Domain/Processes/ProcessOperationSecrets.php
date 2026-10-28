<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\DockerProcessRenderer;
use App\Infrastructure\Processes\SystemdProcessRenderer;
use App\Models\Operation;
use App\Models\Process;
use App\Support\ValidatedData;

/** Kit v2 Gateway-only sources. Secret bytes never enter the Operation or Swamp. */
final readonly class ProcessOperationSecrets
{
    public function __construct(private ProcessTargetResolver $targets, private SystemdProcessRenderer $systemd, private DockerProcessRenderer $docker, private ManagedUserAccountResolver $accounts) {}

    public function resolve(string $source, int|string $id, Operation $operation): mixed
    {
        $process = Process::query()->findOrFail($id);
        $target = $this->targets->forInstallation($process);
        if ($process->id !== $operation->target_id || $operation->target_type !== 'process' || $process->id !== ($operation->input['process_id'] ?? null) || $target->node->id !== $operation->node_id) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The secret reference does not belong to this Process.', 422);
        }

        if ($source === 'process.unit_content') {
            $value = $this->systemd->render($process, $target, $target->certificateScope === null ? null : $this->accounts->resolve($target->node));
            if (! hash_equals(ValidatedData::string($operation->input['content_hash']), hash('sha256', $value))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Process unit changed after admission.', 422);
            }

            return $value;
        }
        if (! hash_equals(ValidatedData::string($operation->input['spec']), $this->docker->specHash($process, $target))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process definition changed after admission.', 422);
        }

        return match ($source) {
            'process.unit_content' => $this->systemd->render($process, $target, $target->certificateScope === null ? null : $this->accounts->resolve($target->node)),
            'process.environment' => $this->docker->containerInput($process, $target)['environment'],
            'process.command' => $this->docker->containerInput($process, $target)['command'],
            default => throw new ResourceOperationException('operation.execution_forbidden', 'Unknown Process secret source.', 422),
        };
    }
}
