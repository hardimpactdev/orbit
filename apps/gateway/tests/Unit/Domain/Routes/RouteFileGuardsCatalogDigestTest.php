<?php

declare(strict_types=1);

use App\Domain\Routes\RouteFileGuards;

// The catalog Swamp's route withdrawal wrote on the nested VPN Node in the ORB-6310 proof, and this Gateway's render of the
// same state. The executor's dns crate pins the same canonical digest (a_catalog_swamp_rewrote_matches_...).
$swamp = '{"records":{"gateway.orbit":"10.45.0.1","reverb.orbit":"10.45.0.1","swamp.orbit":"10.45.0.1"},"requesters":{"10.45.0.1":1,"10.45.0.10":2,"10.45.0.2":3,"10.45.0.3":4},"suffixes":{"nested-dev":"10.45.0.2","nested-prod":"10.45.0.3"},"overrides":{}}'."\n";
$gateway = '{"requesters":{"10.45.0.1":1,"10.45.0.10":2,"10.45.0.2":3,"10.45.0.3":4},"records":{"gateway.orbit":"10.45.0.1","reverb.orbit":"10.45.0.1","swamp.orbit":"10.45.0.1"},"suffixes":{"nested-dev":"10.45.0.2","nested-prod":"10.45.0.3"},"overrides":[]}'."\n";

it('guards the catalog Swamp rewrote with the digest the Gateway rendered from', function () use ($swamp, $gateway): void {
    expect(hash('sha256', $swamp))->not->toBe(hash('sha256', $gateway))
        ->and(RouteFileGuards::catalogDigest($gateway))->toBe('141b5eab6c46db1a9c5e2615fc229b8b9bf0253d3f4079b95e6ce4470a03baba')
        ->and(RouteFileGuards::catalogDigest($swamp))->toBe(RouteFileGuards::catalogDigest($gateway));
});

it('keeps the plain digest of a canonical catalog and changes it for another answer', function () use ($swamp): void {
    expect(RouteFileGuards::catalogDigest("{\"shop\":1}\n"))->toBe(hash('sha256', "{\"shop\":1}\n"))
        ->and(RouteFileGuards::catalogDigest(str_replace('"swamp.orbit":"10.45.0.1"', '"swamp.orbit":"10.45.0.9"', $swamp)))
        ->not->toBe(RouteFileGuards::catalogDigest($swamp));
});

it('renders a catalog byte for byte as Swamp\'s withdrawal writes the same state', function (): void {
    // Written by swamp/ansible/module_utils/orbit_route_withdraw.py merge_dns from the Gateway's render.
    $fixture = json_decode((string) file_get_contents(base_path('tests/Fixtures/PrivateDns/swamp-withdrawn-catalog.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($fixture['written'])->not->toBe($fixture['gateway_render'])
        ->and(RouteFileGuards::swampCatalog($fixture['gateway_render']))->toBe($fixture['written']);
});

it('cannot name the bytes of a catalog whose key order came from an earlier writer', function () use ($swamp, $gateway): void {
    // The ORB-6310 catalog: Swamp kept the top-level key order of the file it merged into, which the Gateway cannot read.
    expect(RouteFileGuards::swampCatalog($gateway))->not->toBe($swamp);
});
