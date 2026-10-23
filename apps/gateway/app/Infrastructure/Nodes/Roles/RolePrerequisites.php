<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/**
 * A role's prerequisites through the agent: the pinned Caddy package, then `roles.install-prerequisites`. Each answers
 * a command result, so each role keeps its own failure.
 */
final readonly class RolePrerequisites
{
    public function __construct(
        private AgentCommandRunner $agents,
        private AgentCaddyPackage $caddy,
        private NodeBootstrapPackageCatalog $packages = new NodeBootstrapPackageCatalog,
    ) {}

    public function needsCaddy(Node $node, RoleName $role): bool
    {
        return in_array('caddy', $this->packages->forRole($node, $role), strict: true);
    }

    /**
     * Installs the pinned Caddy project package before the role installs its packages, so `caddy` comes from the
     * Caddy project rather than the Ubuntu archive. Null when the role needs no Caddy.
     */
    public function caddy(Node $node, RoleName $role): ?CommandResult
    {
        return $this->needsCaddy($node, $role) ? $this->caddy->install($node) : null;
    }

    public function install(Node $node, RoleName $role, ManagedUserAccount $account): CommandResult
    {
        if ($role === RoleName::Gateway) {
            return new CommandResult(0, '', '', 0, false);
        }

        try {
            $this->agents->run($node, 'roles.install-prerequisites', $this->input($node, $role, $account));
        } catch (AgentCommandRefused $refusal) {
            return AgentStep::output($refusal);
        } catch (AgentRunnerFailure $failure) {
            return $failure->result ?? new CommandResult(255, '', $failure->getMessage(), 0, false);
        }

        return new CommandResult(0, '', '', 0, false);
    }

    /**
     * Installs the Caddy package and then the prerequisites, and fails the step that failed with the role's label.
     * A task VM skips what its image already holds.
     */
    public function converge(Node $node, RoleName $role, ManagedUserAccount $account, string $errorCode, string $label, bool $caddy = true, bool $packages = true): void
    {
        $result = $caddy ? $this->caddy($node, $role) : null;
        if ($result !== null && ! $result->succeeded()) {
            throw AgentStep::failed($node, 'caddy-package-source', $errorCode, $label, $result);
        }
        $result = $packages ? $this->install($node, $role, $account) : null;
        if ($result !== null && ! $result->succeeded()) {
            throw AgentStep::failed($node, 'role-prerequisites', $errorCode, $label, $result);
        }
    }

    /** @return array{user: string, group: string, home: string, releases: list<string>, packages: list<string>, apps_directory: bool, application_runtime: bool} */
    public function input(Node $node, RoleName $role, ManagedUserAccount $account): array
    {
        return [
            'user' => $account->user,
            'group' => $account->group,
            'home' => $account->home,
            'releases' => array_map(static fn (UbuntuRelease $release): string => $release->value, UbuntuRelease::forRole($role)),
            'packages' => $this->packages->forRole($node, $role),
            'apps_directory' => $role === RoleName::AppDev,
            'application_runtime' => in_array($role, [RoleName::AppDev, RoleName::AppProd], strict: true),
        ];
    }
}
