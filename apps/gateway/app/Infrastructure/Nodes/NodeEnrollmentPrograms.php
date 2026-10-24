<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AgentInvocation;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\TaskVms\TaskVmImageRecipe;
use App\Infrastructure\Tools\VpToolManager;
use App\Models\Node;
use InvalidArgumentException;

/**
 * The idempotent package and tool programs that enrollment runs on a Linux Node with the managed user `orbit`,
 * in enrollment order, rendered by the same code. It needs no SSH, no database row, and no network. Images that
 * run these programs ahead of enrollment use it: the task VM base image ({@see TaskVmImageRecipe})
 * and the role images of disposable test topologies (`orbit:node-programs`).
 *
 * Steps that need Gateway records, WireGuard keys, secrets, or Node-specific configuration are not programs here.
 */
final readonly class NodeEnrollmentPrograms
{
    private const string ManagedUser = 'orbit';

    /** The roles whose enrollment programs this renders. Metrics and analytics converge only from Gateway records. */
    private const array Roles = [
        RoleName::Gateway,
        RoleName::Vpn,
        RoleName::Router,
        RoleName::Ingress,
        RoleName::AppDev,
        RoleName::AppProd,
        RoleName::Database,
        RoleName::WebSocket,
    ];

    public function __construct(
        private NodeBootstrapCommandFactory $bootstrap,
        private RolePrerequisites $roles,
        private AgentInvocation $agent,
        private VpToolManager $vp,
        private RemotePhpPackageManager $php,
        private InstancePhpVersionCatalog $phpVersions,
    ) {}

    /**
     * The bootstrap program, then for each role in order its pinned Caddy package (once for all roles) and its
     * prerequisites. An application role then materializes the Vite+ and Composer managers, and `app-dev` adds the
     * PHP packages of every PHP version an Instance can select. No roles renders the bootstrap of a roleless
     * operator Node. Each program runs as `orbit` and starts with `sudo`.
     *
     * @param  list<RoleName>  $roles
     * @return array<string, RemoteCommand> keyed by stable program name ({@see EnrollmentProgram})
     *
     * @throws InvalidArgumentException for a repeated role or a role this cannot render
     */
    public function render(array $roles, string $architecture = 'x86_64'): array
    {
        $node = new Node(['name' => 'node-programs', 'platform' => 'linux', 'user' => self::ManagedUser, 'architecture' => $architecture]);
        $account = new ManagedUserAccount(self::ManagedUser, self::ManagedUser, '/home/'.self::ManagedUser);

        $programs = [EnrollmentProgram::Bootstrap => $this->agent->command($node, 'nodes.bootstrap', $this->bootstrap->input($node, self::ManagedUser, rolelessOperator: $roles === []))];
        $rendered = [];
        foreach ($roles as $role) {
            if (in_array($role, $rendered, strict: true)) {
                throw new InvalidArgumentException("Role [{$role->value}] is repeated.");
            }
            if (! in_array($role, self::Roles, strict: true)) {
                throw new InvalidArgumentException("Role [{$role->value}] has no enrollment programs that run without Gateway records.");
            }
            $rendered[] = $role;

            if ($this->roles->needsCaddy($node, $role)) {
                $programs[EnrollmentProgram::CaddyPackage] ??= $this->agent->command($node, 'caddy.install-package', AgentCaddyPackage::input());
            }
            // The gateway role has no prerequisites program; its Caddy follows the VPN through a role step.
            if ($role !== RoleName::Gateway) {
                $programs[EnrollmentProgram::prerequisites($role)] = $this->agent->command($node, 'roles.install-prerequisites', $this->roles->input($node, $role, $account));
            }
            if ($role === RoleName::AppDev || $role === RoleName::AppProd) {
                $programs[EnrollmentProgram::VpManager] ??= $this->agent->command($node, 'tools.materialize-vp', $this->vp->materializeInput($node));
                $programs[EnrollmentProgram::ComposerManager] ??= $this->agent->command($node, 'tools.materialize-composer', ['user' => $node->user]);
            }
            if ($role === RoleName::AppDev) {
                foreach ($this->php->appDevPrograms($node, $this->phpVersions->versions()) as $name => $program) {
                    $programs[EnrollmentProgram::AppDevPhp.'-'.str_replace('.', '-', (string) $name)] = $program;
                }
            }
        }

        return $programs;
    }
}
