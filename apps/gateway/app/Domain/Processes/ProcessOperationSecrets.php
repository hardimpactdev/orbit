<?php

declare(strict_types=1);

namespace App\Domain\Processes;

use App\Domain\Nodes\ManagedUserAccount;
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
    public function __construct(private ProcessTargetResolver $targets, private SystemdProcessRenderer $systemd, private DockerProcessRenderer $docker) {}

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

        if (! in_array($operation->kind, ['process.create', 'process.update'], true)) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The installation kind is not supported.', 422);
        }

        return $this->materialFor($process, $target, [...$operation->input, 'node_id' => $operation->node_id]);
    }

    /** Reuse the existing installation secret type at an admitted placement without resolving accounts.
     * @param  array<string,mixed>  $input
     */
    public function materialFor(Process $process, ProcessTarget $target, array $input, ?ManagedUserAccount $account = null): OperationSecretValue
    {
        if (($input['process_id'] ?? null) !== $process->id || ($input['node_id'] ?? null) !== $target->node->id
            || ($input['runtime'] ?? null) !== $process->runtime->value) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The installation does not match its admitted Process and Node.', 422);
        }

        if ($process->runtime === ProcessRuntime::Systemd) {
            if (isset($input['account_user'])) {
                if ($input['account_user'] !== $target->user || $account !== null || $target->certificateScope === null || $process->isVpDev()) {
                    throw new ResourceOperationException('operation.execution_forbidden', 'The Process account template does not match its target.', 422);
                }
                $account = new ManagedUserAccount($target->user, '__orbit_process_account_group__', ProcessOperationAdmission::AccountHomeToken);
            }
            $value = $this->systemd->render($process, $target, $account);
            if (! hash_equals(ValidatedData::string($input['content_hash']), hash('sha256', $value))) {
                throw new ResourceOperationException('operation.execution_forbidden', 'The Process unit changed after admission.', 422);
            }

            return new OperationSecretValue(['runtime' => 'systemd', 'content' => $value, 'environment' => $this->systemd->environmentContent($process, $target)], new OperationSecretValue($process->runtime_config)->strings());
        }
        if (! hash_equals(ValidatedData::string($input['spec']), $this->docker->specHash($process, $target))) {
            throw new ResourceOperationException('operation.execution_forbidden', 'The Process definition changed after admission.', 422);
        }

        $value = ['runtime' => 'docker', 'spec' => $input['spec'], 'definition' => $this->docker->containerInput($process, $target)];

        return new OperationSecretValue($value);
    }
}
