<?php

declare(strict_types=1);

use Tests\E2E\Support\E2EConfig;
use Tests\E2E\Support\E2ETopologyKind;
use Tests\E2E\Support\HcloudProvider;
use Tests\E2E\Support\IncusProvider;
use Tests\E2E\Support\ProviderAvailability;

it('constructs available ProviderAvailability', function (): void {
    $availability = ProviderAvailability::available();

    expect($availability->available)->toBeTrue()
        ->and($availability->message)->toBe('available');
});

it('constructs unavailable ProviderAvailability', function (): void {
    $availability = ProviderAvailability::unavailable('test reason');

    expect($availability->available)->toBeFalse()
        ->and($availability->message)->toBe('test reason');
});

it('hcloud provider does not support prepared topologies', function (): void {
    $provider = new HcloudProvider(E2EConfig::fromEnvironment());

    expect($provider->supportsPreparedTopologies())->toBeFalse();
});

it('hcloud provider topology availability returns hcloud-specific reason', function (): void {
    $provider = new HcloudProvider(E2EConfig::fromEnvironment());

    $availability = $provider->topologyAvailability(E2ETopologyKind::Control);

    expect($availability->available)->toBeFalse()
        ->and($availability->message)->toBe('hcloud prepared topologies are not implemented yet');
});

it('hcloud provider acquire topology throws runtime exception', function (): void {
    $provider = new HcloudProvider(E2EConfig::fromEnvironment());

    $provider->acquireTopology(E2ETopologyKind::Control, 'test');
})->throws(RuntimeException::class, 'hcloud prepared topologies are not implemented yet');

it('incus provider does not support prepared topologies', function (): void {
    $provider = new IncusProvider(E2EConfig::fromEnvironment());

    expect($provider->supportsPreparedTopologies())->toBeFalse();
});

it('incus provider topology availability returns incus-specific reason', function (): void {
    $provider = new IncusProvider(E2EConfig::fromEnvironment());

    $availability = $provider->topologyAvailability(E2ETopologyKind::Control);

    expect($availability->available)->toBeFalse()
        ->and($availability->message)->toBe('Incus prepared topologies are accessed via IncusTopologyTemplate, not the provider directly');
});

it('incus provider acquire topology throws runtime exception', function (): void {
    $provider = new IncusProvider(E2EConfig::fromEnvironment());

    $provider->acquireTopology(E2ETopologyKind::Control, 'test');
})->throws(RuntimeException::class, 'Incus prepared topologies are accessed via IncusTopologyTemplate, not the provider directly');
