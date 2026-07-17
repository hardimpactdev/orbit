<?php

declare(strict_types=1);

use App\Services\Analytics\GlobalUnicastIpAddress;

it('accepts public global-unicast addresses', function (string $address): void {
    expect(GlobalUnicastIpAddress::isGlobalUnicast($address))->toBeTrue();
})->with([
    'public IPv4' => '93.184.216.34',
    'provider IPv4' => '104.16.132.229',
    'public IPv6' => '2606:4700::6810:84e5',
]);

it('rejects non-global and special-use addresses', function (string $address): void {
    expect(GlobalUnicastIpAddress::isGlobalUnicast($address))->toBeFalse();
})->with([
    'unspecified IPv4' => '0.0.0.1',
    'private IPv4' => '10.6.0.5',
    'shared CGNAT' => '100.64.0.1',
    'loopback IPv4' => '127.0.0.1',
    'link-local IPv4' => '169.254.1.1',
    'private class B' => '172.16.0.1',
    'IETF protocol assignment' => '192.0.0.1',
    'documentation IPv4' => '192.0.2.1',
    'private class C' => '192.168.1.1',
    'benchmarking IPv4' => '198.18.0.1',
    'documentation IPv4 block two' => '198.51.100.1',
    'documentation IPv4 block three' => '203.0.113.1',
    'multicast IPv4' => '224.0.0.1',
    'reserved IPv4' => '240.0.0.1',
    'unspecified IPv6' => '::',
    'loopback IPv6' => '::1',
    'IPv4-mapped IPv6' => '::ffff:127.0.0.1',
    'NAT64 transition' => '64:ff9b::1',
    'discard-only IPv6' => '100::1',
    'Teredo range' => '2001::1',
    'benchmarking IPv6' => '2001:2::1',
    'documentation IPv6' => '2001:db8::1',
    '6to4 transition' => '2002::1',
    'documentation IPv6 block two' => '3fff::1',
    'unique-local IPv6' => 'fc00::1',
    'link-local IPv6' => 'fe80::1',
    'multicast IPv6' => 'ff00::1',
]);
