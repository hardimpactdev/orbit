<?php

declare(strict_types=1);

namespace App\Infrastructure\WireGuard;

use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Gateway\GatewayVpnConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\WireGuard\Ipv4Subnet;
use App\Infrastructure\AgentCommands\AgentCommandRefused;
use App\Infrastructure\AgentCommands\AgentCommandRunner;
use App\Infrastructure\AgentCommands\AgentRunnerFailure;
use App\Infrastructure\AgentCommands\AgentStep;
use App\Infrastructure\AppDev\VpnDnsmasqBackendListen;
use App\Infrastructure\Files\ProtectedFileWriter;
use App\Infrastructure\Firewall\UfwRuleShape;
use App\Infrastructure\Gateway\GatewayPrivateDnsResolver;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use Closure;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

final readonly class NativeGatewayVpnConverger implements GatewayVpnConverger
{
    public function __construct(
        private WireGuardServerConfigRenderer $renderer,
        private ProtectedFileWriter $files,
        private ProcessRunner $processes,
        private AgentCommandRunner $agents,
        private string $orbitHome,
        private UplinkDnsResolvers $uplinkResolvers = new UplinkDnsResolvers,
        private GatewayPrivateDnsResolver $resolver = new GatewayPrivateDnsResolver,
    ) {}

    public function converge(Node $gateway, BootstrapGatewayData $data): void
    {
        $this->withServerProjectionLock(function () use ($gateway, $data): void {
            $this->convergeWireGuard($gateway, $data);
        });
        $this->convergeDns($gateway, $data);
        $this->convergeFirewall($gateway, $data);
        $this->convergeResolver($data);
    }

    /**
     * Routes the private domain on this machine to its own VPN DNS listener, so clients on the
     * Gateway resolve private names such as `reverb.orbit`. A failure never fails bootstrap; Doctor
     * reports the missing route.
     */
    private function convergeResolver(BootstrapGatewayData $data): void
    {
        try {
            $command = $this->resolver->convergeCommand($data->dnsServer, $data->domain);
            $this->run(
                step: 'gateway-private-dns-resolver',
                errorCode: 'vpn.dns_resolver_failed',
                arguments: $command->arguments,
                input: $command->input,
            );
        } catch (NodeProvisioningException $exception) {
            Log::warning('The Gateway private DNS route step failed; bootstrap continues.', [
                'error_code' => $exception->errorCode,
                'exit_code' => $exception->result?->exitCode,
            ]);
        }
    }

    private function convergeWireGuard(Node $gateway, BootstrapGatewayData $data): void
    {
        $subnet = $this->subnet($data->wireguardSubnet);

        $prefixLength = $subnet->prefixLength();
        $privateKey = $this->key('private');
        $publicKey = $this->key('public');
        $configuration = new VpnConfiguration(
            server: $gateway,
            subnet: $subnet->value(),
            prefixLength: $prefixLength,
            port: $data->wireguardPort,
            endpoint: $data->wireguardEndpoint,
            dnsServer: $data->dnsServer,
            domain: $data->domain,
            serverAddress: "{$data->wireguardIp}/{$prefixLength}",
            peerAddress: "{$data->wireguardIp}/{$prefixLength}",
            serverPrivateKey: $privateKey,
            serverPublicKey: $publicKey,
        );
        $generatedPath = rtrim(string: $this->orbitHome, characters: '/').'/generated/wireguard/orbit.conf';
        $configuration = $this->renderer->render($configuration, Node::query()->whereNotNull('wireguard_public_key')->get());
        $this->files->put($generatedPath, $configuration);
        $this->installAgent($gateway);
        try {
            $this->agents->run($gateway, 'wireguard.bootstrap-hub', ['config' => $configuration]);
        } catch (AgentCommandRefused $refused) {
            [$step, $code] = match ($refused->errorCode) {
                'wireguard.hub_install_failed' => ['wireguard-server-install', 'vpn.server_config_install_failed'],
                'wireguard.hub_config_invalid' => ['wireguard-server-validate', 'vpn.server_config_invalid'],
                'wireguard.forwarding_install_failed' => ['wireguard-forwarding-install', 'vpn.forwarding_config_install_failed'],
                'wireguard.forwarding_config_invalid' => ['wireguard-forwarding-apply', 'vpn.forwarding_config_invalid'],
                default => ['wireguard-server-restart', 'vpn.server_start_failed'],
            };
            throw new NodeProvisioningException($step, $code, $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException('wireguard-server-restart', 'vpn.server_start_failed', 'The Gateway WireGuard command could not run.', previous: $failure, result: $failure->result);
        }
    }

    private function withServerProjectionLock(Closure $operation): void
    {
        $directory = rtrim(string: $this->orbitHome, characters: '/').'/locks';

        if (
            ! is_dir($directory)
            && ! mkdir(directory: $directory, permissions: 0o700, recursive: true)
            && ! is_dir($directory)
        ) {
            throw new NodeProvisioningException(
                step: 'wireguard-server-lock',
                errorCode: 'vpn.server_lock_failed',
                message: 'Could not create the gateway WireGuard projection lock directory.',
            );
        }

        chmod(filename: $directory, permissions: 0o700);
        $lockPath = $directory.'/wireguard-server.lock';
        $lock = fopen(filename: $lockPath, mode: 'c+');

        if ($lock === false) {
            throw new NodeProvisioningException(
                step: 'wireguard-server-lock',
                errorCode: 'vpn.server_lock_failed',
                message: 'Could not open the gateway WireGuard projection lock.',
            );
        }

        try {
            if (! flock($lock, LOCK_EX)) {
                throw new NodeProvisioningException(
                    step: 'wireguard-server-lock',
                    errorCode: 'vpn.server_lock_failed',
                    message: 'Could not acquire the gateway WireGuard projection lock.',
                );
            }

            $operation();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function convergeDns(Node $gateway, BootstrapGatewayData $data): void
    {
        $this->removeDnsOrderingDropIn($gateway);
        $configuration = implode(PHP_EOL, [
            '# Managed by Orbit.',
            'listen-address='.VpnDnsmasqBackendListen::Address,
            'bind-interfaces',
            'domain-needed',
            'bogus-priv',
            'no-resolv',
            ...array_map(
                static fn (string $server): string => "server={$server}",
                $this->uplinkResolvers->nameservers(),
            ),
            "local=/{$data->domain}/",
            "host-record={$data->name}.{$data->domain},{$data->wireguardIp}",
            '',
        ]);
        $generatedPath = rtrim(string: $this->orbitHome, characters: '/').'/generated/dnsmasq/orbit-vpn.conf';
        $this->files->put($generatedPath, $configuration, 0o644);
        $encoded = base64_encode($configuration);
        $this->run(
            step: 'vpn-dns',
            errorCode: 'vpn.dns_config_failed',
            arguments: ['sudo', 'bash', '-seu'],
            input: <<<BASH
                managed=/etc/dnsmasq.d/orbit-vpn.conf
                candidate=/etc/dnsmasq.d/.orbit-vpn.\$\$.candidate
                validation=\$(mktemp -d)
                backup=\$(mktemp /etc/dnsmasq.d/.orbit-vpn.backup.XXXXXX)
                had_managed=0
                trap 'rm -rf -- "\$validation"; rm -f -- "\$candidate" "\$backup"' EXIT
                restart_dnsmasq() {
                    if systemctl restart dnsmasq; then
                        return 0
                    fi
                    printf 'dnsmasq did not restart. Last journal lines:\\n' >&2
                    journalctl --unit=dnsmasq --lines=20 --no-pager >&2 || true
                    return 1
                }
                exec 9>/run/lock/orbit-dnsmasq.lock
                flock -w 30 9
                if [ -f "\$managed" ]; then
                    cp --preserve=mode,ownership -- "\$managed" "\$backup"
                    had_managed=1
                fi
                install -d -m 0755 -- "\$validation/fragments"
                cp -a -- /etc/dnsmasq.d/. "\$validation/fragments/"
                printf '%s' '{$encoded}' | base64 --decode > "\$validation/fragments/orbit-vpn.conf"
                sed "s#/etc/dnsmasq.d#\$validation/fragments#g" /etc/dnsmasq.conf > "\$validation/dnsmasq.conf"
                dnsmasq --test --conf-file="\$validation/dnsmasq.conf"
                if [ -f "\$managed" ] && cmp -s -- "\$validation/fragments/orbit-vpn.conf" "\$managed"; then
                    if systemctl is-active --quiet dnsmasq; then
                        exit 0
                    fi
                    restart_dnsmasq
                    exit 0
                fi
                install -o root -g root -m 0644 -- "\$validation/fragments/orbit-vpn.conf" "\$candidate"
                mv -fT -- "\$candidate" "\$managed"
                if ! restart_dnsmasq; then
                    if [ "\$had_managed" = 1 ]; then
                        install -o root -g root -m 0644 -- "\$backup" "\$managed"
                    else
                        rm -f -- "\$managed"
                    fi
                    systemctl restart dnsmasq || true
                    exit 1
                fi
                BASH,
        );
    }

    /**
     * Removes the drop-in that earlier Orbit versions installed to order
     * dnsmasq after `wg-quick@orbit.service`. dnsmasq provides
     * `nss-lookup.target`, which `wg-quick@.service` is ordered after, so the
     * drop-in closed an ordering cycle that systemd broke by deleting a job.
     * The managed fragment binds `orbit` dynamically instead.
     */
    private function removeDnsOrderingDropIn(Node $gateway): void
    {
        try {
            $this->agents->run($gateway, 'wireguard.remove-dns-ordering');
        } catch (AgentCommandRefused $refused) {
            throw new NodeProvisioningException('vpn-dns-ordering', 'vpn.dns_config_failed', $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException('vpn-dns-ordering', 'vpn.dns_config_failed', 'The dnsmasq ordering command could not run.', previous: $failure, result: $failure->result);
        }
    }

    private function installAgent(Node $gateway): void
    {
        try {
            $this->agents->install($gateway);
        } catch (\Throwable $exception) {
            throw new NodeProvisioningException('agent-install', 'agent.install_failed', 'The Gateway command agent could not be installed.', previous: $exception);
        }
    }

    private function convergeFirewall(Node $gateway, BootstrapGatewayData $data): void
    {
        try {
            $this->agents->run($gateway, 'firewall.gateway-vpn', [
                'desired' => array_map(static fn (UfwRuleShape $shape): array => $shape->toAgentInput(), $this->firewallRules($data)),
                'recovery' => $this->incomingFirewallShape('orbit:public-ssh-recovery', 'tcp', (string) $gateway->public_ssh_port)->toAgentInput(),
            ]);
        } catch (AgentCommandRefused $refused) {
            $step = match ($refused->errorCode) {
                'vpn.firewall_status_failed', 'vpn.firewall_status_invalid' => 'vpn-firewall-status',
                'vpn.firewall_recovery_probe_failed' => 'vpn-firewall-recovery-probe',
                'vpn.firewall_enable_failed' => 'vpn-firewall-enable',
                'vpn.firewall_rule_failed' => 'vpn-firewall',
                'vpn.firewall_recovery_remove_failed' => 'vpn-firewall-recovery-remove',
                default => 'vpn-firewall-probe',
            };
            throw new NodeProvisioningException($step, $refused->errorCode, $refused->getMessage(), previous: $refused, result: AgentStep::output($refused));
        } catch (AgentRunnerFailure $failure) {
            throw new NodeProvisioningException('vpn-firewall-status', 'vpn.firewall_status_failed', 'The Gateway firewall command could not run.', previous: $failure, result: $failure->result);
        }
    }

    /** @return list<UfwRuleShape> */
    private function firewallRules(BootstrapGatewayData $data): array
    {
        $rules = [
            $this->incomingFirewallShape('orbit:vpn-wireguard', 'udp', (string) $data->wireguardPort),
            new UfwRuleShape(
                comment: 'orbit:vpn-ssh', action: 'allow', direction: 'in', source: 'any',
                destination: $data->wireguardIp, port: '22', protocol: 'tcp',
                inInterface: 'orbit', outInterface: null, family: 'v4',
            ),
        ];
        foreach (array_unique($this->privateInterfaces($data)) as $interface) {
            foreach (['udp', 'tcp'] as $protocol) {
                $rules[] = $this->incomingFirewallShape("orbit:vpn-dns-{$protocol}-{$interface}", $protocol, '53', $interface);
            }
        }
        $rules[] = $this->incomingFirewallShape('orbit:gateway-https', 'tcp', '443', 'orbit');
        $rules[] = new UfwRuleShape(
            comment: 'orbit:vpn-peer-forwarding', action: 'allow', direction: 'fwd',
            source: $data->wireguardSubnet, destination: $data->wireguardSubnet,
            port: 'any', protocol: 'any', inInterface: 'orbit', outInterface: 'orbit', family: 'v4',
        );

        return $rules;
    }

    private function incomingFirewallShape(
        string $comment,
        string $protocol,
        string $port,
        ?string $interface = null,
    ): UfwRuleShape {
        return new UfwRuleShape(
            comment: $comment,
            action: 'allow',
            direction: 'in',
            source: 'any',
            destination: 'any',
            port: $port,
            protocol: $protocol,
            inInterface: $interface,
            outInterface: null,
            family: null,
        );
    }

    /** @return non-empty-list<string> */
    private function privateInterfaces(BootstrapGatewayData $data): array
    {
        if ($data->privateInterface === null) {
            return ['orbit'];
        }

        return ['orbit', $data->privateInterface];
    }

    private function subnet(string $value): Ipv4Subnet
    {
        try {
            return Ipv4Subnet::from($value);
        } catch (InvalidArgumentException) {
            throw new NodeProvisioningException(
                step: 'wireguard-configuration',
                errorCode: 'vpn.configuration_invalid',
                message: "WireGuard subnet [{$value}] is invalid.",
            );
        }
    }

    private function key(string $name): string
    {
        $path = rtrim(string: $this->orbitHome, characters: '/')."/wireguard/{$name}.key";
        $key = file_get_contents($path);

        if (! is_string($key) || trim($key) === '') {
            throw new NodeProvisioningException(
                step: 'wireguard-configuration',
                errorCode: 'vpn.configuration_invalid',
                message: "WireGuard key [{$path}] is missing.",
            );
        }

        return trim($key);
    }

    /** @param non-empty-list<string> $arguments */
    private function run(
        string $step,
        string $errorCode,
        array $arguments,
        ?string $input = null,
    ): CommandResult {
        $result = $this->processes->run(new ProcessInvocation($arguments, timeout: 60.0, input: $input));

        if (! $result->succeeded()) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: $errorCode,
                message: 'Could not converge the gateway WireGuard service.',
                result: $result,
            );
        }

        return $result;
    }
}
