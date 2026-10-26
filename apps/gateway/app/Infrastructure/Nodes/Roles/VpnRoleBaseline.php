<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\AppDev\PrivateDnsManager;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Nodes\NodeRoleValidationException;
use App\Domain\Nodes\RoleBaseline;
use App\Domain\Nodes\RoleName;
use App\Domain\RemotePrograms\RemoteProgramFailed;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Support\Facades\Log;

final readonly class VpnRoleBaseline implements RoleBaseline
{
    public function __construct(
        private RolePrerequisites $prerequisites,
        private NodeRoleFirewallManager $firewall,
        private ManagedUserAccountResolver $accounts,
        private ?PhpBuildInstaller $php = null,
        private RecipeLoader $recipes = new RecipeLoader,
        private ?PrivateDnsManager $dns = null,
    ) {}

    public function converge(Node $node, NodeRole $assignment): void
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'vpn.wireguard_ip_missing',
                "Node [{$node->name}] has no WireGuard address.",
            );
        }

        $account = $this->accounts->resolve($node);

        $wireguardIp = $node->wireguard_ip;

        $result = $this->prerequisites->install($node, RoleName::Vpn, $account);

        if (! $result->succeeded()) {
            throw new NodeRoleOperationException(
                'role-prerequisites',
                'node_role.convergence_failed',
                'vpn.prerequisite_failed',
                "VPN role prerequisites failed on node [{$node->name}].",
                $result,
            );
        }

        $recipe = $this->recipes->load();
        $tools = array_intersect_key($recipe->tools, array_flip($recipe->profile(RoleName::Vpn->value)));
        $versions = NodeRecipeProgram::phpVersions($recipe, $tools);
        $profiles = [...$node->roles->map(static fn (NodeRole $role): string => $role->role->value)->all(), RoleName::Vpn->value];
        try {
            $installed = ($this->php ?? app(PhpBuildInstaller::class))->run(
                $node, $recipe, $versions, 'apply',
                pcov: array_intersect($profiles, $recipe->phpPolicy->pcovProfiles) !== [],
                extras: 'keep', fpm: PhpBuildInstaller::fpmForProfiles($profiles),
                opentelemetry: array_intersect($profiles, $recipe->phpPolicy->opentelemetryProfiles) !== [],
            );
        } catch (RemoteProgramFailed $exception) {
            throw new NodeRoleOperationException(
                'private-dns-php', 'node_role.convergence_failed', 'vpn.php_install_failed',
                $exception->getMessage(), previous: $exception,
            );
        }
        if (! in_array($installed->outcome, PhpBuildInstaller::Succeeded, true)) {
            throw new NodeRoleOperationException(
                'private-dns-php', 'node_role.convergence_failed', 'vpn.php_install_failed',
                'Private DNS PHP installation failed: '.PhpBuildInstaller::describe($installed),
            );
        }
        Log::info('Private DNS PHP build verified.', ['node_id' => $node->id, 'outcome' => $installed->outcome]);

        $this->firewall->converge($node, RoleName::Vpn, $node->user);

        // Role convergence claims the assignment as provisioning while its VPN Node stays active.
        if ($installed->outcome === 'applied' && $node->status === LifecycleStatus::Active) {
            ($this->dns ?? app(PrivateDnsManager::class))->converge();
        }
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): never
    {
        throw new NodeRoleValidationException('The VPN role cannot be removed.');
    }

    public function removeUnreachable(Node $node, NodeRole $assignment): never
    {
        throw new NodeRoleValidationException('The VPN role cannot be removed.');
    }
}
