<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
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

    private function host(Node $node, string $action): void
    {
        $this->ssh->execute(
            $node,
            CiRunnerHostProgram::command($action),
            'ci-runner-host',
            'ci-runner.host_failed',
            failureLabel: 'CI runner',
        );
    }
}
