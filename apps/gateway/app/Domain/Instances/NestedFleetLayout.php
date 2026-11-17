<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use Symfony\Component\HttpFoundation\IpUtils;

/** The stable layout of an Instance container's nested Gateway, app-dev and app-prod Nodes. */
final class NestedFleetLayout
{
    /** The containers by role. */
    public const array Guests = ['gateway' => 'nested-gateway', 'app-dev' => 'nested-app-dev', 'app-prod' => 'nested-app-prod'];

    /** The subnet of the nested network. */
    public const string Subnet = '10.232.2.0/24';

    /** Each container's address, MAC, private fleet address, memory limit and served TLD. */
    public const array Layout = [
        'gateway' => ['address' => '10.232.2.11', 'hwaddr' => '10:66:6a:5d:00:11', 'wireguard' => '10.45.0.1', 'memory' => '4GiB', 'tld' => null],
        'app-dev' => ['address' => '10.232.2.12', 'hwaddr' => '10:66:6a:5d:00:12', 'wireguard' => '10.45.0.2', 'memory' => '3GiB', 'tld' => 'nested-dev'],
        'app-prod' => ['address' => '10.232.2.13', 'hwaddr' => '10:66:6a:5d:00:13', 'wireguard' => '10.45.0.3', 'memory' => '3GiB', 'tld' => 'nested-prod'],
    ];

    /** The private network of the fleet, which its Gateway leads. */
    public const string WireGuardSubnet = '10.45.0.0/24';

    /** The outer container's address in the fleet's private network. */
    public const string OperatorAddress = '10.45.0.10';

    /** Where the nested Gateway and app-dev see the outer container's checkout. */
    public const string CheckoutPath = '/home/orbit/orbit';

    /** Whether a machine address belongs to the nested fleet subnet. */
    public static function holds(string $address): bool
    {
        return IpUtils::checkIp($address, self::Subnet);
    }

    /**
     * The operator end of the private network and the fleet's resolver domains.
     *
     * @return array{address:string,hub:string,network:string,domains:list<string>}
     */
    public static function operator(): array
    {
        return ['address' => self::OperatorAddress, 'hub' => self::Layout['gateway']['wireguard'], 'network' => self::WireGuardSubnet,
            'domains' => array_values(array_filter(array_column(self::Layout, 'tld')))];
    }
}
