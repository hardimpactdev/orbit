<?php

declare(strict_types=1);

use App\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-canary', 'e2e-feature-operator', 'e2e-feature-control');

it('lists Orbit-managed resolver overrides on a Linux operator node', function (): void {
    $topology = e2eTopology(E2ETopologyKind::Operator)
        ->withCurrentCheckout(roles: ['control']);

    try {
        $topology->ssh(
            'control',
            "cd {$topology->checkout('control')} && mkdir -p storage/app/orbit/dnsmasq.d && printf 'address=/.test/10.6.0.7\n' > storage/app/orbit/dnsmasq.d/test.conf",
        );

        $result = $topology->ssh('control', "cd {$topology->checkout('control')} && orbit dns:list --json");
        $payload = json_decode(trim($result->output()), associative: true, flags: JSON_THROW_ON_ERROR);

        expect($payload['success']['data']['dns'])->toBe([
            [
                'tld' => 'test',
                'target' => '10.6.0.7',
                'source' => 'local_resolver',
                'resolver_backend' => 'dnsmasq',
                'status' => 'active',
            ],
        ])->and($payload['success']['meta'])->toBe([
            'count' => 1,
            'resolver_backend' => 'dnsmasq',
        ]);
    } finally {
        $topology->cleanup();
    }
});
