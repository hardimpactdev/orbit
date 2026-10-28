<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Instances\Deployment\AutomaticDeployEventOperation;
use App\Domain\Instances\Deployment\InstanceDeployOperation;
use App\Domain\Instances\Deployment\InstanceRollbackOperation;
use App\Domain\Instances\Smoke\InstanceSmokeOperation;
use App\Domain\Tasks\TaskAnalysisOperation;
use App\Domain\Tasks\TaskCheckOperation;
use App\Domain\Tasks\TaskParkOperation;
use App\Domain\Tasks\TaskProvisionOperation;
use App\Domain\Tasks\TaskPublishOperation;
use App\Domain\Tasks\TaskScriptOperation;
use App\Domain\Tasks\TaskTurnOperation;
use App\Domain\Tasks\TaskWorkerOperation;
use Illuminate\Contracts\Container\Container;
use LogicException;

/** The closed registry of operation kinds. A kind's name is `<family>.<verb>`; its target type is one word. */
final readonly class OperationKinds
{
    /** The form of every kind name, `<family>.<verb>` in lower case, such as `instance.deploy`. */
    public const string NamePattern = '/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D';

    /** The form of every target type, one lower-case word, such as `instance`. */
    public const string TargetTypePattern = '/\A[a-z][a-z0-9_]*\z/D';

    public const string InstanceSmoke = 'instance.smoke';

    public const string InstanceDeploy = 'instance.deploy';

    public const string InstancePolicyEvent = 'instance.policy_event';

    public const string InstanceRollback = 'instance.rollback';

    public const string TasksPublish = 'tasks.publish';

    public const string TasksAnalyze = 'tasks.analyze';

    public const string TasksScript = 'tasks.script';

    public const string TasksCheck = 'tasks.check';

    public const string TasksProvision = 'tasks.provision';

    public const string TasksTurn = 'tasks.turn';

    public const string TasksWorker = 'tasks.worker';

    public const string TasksPark = 'tasks.park';

    /** The target type of every kind that changes an Instance, so they hold the same target. */
    public const string InstanceTarget = 'instance';

    /** All operations on a task group hold the same target. */
    public const string TaskGroupTarget = 'task_group';

    /** @var array<string, class-string<OperationKind>> */
    private const array Kinds = [
        self::InstanceSmoke => InstanceSmokeOperation::class,
        self::InstanceDeploy => InstanceDeployOperation::class,
        self::InstancePolicyEvent => AutomaticDeployEventOperation::class,
        self::InstanceRollback => InstanceRollbackOperation::class,
        self::TasksPublish => TaskPublishOperation::class,
        self::TasksScript => TaskScriptOperation::class,
        self::TasksCheck => TaskCheckOperation::class,
        self::TasksAnalyze => TaskAnalysisOperation::class,
        self::TasksProvision => TaskProvisionOperation::class,
        self::TasksTurn => TaskTurnOperation::class,
        self::TasksWorker => TaskWorkerOperation::class,
        self::TasksPark => TaskParkOperation::class,
    ];

    /** @param array<string, class-string<OperationKind>> $kinds */
    public function __construct(private Container $container, private array $kinds = self::Kinds) {}

    public function for(string $kind): OperationKind
    {
        $class = $this->kinds[$kind] ?? throw new LogicException("Unknown operation kind [{$kind}].");

        return $this->container->make($class);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::Kinds);
    }
}
