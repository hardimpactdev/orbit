<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use App\Domain\Instances\Deployment\InstanceDeployOperation;
use Illuminate\Contracts\Container\Container;
use LogicException;

/** The closed registry of operation kinds. A kind's name is `<family>.<verb>`. */
final readonly class OperationKinds
{
    public const string InstanceDeploy = 'instance.deploy';

    /** @var array<string, class-string<OperationKind>> */
    private const array Kinds = [
        self::InstanceDeploy => InstanceDeployOperation::class,
    ];

    public function __construct(private Container $container) {}

    public function for(string $kind): OperationKind
    {
        $class = self::Kinds[$kind] ?? throw new LogicException("Unknown operation kind [{$kind}].");

        return $this->container->make($class);
    }

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::Kinds);
    }
}
