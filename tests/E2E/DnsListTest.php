<?php

declare(strict_types=1);

use Tests\E2E\Support\E2ECommand;
use Tests\E2E\Support\E2ECurrentCheckout;
use Tests\E2E\Support\E2ETopologyFactory;
use Tests\E2E\Support\E2ETopologyKind;

pest()->group('e2e-feature', 'e2e-feature-control');

it('lists Orbit-managed resolver overrides on a Linux control node', function (): void {
    $topology = E2ETopologyFactory::fromEnvironment()
        ->withSshUsers(['control' => 'control'])
        ->require(E2ETopologyKind::Control);

    try {
        $control = $topology->control();
        $key = $topology->sshKeyPair();
        $checkout = E2ECurrentCheckout::install($control, 'control', $key);

        E2ECommand::ssh(
            $control,
            'control',
            $key,
            "cd {$checkout} && mkdir -p storage/app/orbit/dnsmasq.d && printf 'address=/.test/10.6.0.7\n' > storage/app/orbit/dnsmasq.d/test.conf",
        );

        $result = E2ECommand::ssh($control, 'control', $key, "cd {$checkout} && php artisan dns:list --json");
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
