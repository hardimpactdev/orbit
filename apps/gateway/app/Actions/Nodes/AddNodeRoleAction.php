<?php

declare(strict_types=1);

namespace App\Actions\Nodes;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\NodeRoleFollowUpReport;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\RoleAssignmentException;
use App\Domain\Nodes\RoleBaselineConverger;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\RoleRegistry;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\Tools\ToolManagerMaterializer;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolManagerScopeLock;
use App\Domain\Tools\ToolManagerScopeLockException;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use App\Models\NodeRole;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class AddNodeRoleAction
{
    public function __construct(
        private AssignRoleAction $assignRole,
        private RoleRegistry $registry,
        private RoleBaselineConverger $baselines,
        private ToolManagerMaterializer $toolManagers,
        private ToolManagerScopeLock $managerScope,
        private ?NodeRoleConvergeLock $nodeLock = null,
        private ?NodeRoleFollowUpReport $followUps = null,
    ) {}

    public function executeDuringProvisioning(Node $node, RoleName $role): NodeRole
    {
        $this->guardProvisioningNode($node);
        $this->guardSupportedPlatform($node);
        if (! $this->registry->definition($role)->assignableDuringProvisioning) {
            throw new RoleAssignmentException("Role [{$role->value}] cannot be assigned during provisioning.");
        }

        return $this->withAppManagerScope(
            $node,
            $role,
            fn (): NodeRole => $this->nodeLock()->run(
                $node,
                fn (): NodeRole => $this->convergeClaim($node, $role, $this->claimExisting($node, $role))['assignment'],
                step: 'converge:node-lock',
            ),
        );
    }

    /** @param list<RoleName> $prospectiveRoles */
    public function preflightDuringProvisioning(Node $node, RoleName $role, array $prospectiveRoles): void
    {
        if (! $this->registry->definition($role)->assignableDuringProvisioning) {
            throw new RoleAssignmentException("Role [{$role->value}] cannot be assigned during provisioning.");
        }

        $this->assignRole->preflight($node, $role, $prospectiveRoles);
    }

    /** @return array{assignment: NodeRole, created: bool} */
    private function claimExisting(Node $node, RoleName $role): array
    {

        $claim = DB::transaction(function () use ($node, $role): array {
            $assignment = $this->assignRole->execute($node, $role);

            if ($assignment->wasRecentlyCreated) {
                return ['assignment' => $assignment, 'created' => true];
            }

            $assignment->refresh();

            if (! $assignment->canClaimConvergence()) {
                throw new RoleAssignmentException(
                    "Role [{$role->value}] cannot converge from status [{$assignment->status->value}].",
                );
            }

            $assignment->claimConvergence();

            return ['assignment' => $assignment, 'created' => false];
        });

        return $claim;
    }

    /**
     * @param  array{assignment: NodeRole, created: bool}  $claim
     * @return array{assignment: NodeRole, created: bool, follow_up: ?string}
     */
    private function convergeClaim(Node $node, RoleName $role, array $claim): array
    {
        // A follow-up recorded earlier in this request belongs to another convergence.
        $this->followUps()->take();

        try {
            $this->nodeLock()->run($node, function () use ($node, $role, $claim): void {
                $this->baselines->converge($node, $claim['assignment']);
                $this->materializeAppManagers($node, $role, $claim['assignment']);
            });
        } catch (NodeProvisioningException $exception) {
            $this->failConvergence($claim['assignment'], new NodeRoleOperationException(
                step: $exception->step,
                errorCode: 'node_role.convergence_failed',
                underlyingErrorCode: $exception->errorCode,
                message: $exception->getMessage(),
                result: $exception->result,
                previous: $exception,
            ));
        } catch (NodeRoleOperationException $exception) {
            $step = $this->unnamespacedStep($exception->step);

            $this->failConvergence($claim['assignment'], new NodeRoleOperationException(
                step: $step,
                errorCode: 'node_role.convergence_failed',
                underlyingErrorCode: $exception->underlyingErrorCode,
                message: $exception->getMessage(),
                result: $exception->result,
                previous: $exception,
            ));
        } catch (RuntimeConvergenceException|FirewallOperationException $exception) {
            $this->failConvergence($claim['assignment'], new NodeRoleOperationException(
                step: $this->unnamespacedStep($exception->step),
                errorCode: 'node_role.convergence_failed',
                underlyingErrorCode: $exception->errorCode,
                message: $exception->getMessage(),
                result: $exception->result,
                previous: $exception,
            ));
        } catch (Throwable $exception) {
            $this->failConvergence($claim['assignment'], new NodeRoleOperationException(
                step: 'baseline',
                errorCode: 'node_role.convergence_failed',
                underlyingErrorCode: 'node_role.convergence_unknown',
                message: "Role [{$role->value}] convergence failed on node [{$node->name}].",
                previous: $exception,
            ));
        }

        DB::transaction(static fn () => $claim['assignment']->markConvergenceActive());

        return [
            'assignment' => $claim['assignment']->refresh(),
            'created' => $claim['created'],
            'follow_up' => $this->followUps()->take(),
        ];
    }

    private function followUps(): NodeRoleFollowUpReport
    {
        return $this->followUps ?? app(NodeRoleFollowUpReport::class);
    }

    private function guardSupportedPlatform(Node $node): void
    {
        if ($node->platform === 'linux') {
            return;
        }

        throw new ResourceOperationException(
            errorCode: 'node.platform_unsupported',
            message: "Node platform [{$node->platform}] does not support roles.",
            status: 422,
        );
    }

    private function guardProvisioningNode(Node $node): void
    {
        if (
            ! $node->exists
            || ! in_array(
                $node->status,
                [LifecycleStatus::Provisioning, LifecycleStatus::Active],
                strict: true,
            )
        ) {
            throw new RoleAssignmentException('Roles can be changed only on a provisioning or active node.');
        }
    }

    private function materializeAppManagers(Node $node, RoleName $role, NodeRole $assignment): void
    {
        if ($role !== RoleName::AppDev && $role !== RoleName::AppProd) {
            return;
        }

        $this->toolManagers->convergeWithFailureHandler(
            $node,
            static function (NodeProvisioningException $failure) use ($assignment): void {
                $assignment->markConvergenceFailed($failure->step, $failure->errorCode);
            },
            ToolManagerName::Vp,
            ToolManagerName::Composer,
        );
    }

    /**
     * The per-Node role lock. The action takes it before it claims the assignment, so a busy Node
     * returns an error and leaves the assignment as it was.
     */
    private function nodeLock(): NodeRoleConvergeLock
    {
        return $this->nodeLock ?? app(NodeRoleConvergeLock::class);
    }

    private function unnamespacedStep(string $step): string
    {
        return str_starts_with($step, 'converge:') ? substr($step, offset: 9) : $step;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function withAppManagerScope(Node $node, RoleName $role, Closure $callback): mixed
    {
        if ($role !== RoleName::AppDev && $role !== RoleName::AppProd) {
            return $callback();
        }

        try {
            return $this->managerScope->run($node->id, ToolManagerName::Vp, fn () => $this->managerScope->run(
                $node->id,
                ToolManagerName::Composer,
                $callback,
            ));
        } catch (ToolManagerScopeLockException $exception) {
            throw new NodeRoleOperationException(
                step: 'converge:tool-manager-lock',
                errorCode: 'node_role.convergence_failed',
                underlyingErrorCode: 'node_role.tool_manager_locked',
                message: "Tool manager state is busy on node [{$node->name}].",
                previous: $exception,
            );
        }
    }

    private function failConvergence(NodeRole $assignment, NodeRoleOperationException $exception): never
    {
        DB::transaction(static fn () => $assignment->markConvergenceFailed(
            $exception->step,
            $exception->underlyingErrorCode,
        ));

        throw new NodeRoleOperationException(
            step: "converge:{$exception->step}",
            errorCode: $exception->errorCode,
            underlyingErrorCode: $exception->underlyingErrorCode,
            message: $exception->getMessage(),
            result: $exception->result,
            previous: $exception->getPrevious(),
        );
    }
}
