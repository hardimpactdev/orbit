<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceOperationRecovery;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\TaskWorkspaceLeaseRecord;
use App\Models\TaskWorkspaceOperation;
use App\Support\ValidatedData;
use Closure;
use Illuminate\Container\Attributes\Singleton;
use Throwable;

/** The task scope journals every native SSH call, including lifecycle and source-removal protocols. */
#[Singleton]
final class RemoteWorkspaceOperationJournal implements TaskWorkspaceOperationRecovery
{
    private bool $probing = false;

    /**
     * @param  Closure(RemoteCommand): CommandResult  $execute
     */
    public function execute(SshConnection $connection, RemoteCommand $command, Closure $execute): CommandResult
    {
        $lease = $this->probing ? null : app(TaskWorkspaceLease::class)->currentScope();
        if (! $lease instanceof TaskWorkspaceLeaseRecord) {
            return $execute($command);
        }
        $operation = app(TaskExecutionLock::class)->synchronized(-$lease->instance_id, function () use ($lease, $connection): TaskWorkspaceOperation {
            $fresh = TaskWorkspaceLeaseRecord::query()->whereKey($lease->id)->where('status', 'held')->where('active_instance_id', $lease->instance_id)->first();
            if (! $fresh instanceof TaskWorkspaceLeaseRecord || $fresh->getAttribute('completion_pending')) {
                throw new TaskWorkspaceWaiting('The task operation no longer owns this workspace.');
            }
            $operation = TaskWorkspaceOperation::query()->create([
                'lease_id' => $lease->id, 'operation' => bin2hex(random_bytes(16)),
                'binding' => hash('sha256', $lease->id.':'.$lease->instance_id.':'.$lease->acquisition_sequence),
                'connection' => self::connectionData($connection),
            ]);
            $fresh->update(['remote_uncertain' => true]);

            return $operation;
        });
        $wrapped = new RemoteCommand(
            arguments: ['python3', '-c', $this->program(), 'run', $operation->operation, $operation->binding, '0', ...$command->arguments],
            input: $command->input, protectedInput: $command->protectedInput, maxOutputBytes: $command->maxOutputBytes,
            output: $command->output, cancelled: $command->cancelled, timeout: $command->timeout,
            terminateGraceSeconds: $command->terminateGraceSeconds,
        );
        $result = $execute($wrapped);
        // Raw SSH exit codes cannot prove wrapper/descendant termination, including exit75.
        // Probe the exact durable receipt even after a successful transport response.
        $this->settled($lease);

        return $result;
    }

    public function settled(TaskWorkspaceLeaseRecord $lease): bool
    {
        if (! $lease->getAttribute('journaled')) {
            return ! $lease->getAttribute('remote_uncertain');
        }
        if ($lease->getAttribute('remote_uncertain') && ! TaskWorkspaceOperation::query()->where('lease_id', $lease->id)->exists()) {
            return false;
        }
        $this->probing = true;
        try {
            foreach (TaskWorkspaceOperation::query()->where('lease_id', $lease->id)->where('status', 'pending')->get() as $operation) {
                try {
                    $result = app(SshExecutor::class)->execute(self::connection($operation->connection), new RemoteCommand(
                        ['python3', '-c', $this->program(), 'probe', $operation->operation, $operation->binding, '0'],
                        maxOutputBytes: 4096, timeout: 15.0,
                    ));
                    $data = $result->succeeded() ? json_decode(trim($result->stdout), true, 8, JSON_THROW_ON_ERROR) : null;
                    if (! is_array($data) || ($data['operation'] ?? null) !== $operation->operation || ($data['binding'] ?? null) !== $operation->binding
                        || ! in_array($data['state'] ?? null, ['finished', 'not_started'], true)) {
                        return false;
                    }
                    $operation->update(['status' => $data['state']]);
                } catch (Throwable) {
                    return false;
                }
            }

            return app(TaskExecutionLock::class)->synchronized(-$lease->instance_id, static function () use ($lease): bool {
                if (TaskWorkspaceOperation::query()->where('lease_id', $lease->id)->where('status', 'pending')->exists()) {
                    return false;
                }

                return TaskWorkspaceLeaseRecord::query()->whereKey($lease->id)->where('status', 'held')->where('active_instance_id', $lease->instance_id)
                    ->update(['remote_uncertain' => false]) === 1;
            });
        } finally {
            $this->probing = false;
        }
    }

    private function program(): string
    {
        $program = file_get_contents(resource_path('tasks/workspace-operation.py'));
        if (! is_string($program) || $program === '') {
            throw new \RuntimeException('The task workspace receipt protocol is unavailable.');
        }

        return $program;
    }

    /** @return array<string, mixed> */
    private static function connectionData(SshConnection $connection): array
    {
        return [...get_object_vars($connection), 'proxyJump' => $connection->proxyJump === null ? null : self::connectionData($connection->proxyJump)];
    }

    /** @param array<string, mixed> $data */
    private static function connection(array $data): SshConnection
    {
        // Connection fields originate exclusively from SshConnection, never from remote output.
        return new SshConnection(
            host: ValidatedData::string($data['host']), user: ValidatedData::string($data['user']), port: ValidatedData::integer($data['port']),
            identityFile: ValidatedData::string($data['identityFile']), knownHostsFile: ValidatedData::string($data['knownHostsFile']),
            connectTimeout: ValidatedData::integer($data['connectTimeout']), commandTimeout: is_float($data['commandTimeout']) ? $data['commandTimeout'] : ValidatedData::integer($data['commandTimeout']),
            shareConnection: (bool) $data['shareConnection'],
            proxyJump: is_array($data['proxyJump']) ? self::connection(ValidatedData::object($data['proxyJump'])) : null,
            hostIdentity: is_string($data['hostIdentity']) ? $data['hostIdentity'] : null,
        );
    }
}
