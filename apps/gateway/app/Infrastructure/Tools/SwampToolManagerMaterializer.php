<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Actions\Tools\ConvergeToolManagerAction;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Operations\AwaitOperation;
use App\Domain\Operations\OperationStatus;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerRegistry;
use App\Infrastructure\Nodes\EnrollmentProgram;
use App\Models\Node;
use Closure;

/** Gateway callers submit manager intent and use the shared bounded waiter. */
final readonly class SwampToolManagerMaterializer implements ToolManagerMaterializer
{
    public function __construct(private ConvergeToolManagerAction $operations, private AwaitOperation $await, private ToolManagerRegistry $managers) {}

    public function converge(Node $node, ToolManagerName ...$managerNames): void
    {
        $this->convergeInternal($node, null, ...$managerNames);
    }

    public function convergeWithFailureHandler(Node $node, Closure $onFailure, ToolManagerName ...$managerNames): void
    {
        $this->convergeInternal($node, $onFailure, ...$managerNames);
    }

    /** @param Closure(NodeProvisioningException): void|null $onFailure */
    private function convergeInternal(Node $node, ?Closure $onFailure, ToolManagerName ...$managerNames): void
    {
        $names = $managerNames === [] ? array_map(static fn ($manager): ToolManagerName => $manager->name(), $this->managers->supportedFor($node)) : $managerNames;
        try {
            foreach (array_unique($names, SORT_REGULAR) as $name) {
                $program = match ($name) {
                    ToolManagerName::Vp => EnrollmentProgram::VpManager,
                    ToolManagerName::Composer => EnrollmentProgram::ComposerManager,
                    default => null,
                };
                $skip = $onFailure !== null && $program !== null && EnrollmentProgram::skipForTaskVm($node, $program);
                $operation = $this->await->execute($this->operations->execute($node, $name, $skip));
                if ($operation->status !== OperationStatus::Succeeded) {
                    throw new NodeProvisioningException('tool-manager-'.$name->value,
                        $operation->error_code ?? 'node.tool_manager_materialization_failed', 'The Tool manager operation failed.');
                }
            }
        } catch (NodeProvisioningException $error) {
            if ($onFailure !== null) {
                $onFailure($error);
            }
            throw $error;
        }
    }
}
