<?php

declare(strict_types=1);

namespace App\Domain\TaskVms;

use App\Infrastructure\AgentCommands\AgentDelivery;

/**
 * The nested fleet of a task VM (docs/cli/tasks/vm-image-refresh.mmd): three Incus system containers on the nested network of the VM, a
 * Gateway with its own Swamp, and the app-dev and app-prod Nodes it leads. The fleet phase of the common converge
 * (swamp/ansible/tasks/task-vm-fleet.yml) builds it from stock Ubuntu once, on a base VM that has none. The records of the nested Gateway
 * name these addresses and MACs, so a built fleet keeps them.
 */
final class TaskVmNestedFleet
{
    /** The containers by role. */
    public const array Guests = ['gateway' => 'nested-gateway', 'app-dev' => 'nested-app-dev', 'app-prod' => 'nested-app-prod'];

    /** The nested network of a task VM: an Incus bridge that the tools phase makes (swamp/ansible/tasks/task-vm-incus.yml). */
    public const string Network = 'oe-nested';

    /** The subnet of the nested network. */
    public const string Subnet = '10.232.2.0/24';

    /** The bridge of the nested network: the first address of its subnet. */
    public const string Bridge = '10.232.2.1/24';

    /**
     * Each container by role: its address and MAC on the nested network, its address on the private network of the fleet, its memory
     * limit, and the TLD an `app-dev` or `app-prod` Node serves.
     */
    public const array Layout = [
        'gateway' => ['address' => '10.232.2.11', 'hwaddr' => '10:66:6a:5d:00:11', 'wireguard' => '10.45.0.1', 'memory' => '4GiB', 'tld' => null],
        'app-dev' => ['address' => '10.232.2.12', 'hwaddr' => '10:66:6a:5d:00:12', 'wireguard' => '10.45.0.2', 'memory' => '3GiB', 'tld' => 'nested-dev'],
        'app-prod' => ['address' => '10.232.2.13', 'hwaddr' => '10:66:6a:5d:00:13', 'wireguard' => '10.45.0.3', 'memory' => '3GiB', 'tld' => 'nested-prod'],
    ];

    /** The private network of the fleet, which its Gateway leads. */
    public const string WireGuardSubnet = '10.45.0.0/24';

    /** The task VM's address in that network, as the operator of the fleet. */
    public const string OperatorAddress = '10.45.0.10';

    /** Where the Gateway and app-dev containers see the checkout of the outer VM (an Incus disk device), and where their own state lives. */
    public const string CheckoutPath = '/home/orbit/orbit';

    public const string StatePath = '/var/lib/orbit-checkout-state';

    /** The state of the checkout on the Gateway container, over the shared checkout: the application's and the Swamp repository's own. */
    private const array GatewayState = ['apps/gateway/.env', 'apps/gateway/storage', 'apps/gateway/bootstrap/cache', 'swamp/.swamp', 'swamp/vaults', 'swamp/grants'];

    /** The state of the checkout on the app-dev container. */
    private const array AppDevState = ['apps/gateway/.env', 'apps/gateway/storage', 'apps/gateway/bootstrap/cache'];

    /**
     * The operator end of the fleet's private network: the task VM's address, the hub (the nested Gateway) and the subnet.
     *
     * @return array{address:string,hub:string,network:string}
     */
    public static function operator(): array
    {
        return ['address' => self::OperatorAddress, 'hub' => self::Layout['gateway']['wireguard'], 'network' => self::WireGuardSubnet];
    }

    /**
     * The part of the `gateway-bootstrap` input that the Gateway knows: the package cache of the task VM for the apt runs of the nested
     * Gateway, named the way every nested Node gets it; the address of the nested Gateway in the fleet's network; the operator with the
     * checkout that renders the plan; and the agent delivery. The run adds the Swamp pin, the machine with its host key and the release.
     *
     * @return array{apt_proxy:?string,gateway:array{url:string,wireguard_ip:string,wireguard_subnet:string},operator:array{checkout:string,wireguard_ip:string},agent_delivery:string}
     */
    public static function bootstrap(AgentDelivery $delivery, string $checkout): array
    {
        $hub = self::Layout['gateway']['wireguard'];

        return [
            'apt_proxy' => TaskVmAptCache::forAddress(self::Layout['gateway']['address']),
            'gateway' => ['url' => 'https://'.$hub, 'wireguard_ip' => $hub, 'wireguard_subnet' => self::WireGuardSubnet],
            'operator' => ['checkout' => $checkout, 'wireguard_ip' => self::OperatorAddress],
            'agent_delivery' => json_encode(['commit' => $delivery->commit(), 'version' => $delivery->version(), 'artifacts' => $delivery->artifacts()],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The checkout of the outer VM in the Gateway and app-dev containers, and the mount units of their own state. A bind mount of each
     * piece of state over the checkout is a stock systemd unit, named as systemd names it for `/home/orbit/orbit/<piece>`.
     *
     * @return array{path:string,state:string,units:list<array{guest:string,name:string,content:string}>,binds:array<string,list<string>>}
     */
    public static function checkout(): array
    {
        $units = [];
        $binds = [];
        foreach (['gateway' => self::GatewayState, 'app-dev' => self::AppDevState] as $role => $state) {
            $binds[$role] = [];
            foreach ($state as $relative) {
                $name = self::unitName(self::CheckoutPath.'/'.$relative);
                $binds[$role][] = $name;
                $units[] = ['guest' => self::Guests[$role], 'name' => $name, 'content' => implode("\n", [
                    '[Unit]', "Description=Orbit state {$relative}", 'RequiresMountsFor='.self::CheckoutPath, '',
                    '[Mount]', 'What='.self::StatePath.'/'.$relative, 'Where='.self::CheckoutPath.'/'.$relative, 'Type=none', 'Options=bind', '',
                    '[Install]', 'WantedBy=multi-user.target', '',
                ])];
            }
        }

        return ['path' => self::CheckoutPath, 'state' => self::StatePath, 'units' => $units, 'binds' => $binds];
    }

    /** The name of the mount unit of a path, as `systemd-escape --path --suffix=mount` writes it for these paths (no dash in a part, no escaped byte). */
    private static function unitName(string $path): string
    {
        return str_replace('/', '-', ltrim($path, '/')).'.mount';
    }
}
