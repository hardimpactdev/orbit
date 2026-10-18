<?php

declare(strict_types=1);

use App\Actions\Gateway\GatewayBootstrapIdentityValidator;
use App\Data\Gateway\BootstrapGatewayData;

it('rejects unusable Gateway WireGuard addresses at the bootstrap boundary', function (string $address, string $reason): void {
    $data = new BootstrapGatewayData(
        publicHost: '85.9.218.89',
        wireguardIp: $address,
        wireguardSubnet: '10.44.0.0/30',
        wireguardEndpoint: '85.9.218.89:51820',
        dnsServer: '10.44.0.1',
        domain: 'orbit',
    );

    expect(fn () => new GatewayBootstrapIdentityValidator()->validate($data))
        ->toThrow(InvalidArgumentException::class, "Gateway WireGuard address [{$address}] is {$reason} [10.44.0.0/30].");
})->with([
    'network address' => ['10.44.0.0', 'not usable in'],
    'broadcast address' => ['10.44.0.3', 'not usable in'],
    'outside address' => ['10.44.0.4', 'outside'],
]);
