<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Firewall\FirewallOperationException;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Firewall\RouterLanIngressPublisher;
use App\Domain\Nodes\NodeRoleFirewallManager;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\AgentCommands\AdmissionAgentRunner;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Firewall\UfwManagedRule;
use App\Infrastructure\Nodes\NodeSshJump;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;

final readonly class NativeNodeRoleFirewallManager implements NodeRoleFirewallManager, RouterLanIngressPublisher
{
    public function __construct(
        private AdmissionAgentRunner $agents,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private NodeFirewallRuleCatalog $catalog = new NodeFirewallRuleCatalog,
    ) {}

    public function convergeBase(Node $node, string $managedUser): void
    {
        $this->convergeRules(
            $node,
            [$this->publicSshRule($node)],
            publicConnection: true,
            enable: true,
            managedUser: $managedUser,
        );
    }

    public function converge(Node $node, RoleName $role, string $managedUser): void
    {
        $rules = $this->uniqueRules([$this->wireguardSshRule($node), ...$this->roleRules($node, $role)]);

        $this->convergeRules($node, $rules, publicConnection: false, enable: false, managedUser: $managedUser,
            remove: $this->uniqueRules([$this->publicSshRule($node), ...$this->catalog->retiredForRole($node, $role)]));

        if ($role !== RoleName::Router) {
            return;
        }

        $this->reconcileFamily(
            $node,
            $this->catalog->routerLanIngress($node),
            $managedUser,
            expand: true,
            prune: true,
        );
    }

    public function remove(Node $node, RoleName $role, string $managedUser): void
    {
        $rules = $this->roleRules($node, $role, owned: true);

        if ($rules !== []) {
            $this->removeRules($node, $rules, $managedUser);
        }

        if ($role === RoleName::Router) {
            $this->reconcileFamily($node, [], $managedUser, expand: false, prune: true);
        }
    }

    public function expandRouterLanIngress(Node $router, array $desired, string $managedUser): void
    {
        if ($desired === []) {
            return;
        }

        $this->reconcileFamily($router, $desired, $managedUser, expand: true, prune: false);
    }

    public function pruneRouterLanIngress(Node $router, array $desired, string $managedUser): void
    {
        $this->reconcileFamily($router, $desired, $managedUser, expand: false, prune: true);
    }

    public function trustWireGuardMembers(Node $node, string $managedUser): void
    {
        $this->convergeRules(
            $node,
            [$this->wireguardSshRule($node)],
            publicConnection: false,
            enable: false,
            managedUser: $managedUser,
        );
    }

    public function restorePublicSsh(Node $node, string $managedUser): void
    {
        $this->convergeRules(
            $node,
            [$this->publicSshRule($node)],
            publicConnection: false,
            enable: false,
            managedUser: $managedUser,
        );
    }

    /** @param list<UfwManagedRule> $rules */
    private function removeRules(Node $node, array $rules, string $managedUser): void
    {
        $this->convergeRules($node, [], false, false, $managedUser, remove: $rules);
    }

    /**
     * @param  list<UfwManagedRule>  $rules
     * @param  list<UfwManagedRule>  $remove
     */
    private function convergeRules(Node $node, array $rules, bool $publicConnection, bool $enable, string $managedUser, array $remove = []): void
    {
        $this->run($node, $managedUser, $publicConnection, 'firewall.rules', [
            'desired' => array_map(static fn (UfwManagedRule $rule): array => [
                'shape' => $rule->shape->toAgentInput(), 'replace_port' => $rule->replacePort,
            ], $rules),
            'remove' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), $remove),
            'enable' => $enable,
        ]);
    }

    /** @param array<string, mixed> $input */
    private function run(Node $node, string $managedUser, bool $publicConnection, string $command, array $input): void
    {
        $connection = $this->connection($node, $publicConnection, $managedUser);
        try {
            $result = $this->agents->runOver($node, $connection, $command, $input);
            if (($result['matches'] ?? null) !== true) {
                throw new \UnexpectedValueException('The managed firewall rules did not match.');
            }
        } catch (\Throwable $failure) {
            $code = $failure instanceof AgentCommandRefused && $failure->errorCode === 'router.lan_ingress_recovery_failed'
                ? $failure->errorCode : 'node.firewall_convergence_failed';
            throw new FirewallOperationException('host-firewall', $code, "Could not preserve SSH access and converge UFW on node [{$node->name}].", previous: $failure);
        }
    }

    private function connection(Node $node, bool $publicConnection, string $managedUser): SshConnection
    {
        $host = $publicConnection ? $node->public_ssh_host : $node->wireguard_ip;
        $port = $publicConnection ? $node->public_ssh_port : 22;

        if (! is_string($host) || $host === '') {
            throw new FirewallOperationException(
                step: 'host-firewall',
                errorCode: 'node.firewall_convergence_failed',
                message: "Node [{$node->name}] has no reachable firewall address.",
            );
        }

        return new SshConnection(
            host: $host,
            user: $managedUser,
            port: $port,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            proxyJump: $publicConnection
                ? NodeSshJump::connection($node, $this->keys->privateKeyPath(), $this->knownHosts->path())
                : null,
            hostIdentity: $node->ssh_host_fingerprint,
        );
    }

    private function publicSshRule(Node $node): UfwManagedRule
    {
        return $this->catalog->forNode($node)[0];
    }

    private function wireguardSshRule(Node $node): UfwManagedRule
    {
        return $this->catalog->forNode($node)[1];
    }

    /**
     * The role's rules without the Node baseline and Router LAN rules. `$owned` selects every rule the role can
     * own, which removal closes, instead of the rules its current condition requires.
     *
     * @return list<UfwManagedRule>
     */
    private function roleRules(Node $node, RoleName $role, bool $owned = false): array
    {
        $baselineComments = array_map(
            static fn (UfwManagedRule $rule): string => $rule->shape->comment,
            $this->catalog->forNode($node),
        );

        $policy = new RouterLanIngressPolicy;

        return array_values(array_filter(
            $owned ? $this->catalog->ownedByRole($node, $role) : $this->catalog->forRole($node, $role),
            static fn (UfwManagedRule $rule): bool => ! in_array(
                $rule->shape->comment,
                $baselineComments,
                strict: true,
            ) && ! $policy->ownsComment($rule->shape->comment),
        ));
    }

    /** @param list<UfwManagedRule> $desired */
    private function reconcileFamily(Node $node, array $desired, string $managedUser, bool $expand, bool $prune): void
    {
        $this->run($node, $managedUser, false, 'firewall.router-lan', [
            'desired' => array_map(static fn (UfwManagedRule $rule): array => $rule->shape->toAgentInput(), $desired),
            'expand' => $expand,
            'prune' => $prune,
        ]);
    }

    /**
     * @param  non-empty-list<UfwManagedRule>  $rules
     * @return non-empty-list<UfwManagedRule>
     */
    private function uniqueRules(array $rules): array
    {
        $unique = [];
        foreach ($rules as $rule) {
            $unique[$rule->shape->comment] = $rule;
        }

        $values = array_values($unique);

        return $values;
    }
}
