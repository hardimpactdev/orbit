<?php

declare(strict_types=1);

namespace App\Domain\Operations;

/**
 * The closed registry of operation kinds. A kind's name is `<family>.<verb>`; its target type is one word.
 */
final readonly class OperationKinds
{
    /** Kinds the Gateway runs or closes itself, with no Swamp run: the WebSocket registry tasks and the proof run, which closes when its runs (`proof.command`) have ended. */
    private const array GatewayJobs = [
        'websocket.sync' => ['executor' => 'gateway'],
        'websocket.app_create' => ['executor' => 'gateway'],
        'websocket.app_destroy' => ['executor' => 'gateway'],
        'websocket.app_rotate' => ['executor' => 'gateway'],
        'proof.run' => ['executor' => 'gateway'],
    ];

    /** The form of every kind name, `<family>.<verb>` in lower case, such as `instance.deploy`. */
    public const string NamePattern = '/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D';

    /** The form of every target type, one lower-case word, such as `instance`. */
    public const string TargetTypePattern = '/\A[a-z][a-z0-9_]*\z/D';

    public const string InstanceDeploy = 'instance.deploy';

    public const string InstanceRollback = 'instance.rollback';

    public const string TasksPublish = 'tasks.publish';

    public const string TasksScript = 'tasks.script';

    public const string TasksPackage = 'tasks.package';

    public const string TasksCheck = 'tasks.check';

    public const string TasksProvision = 'tasks.provision';

    public const string TasksTurn = 'tasks.turn';

    public const string TasksPark = 'tasks.park';

    public const string TasksPrep = 'tasks.prep';

    public const string TasksPrepFix = 'tasks.prep_fix';

    public const string TasksSync = 'tasks.sync';

    public const string TasksBuild = 'tasks.build';

    public const string TasksPrReady = 'tasks.pr_ready';

    public const string TasksPrMerge = 'tasks.pr_merge';

    /** The merge of a pull request that no Task group watches; it targets its Project. */
    public const string ProjectPrMerge = 'project.pr_merge';

    public const string TasksPrComment = 'tasks.pr_comment';

    public const string ProofRun = 'proof.run';

    public const string ProofVerify = 'proof.verify';

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
