<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Executor\ExecutorProofOperation;

/**
 * The closed registry of operation kinds. A kind's name is `<family>.<verb>`; its target type is one word.
 */
final readonly class OperationKinds
{
    /**
     * Kinds the Gateway runs or closes itself, with no Swamp run: the WebSocket registry tasks, the proof run, which closes when its
     * runs (`proof.command`) have ended, and the executor proof, which closes when its no-op result reaches its waiter.
     */
    private const array GatewayJobs = [
        'websocket.sync' => ['executor' => 'gateway'],
        'websocket.app_create' => ['executor' => 'gateway'],
        'websocket.app_destroy' => ['executor' => 'gateway'],
        'websocket.app_rotate' => ['executor' => 'gateway'],
        'proof.run' => ['executor' => 'gateway'],
        'executor.proof' => ['executor' => 'gateway'],
        'executor.power_proof' => ['executor' => 'gateway'],
    ];

    /** The form of every kind name, `<family>.<verb>` in lower case, such as `instance.deploy`. */
    public const string NamePattern = '/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D';

    /** The form of every target type, one lower-case word, such as `instance`. */
    public const string TargetTypePattern = '/\A[a-z][a-z0-9_]*\z/D';

    public const string InstanceDeploy = 'instance.deploy';

    public const string InstanceRollback = 'instance.rollback';

    public const string TasksPublish = 'tasks.publish';

    public const string TasksCheck = 'tasks.check';

    public const string TasksProvision = 'tasks.provision';

    /** The turn of a task that runs in a copy of its Project's default Instance (docs/cli/task/copy-turn.mmd). */
    public const string TasksCopyTurn = 'tasks.copy_turn';

    public const string TasksSync = 'tasks.sync';

    public const string TasksPrReady = 'tasks.pr_ready';

    public const string TasksPrMerge = 'tasks.pr_merge';

    /** The merge of a pull request that no Task group watches; it targets its Project. */
    public const string ProjectPrMerge = 'project.pr_merge';

    public const string ProofRun = 'proof.run';

    public const string ProofVerify = 'proof.verify';

    /** The proof-only executor round trip on a Node (docs/reference/executor.md#the-proof-operation). Only {@see ExecutorProofOperation} creates it. */
    public const string ExecutorProof = 'executor.proof';

    /** The proof-only sleep/discard Operation on an Instance; production power flows do not call it. */
    public const string ExecutorPowerProof = 'executor.power_proof';

    /** The target type of every kind that changes an Instance, so they hold the same target. */
    public const string InstanceTarget = 'instance';

    /** All operations on a task group hold the same target. */
    public const string TaskGroupTarget = 'task_group';

    /** @return array<string,array<string,mixed>> */
    public static function definitions(): array
    {
        return app(DirectOperation::class)->definitions() + self::GatewayJobs;
    }

    /** @return array<string,mixed>|null */
    public function definition(string $kind): ?array
    {
        return self::definitions()[$kind] ?? null;
    }

    public static function supports(string $capability): bool
    {
        return $capability === 'secret_outputs';
    }

    public static function executor(string $kind): string
    {
        if (self::gatewayJob($kind)) {
            return 'gateway';
        }
        app(DirectOperation::class)->writer($kind);

        return 'swamp';
    }

    public function directOnly(string $kind): bool
    {
        return app(DirectOperation::class)->definition($kind) !== null;
    }

    /** The App-key operations and the WebSocket registry tasks stay inside the Gateway. */
    public static function gatewayJob(string $kind): bool
    {
        return isset(self::GatewayJobs[$kind]);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::definitions());
    }
}
