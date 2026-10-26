<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\RoleInspectionData;
use App\Domain\Doctor\RoleStateInspector;
use App\Domain\Firewall\RouterLanIngressPolicy;
use App\Domain\Nodes\RoleName;
use App\Domain\WireGuard\VpnSettings;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\Firewall\NodeFirewallRuleCatalog;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Nodes\CiRunnerHostProgram;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\NodeRoleServiceCatalog;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\NodeRole;
use Throwable;

final readonly class NativeRoleStateInspector implements RoleStateInspector
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private NodeBootstrapPackageCatalog $packages,
        private NodeRoleServiceCatalog $services,
        private NodeFirewallRuleCatalog $firewall,
        private CommandDeadline $deadline,
        private AgentCommandRunner $agents,
        private UfwManagedRulesCheck $firewallCheck = new UfwManagedRulesCheck,
        private GatewayPrivateDnsResolver $resolver = new GatewayPrivateDnsResolver,
        private ?VpnSettings $vpnSettings = null,
        private ?CiRunnerHostProgram $ciRunner = null,
    ) {}

    public function inspect(NodeRole $role): RoleInspectionData
    {
        try {
            $role->loadMissing('node');
            $node = $role->node;
            $connection = $this->connection($node);
            $required = $this->packages->forRole($node, $role->role);
            $runtime = $this->agents->run($node, 'doctor.inspect-role-runtime', [
                'packages' => $required,
                'services' => $this->services->forRole($role->role),
            ], $this->deadline->cap(30.0));
            if (! is_bool($runtime['packages'] ?? null) || ! is_bool($runtime['services'] ?? null)
                || ! array_key_exists('caddy_version', $runtime)
                || ($runtime['caddy_version'] !== null && ! is_string($runtime['caddy_version']))) {
                throw new DoctorInspectionException;
            }

            return new RoleInspectionData(
                $runtime['packages'],
                $runtime['services'],
                $this->firewallMatches($node, $role),
                $runtime['caddy_version'],
                $role->role === RoleName::Gateway ? $this->privateDnsRouteMatches($connection) : null,
                $role->role === RoleName::CiRunner
                    ? $this->ciRunnerHostMatches($node)
                    : null,
            );
        } catch (DoctorInspectionException $exception) {
            throw $exception;
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }
    }

    private function ciRunnerHostMatches(Node $node): bool
    {
        return match (($this->ciRunner ?? app(CiRunnerHostProgram::class))->run($node, 'inspect')) {
            'matches' => true,
            'drift' => false,
            default => throw new DoctorInspectionException,
        };
    }

    private function connection(Node $node): SshConnection
    {
        $host = $node->wireguard_ip;
        if ($node->platform !== 'linux' || ! is_string($host) || $host === '') {
            throw new DoctorInspectionException;
        }

        return new SshConnection(
            $host,
            $node->user,
            22,
            $this->keys->privateKeyPath(),
            $this->knownHosts->path(),
            commandTimeout: $this->deadline->cap(30.0),
        );
    }

    /**
     * Whether the Gateway machine routes the private domain to VPN DNS as ADR 0156 describes. Null
     * while no Node serves VPN DNS, because then there is no route to expect.
     */
    private function privateDnsRouteMatches(SshConnection $connection): ?bool
    {
        $settings = $this->vpnSettings ?? app(VpnSettings::class);
        $address = $this->resolver->vpnDnsAddress($settings);

        if ($address === null) {
            return null;
        }

        return $this->booleanResult($this->ssh->execute(
            $connection,
            $this->resolver->inspectCommand($address, $settings->domain()),
        ));
    }

    private function booleanResult(CommandResult $result): bool
    {
        if (! $result->succeeded() || $result->truncated) {
            throw new DoctorInspectionException;
        }

        return match ($result->stdout) {
            "1\n" => true,
            "0\n" => false,
            default => throw new DoctorInspectionException,
        };
    }

    private function firewallMatches(Node $node, NodeRole $role): bool
    {
        return $this->firewallCheck->matches(
            $this->agents,
            $node,
            $this->firewall->forRole($node, $role->role),
            $role->role === RoleName::Router ? array_keys(RouterLanIngressPolicy::Families) : [],
            $this->deadline->cap(30.0),
        );
    }
}
