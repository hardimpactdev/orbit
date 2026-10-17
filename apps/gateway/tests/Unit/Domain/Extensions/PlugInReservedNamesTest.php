<?php

declare(strict_types=1);

use App\Domain\Extensions\ConfigValue;
use App\Domain\Extensions\ReservedNames;
use App\Domain\Routes\ReservedPrivateHostname;
use App\Infrastructure\Caddy\Build\CaddySiteCertificates;
use App\Infrastructure\Caddy\Build\NodeCaddyListeners;

it('keeps the existing reserved core names and their public aliases', function (): void {
    expect(ReservedNames::CaddySources)->toBe(['gateway', 'metrics', 'service-metrics', 'app-dev', 'app-prod', 'ingress', 'websocket', 'analytics'])
        ->and(NodeCaddyListeners::CoreSources)->toBe(ReservedNames::CaddySources)
        ->and(ReservedNames::CertificateSlugs)->toBe(['websocket', 'analytics', 'metrics'])
        ->and(CaddySiteCertificates::CoreSlugs)->toBe(ReservedNames::CertificateSlugs)
        ->and([CaddySiteCertificates::Websocket, CaddySiteCertificates::Analytics, CaddySiteCertificates::Metrics])->toBe(ReservedNames::CertificateSlugs)
        ->and(ReservedNames::PrivateDnsNames)->toBe(['gateway.orbit', 'metrics.orbit', 'reverb.orbit', 'analytics.orbit'])
        ->and(ReservedPrivateHostname::CoreNames)->toBe(ReservedNames::PrivateDnsNames)
        ->and(ReservedNames::FootprintArtifacts)->toBe(['agent', 'caddy', 'caddy-package', 'private-dns', 'route-residue', 'tmpfiles']);
});

it('reserves unique, config-safe names in each namespace', function (): void {
    foreach ([ReservedNames::CaddySources, ReservedNames::CertificateSlugs, ReservedNames::FootprintArtifacts] as $names) {
        expect($names)->toBe(array_values(array_unique($names)));
        foreach ($names as $name) {
            expect(ConfigValue::slug($name))->toBeTrue();
        }
    }

    expect(ReservedNames::PrivateDnsNames)->toBe(array_values(array_unique(ReservedNames::PrivateDnsNames)));
    foreach (ReservedNames::PrivateDnsNames as $name) {
        expect(ConfigValue::dnsName($name))->toBeTrue();
    }
});
