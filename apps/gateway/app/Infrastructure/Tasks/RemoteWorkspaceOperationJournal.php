<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\Tasks\TaskExecutionLock;
use App\Domain\Tasks\TaskWorkspaceLease;
use App\Domain\Tasks\TaskWorkspaceOperationRecovery;
use App\Domain\Tasks\TaskWorkspaceWaiting;
use App\Domain\TaskVms\TaskVmWorkspaceGuard;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Models\Instance;
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
            if (! $fresh instanceof TaskWorkspaceLeaseRecord || ($fresh->getAttribute('completion_pending') || $fresh->getAttribute('recovery_sealed'))) {
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
                    if (($data['outcome_unknown'] ?? false) === true) {
                        TaskWorkspaceLeaseRecord::query()->whereKey($lease->id)->update(['causality_uncertain' => true]);
                    }
                } catch (Throwable) {
                    return false;
                }
            }

            return app(TaskExecutionLock::class)->synchronized(-$lease->instance_id, static function () use ($lease): bool {
                if (TaskWorkspaceOperation::query()->where('lease_id', $lease->id)->where('status', 'pending')->exists()) {
                    return false;
                }

                return TaskWorkspaceLeaseRecord::query()->whereKey($lease->id)->where('status', 'held')->where('active_instance_id', $lease->instance_id)->where('recovery_sealed', false)
                    ->update(['remote_uncertain' => false]) === 1;
            });
        } finally {
            $this->probing = false;
        }
    }

    /** Seal every known endpoint before proving that managed descendants have stopped.
     * @return array{binding: string, endpoints: int, check_runs: int}
     */
    public function seal(TaskWorkspaceLeaseRecord $lease, ?Instance $instance): array
    {
        $binding = hash('sha256', $lease->id.':'.$lease->instance_id.':'.$lease->acquisition_sequence);
        $operations = TaskWorkspaceOperation::query()->where('lease_id', $lease->id)->get();
        $runId = $lease->getAttribute('check_run_id');
        if ($runId !== null && (! is_string($runId) || preg_match('/\A[a-f0-9]{32}\z/D', $runId) !== 1)) {
            throw new TaskWorkspaceWaiting('The recorded check run identity is unverifiable.');
        }
        $checkRuns = $runId === null ? [] : [$runId];
        $endpoints = [];
        foreach ($operations as $operation) {
            if ($operation->binding !== $binding) {
                throw new TaskWorkspaceWaiting('The recorded operation binding differs from this lease.');
            }
            $key = hash('sha256', json_encode($operation->connection, JSON_THROW_ON_ERROR));
            $endpoints[$key]['connection'] = $operation->connection;
            $endpoints[$key]['operations'][] = $operation->operation;
        }
        $verify = function (CommandResult $result, int $count) use ($binding, $checkRuns): void {
            $data = $result->succeeded() ? json_decode($result->stdout, true, 8, JSON_THROW_ON_ERROR) : null;
            if (! is_array($data) || ($data['binding'] ?? null) !== $binding || ($data['state'] ?? null) !== 'sealed'
                || ($data['operations'] ?? null) !== $count || ($data['check_runs'] ?? null) !== count($checkRuns)) {
                throw new TaskWorkspaceWaiting('Remote quiescence is not proven; the workspace remains sealed and held.');
            }
        };
        foreach ($endpoints as $endpoint) {
            $identities = $endpoint['operations'];
            $command = new RemoteCommand(['python3', '-c', $this->program(), 'seal', str_repeat('0', 32), $binding, '0', json_encode($identities, JSON_THROW_ON_ERROR), json_encode($checkRuns, JSON_THROW_ON_ERROR)], timeout: 30.0);
            $verify(app(SshExecutor::class)->execute(self::connection($endpoint['connection']), $command), count($identities));
        }
        // An unstarted lease has no saved connection, but its current workspace is still a required endpoint.
        if ($instance instanceof Instance) {
            TaskVmWorkspaceGuard::assertOwnedWorkspace($instance);
            $command = new RemoteCommand(['python3', '-c', $this->program(), 'seal', str_repeat('0', 32), $binding, '0', '[]', json_encode($checkRuns, JSON_THROW_ON_ERROR)], timeout: 30.0);
            $verify(app(DevelopmentSshExecutor::class)->execute($instance->node, $command,
                'task-workspace-recovery', 'tasks.workspace_recovery_unverifiable', failureLabel: 'Task workspace recovery'), 0);
        } elseif ($endpoints === []) {
            throw new TaskWorkspaceWaiting('No recorded remote endpoint can prove this missing workspace stopped.');
        }

        return ['binding' => $binding, 'endpoints' => count($endpoints) + ($instance instanceof Instance ? 1 : 0), 'check_runs' => count($checkRuns)];
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
