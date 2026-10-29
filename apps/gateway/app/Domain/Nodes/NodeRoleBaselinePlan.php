<?php

declare(strict_types=1);

namespace App\Domain\Nodes;

use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\Storage\ConfiguredStoragePathValidator;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Routes\PrivateDnsPublicationNodes;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\MachineSoftware\NodeRecipeProgram;
use App\Infrastructure\MachineSoftware\PhpBuildInstaller;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\Nodes\AgentCaddyPackage;
use App\Infrastructure\Nodes\EnrollmentProgram;
use App\Infrastructure\Nodes\NodeRoleFirewallInput;
use App\Infrastructure\Nodes\Roles\RolePrerequisites;
use App\Models\Node;
use App\Models\NodeRole;

/** Renders role-owned effects. Swamp controls their order, retries and child Operations. */
final readonly class NodeRoleBaselinePlan
{
    public function __construct(private RolePrerequisites $prerequisites, private NodeRoleFirewallInput $firewall,
        private NodeSettingsNormalizer $settings, private ConfiguredStoragePathValidator $storage, private VpnSettings $vpn,
        private GatewayPrivateDnsResolver $resolver = new GatewayPrivateDnsResolver, private RecipeLoader $recipes = new RecipeLoader) {}

    /** @return list<array<string, mixed>> */
    public function converge(Node $node, NodeRole $assignment, ManagedUserAccount $account): array
    {
        $role = $assignment->role;
        if (in_array($role, [RoleName::Metrics, RoleName::Analytics, RoleName::WebSocket], true)) {
            return [$this->child('role-runtime', $role->value.'.role_converge', $node, [])];
        }
        $steps = [];
        if ($role === RoleName::AppDev) {
            $root = $this->storage->validateEffective($this->settings->fromStored($node->settings), $node, $account);
            $steps[] = $this->run('role-storage', 'node.prepare-storage-root', ['root' => $root->value, 'user' => $account->user, 'group' => $account->group, 'create_missing' => true]);
            $this->dns($steps, $node, 'app-dev');
        }
        if ($this->prerequisites->needsCaddy($node, $role) && ! EnrollmentProgram::skipForTaskVm($node, EnrollmentProgram::CaddyPackage)) {
            $steps[] = $this->run('role-caddy-package', 'caddy.install-package', AgentCaddyPackage::input());
        }
        if ($role === RoleName::Gateway) {
            $steps[] = $this->run('role-caddy-ordering', 'caddy.order-after-vpn', []);
            $steps[] = $this->run('role-firewall', 'firewall.rules', $this->firewall->converge($node, $role), ['matches' => true]);
            $steps[] = [...$this->run('role-gateway-access', 'node.grant-gateway-access', [], ['access_granted' => true]), 'transport' => 'gateway'];
            $this->dns($steps, $node, 'gateway');
            $address = $this->resolver->vpnDnsAddress($this->vpn);
            if ($address !== null) {
                $steps[] = [...$this->run('role-gateway-dns', 'wireguard.gateway-dns', ['action' => 'converge', 'address' => $address, 'domain' => $this->vpn->domain()]), 'optional' => true];
            }

            return $steps;
        }
        if (! EnrollmentProgram::skipForTaskVm($node, EnrollmentProgram::prerequisites($role))) {
            $steps[] = $this->run('role-prerequisites', 'role.install-prerequisites', $this->prerequisites->input($node, $role, $account));
            if (in_array($role, [RoleName::AppDev, RoleName::AppProd], true)) {
                $javascript = NodeRecipeProgram::javascript($node, $role);
                if ($javascript !== null) {
                    $steps[] = $this->run('role-javascript', 'recipe.install-javascript', $javascript);
                }
            }
        }
        if ($role === RoleName::Vpn) {
            $recipe = $this->recipes->load();
            $tools = array_intersect_key($recipe->tools, array_flip($recipe->profile($role->value)));
            $profiles = [...$node->roles->map(static fn (NodeRole $role): string => $role->role->value)->all(), $role->value];
            $steps[] = $this->run('role-php', 'php.install-build', PhpBuildInstaller::input($recipe, NodeRecipeProgram::phpVersions($recipe, $tools),
                array_intersect($profiles, $recipe->phpPolicy->pcovProfiles) !== [], 'keep', PhpBuildInstaller::fpmForProfiles($profiles),
                array_intersect($profiles, $recipe->phpPolicy->opentelemetryProfiles) !== []));
        }
        if ($role === RoleName::AppDev) {
            $steps[] = $this->run('role-hibernation', 'hibernation.prepare-directories', ['marker_directory' => RuntimeHibernation::MarkerDirectory, 'log_directory' => RuntimeHibernation::AccessLogDirectory]);
        }
        if (in_array($role, [RoleName::AppDev, RoleName::AppProd, RoleName::Router, RoleName::Ingress], true)) {
            $steps[] = $this->child('role-caddy', 'route.caddy_converge', $node, ['reason' => 'node-role:'.$assignment->id.':converge']);
        }
        if ($role === RoleName::CiRunner) {
            $steps[] = $this->run('role-ci-runner', 'ci-runner.host', ['action' => 'converge'], ['outcome' => 'converged']);
        }
        if (! in_array($role, [RoleName::Ingress, RoleName::Database], true)) {
            $steps[] = $this->run('role-firewall', 'firewall.rules', $this->firewall->converge($node, $role), ['matches' => true]);
            if ($role === RoleName::Router) {
                $steps[] = $this->run('role-router-lan', 'firewall.router-lan', $this->firewall->routerLan($node), ['matches' => true]);
            }
        }
        if ($role === RoleName::Vpn && $node->status === LifecycleStatus::Active) {
            $before = count($steps);
            $this->dns($steps, $node, 'vpn-php');
            if (count($steps) > $before) {
                $steps[array_key_last($steps)]['when'] = ['key' => 'role-php', 'result' => ['outcome' => 'applied']];
            }
        }
        if (in_array($role, [RoleName::AppDev, RoleName::AppProd], true)) {
            foreach (['vp', 'composer'] as $manager) {
                $steps[] = $this->child('role-manager-'.$manager, 'tool.manager_converge', $node,
                    ['manager' => $manager, 'image_provides_manager' => EnrollmentProgram::skipForTaskVm($node, $manager.'-manager')]);
            }
        }

        return $steps;
    }

    /** @return list<array<string, mixed>> */
    public function remove(Node $node, NodeRole $assignment, bool $purgeData, bool $offline): array
    {
        $role = $assignment->role;
        if (in_array($role, [RoleName::Metrics, RoleName::Analytics, RoleName::WebSocket], true)) {
            return [$this->child('role-runtime', $role->value.'.role_remove', $node, ['purge_data' => $purgeData, 'offline' => $offline])];
        }
        $steps = [];
        if ($offline) {
            if (in_array($role, [RoleName::AppDev, RoleName::Gateway], true)) {
                $this->dns($steps, $node, 'offline');
            }

            return $steps;
        }
        if ($role === RoleName::Gateway) {
            $steps[] = $this->run('role-gateway-dns', 'wireguard.gateway-dns', ['action' => 'remove']);
        }
        if (in_array($role, [RoleName::AppDev, RoleName::AppProd, RoleName::Router, RoleName::Ingress], true)) {
            $steps[] = $this->child('role-caddy', 'route.caddy_converge', $node, ['reason' => 'node-role:'.$assignment->id.':remove']);
        }
        if ($role === RoleName::CiRunner) {
            $steps[] = $this->run('role-ci-runner', 'ci-runner.host', ['action' => $purgeData ? 'purge' : 'remove'], ['outcome' => $purgeData ? 'removed' : 'retained']);
        }
        if ($role !== RoleName::Database) {
            $steps[] = $this->run('role-firewall', 'firewall.rules', $this->firewall->remove($node, $role), ['matches' => true]);
        }
        if ($role === RoleName::Router) {
            $steps[] = $this->run('role-router-lan', 'firewall.router-lan', $this->firewall->routerLan($node, true), ['matches' => true]);
        }
        if (in_array($role, [RoleName::AppDev, RoleName::Gateway], true)) {
            $this->dns($steps, $node, 'remove');
        }

        return $steps;
    }

    /** @param list<array<string, mixed>> $steps */
    private function dns(array &$steps, Node $node, string $reason): void
    {
        $serving = PrivateDnsPublicationNodes::query()->first();
        if ($serving instanceof Node) {
            $steps[] = $this->child('role-private-dns', 'route.private_dns_publish', $serving, ['reason' => 'node-role:'.$node->id.':'.$reason]);
        }
    }

    /** @param array<string, mixed> $input
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function run(string $key, string $name, array $input, array $result = []): array
    {
        return ['type' => 'run', 'key' => $key, 'transport' => 'agent', 'name' => $name, 'input' => $input, 'result' => $result, 'optional' => false];
    }

    /** @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function child(string $key, string $kind, Node $node, array $input): array
    {
        return ['type' => 'child', 'key' => $key, 'kind' => $kind, 'node_id' => $node->id, 'input' => $input, 'optional' => false];
    }
}
