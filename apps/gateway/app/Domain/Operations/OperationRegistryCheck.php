<?php

declare(strict_types=1);

namespace App\Domain\Operations;

use Illuminate\Contracts\Container\Container;
use LogicException;

/**
 * Resolves registry wiring without running an Operation or reading its target.
 *
 * @phpstan-import-type SwampDefinition from OperationExecutionContract
 */
final readonly class OperationRegistryCheck
{
    public function __construct(private Container $container, private OperationTransports $transports, private DirectOperation $direct) {}

    /**
     * @param  array<string,class-string<OperationKind>|SwampDefinition>|null  $definitions
     */
    public function execute(?array $definitions = null): int
    {
        $definitions ??= OperationKinds::definitions();
        $kinds = new OperationKinds($this->container, $definitions);
        foreach ($definitions as $name => $definition) {
            if (is_string($definition)) {
                $kinds->for($name);

                continue;
            }
            if (! isset($definition['runs'])) {
                $direct = $this->direct->definition($name);
                if ($direct === null || ! $this->container->make($direct['projection']) instanceof DirectOperationProjection) {
                    throw new LogicException("Direct-only Operation kind [{$name}] needs a direct workflow and projection.");
                }

                continue;
            }
            if (! $this->container->make($definition['completion']) instanceof OperationCompletion) {
                throw new LogicException("Operation kind [{$name}] needs an OperationCompletion handler.");
            }
            if (isset($definition['execution']) && ! $this->container->make($definition['execution']) instanceof OperationFamilyExecution) {
                throw new LogicException("Operation kind [{$name}] needs an OperationFamilyExecution handler.");
            }
            foreach ($definition['runs'] as $run) {
                $this->transports->get($run['transport']);
                if ($run['transport'] === 'gateway') {
                    $this->container->make(OperationGatewayCommands::class)->get($run['name']);
                }
            }
        }

        return count($definitions);
    }
}
