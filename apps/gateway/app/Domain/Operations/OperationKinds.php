<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Instances\Deployment\InstanceDeployOperation;
use App\Domain\Instances\Deployment\InstanceRollbackOperation;
use Illuminate\Contracts\Container\Container;
use LogicException;

/** The closed registry of operation kinds. A kind's name is `<family>.<verb>`; its target type is one word. */
final readonly class OperationKinds
{
    /** The form of every kind name, `<family>.<verb>` in lower case, such as `instance.deploy`. */
    public const string NamePattern = '/\A[a-z][a-z0-9_]*\.[a-z][a-z0-9_]*\z/D';

    /** The form of every target type, one lower-case word, such as `instance`. */
    public const string TargetTypePattern = '/\A[a-z][a-z0-9_]*\z/D';

    public const string InstanceDeploy = 'instance.deploy';

    public const string InstanceRollback = 'instance.rollback';

    /** The target type of every kind that changes an Instance, so they hold the same target. */
    public const string InstanceTarget = 'instance';

    /** All operations on a task group hold the same target. */
    public const string TaskGroupTarget = 'task_group';

    /** @var array<string, class-string<OperationKind>> */
    private const array Kinds = [
        self::InstanceDeploy => InstanceDeployOperation::class,
        self::InstanceRollback => InstanceRollbackOperation::class,
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
