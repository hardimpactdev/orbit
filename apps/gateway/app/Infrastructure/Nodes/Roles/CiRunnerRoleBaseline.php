<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Nodes\CiRunnerHostProgram;
use App\Models\Node;
use App\Models\NodeRole;

final readonly class CiRunnerRoleBaseline implements RoleBaseline
{
    public function __construct(
        private NodeRolePrerequisiteCommandFactory $commands,
        private ManagedUserAccountResolver $accounts,
        private DevelopmentSshExecutor $ssh,
        private NodeRoleFirewallManager $firewall,
        private CiRunnerHostProgram $program,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        $this->ssh->execute(
            $node,
            $this->commands->make($node, RoleName::CiRunner, $this->accounts->resolve($node)),
            'role-prerequisites',
            'ci-runner.prerequisite_failed',
            failureLabel: 'CI runner',
        );
        $this->host($node, 'converge');
        $this->firewall->converge($node, RoleName::CiRunner, $node->user);
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->host($node, $purgeData ? 'purge' : 'remove');
        $this->firewall->remove($node, RoleName::CiRunner, $node->user);
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): void {}

    /** @param 'converge'|'remove'|'purge' $action */
    private function host(Node $node, string $action): void
    {
        try {
            $result = $this->program->run($node, $action);
        } catch (RemoteProgramFailed $exception) {
            throw new RuntimeConvergenceException('ci-runner-host', 'ci-runner.host_failed',
                "CI runner host program failed on node [{$node->name}].", previous: $exception);
        }
        if ($result->outcome === 'occupied') {
            throw new RuntimeConvergenceException('ci-runner-host', 'ci-runner.host_failed',
                'Remove the CI runner containers and their GitHub registrations before removing the ci-runner role.');
        }
        $expected = match ($action) {
            'converge' => 'converged',
            'purge' => 'removed',
            default => 'retained',
        };
        if ($result->outcome !== $expected) {
            throw new RuntimeConvergenceException('ci-runner-host', 'ci-runner.host_failed',
                "CI runner host program ended with [{$result->outcome}] on node [{$node->name}].");
        }
    }
}
