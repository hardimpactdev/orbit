<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayBootstrapPlan;

it('plans the packages, Caddy and PHP of a fresh Gateway from the recipe alone', function (): void {
    $plan = GatewayBootstrapPlan::make();

    expect($plan['packages'])->toContain('wireguard', 'logrotate', 'dnsmasq', 'python3-venv', 'composer', 'git')
        ->not->toContain('caddy')
        ->and($plan['packages'])->toBe(array_values(array_unique($plan['packages'])))
        ->and($plan['caddy_package']['path'])->toEndWith('_linux_amd64.deb')
        ->and($plan['bun'])->toBeNull()
        ->and($plan['directories'][0]['path'])->toBe('/var/cache/orbit/role-php');
    $names = array_column($plan['php_packages'], 'name');
    expect($names)->toContain('php8.5-cli', 'php8.5-fpm')
        ->not->toContain('php8.4-cli', 'php8.5-opentelemetry');
    foreach ($plan['php_packages'] as $package) {
        expect($package['sha256'])->toMatch('/^[0-9a-f]{64}$/')
            ->and($package['path'])->toStartWith('/var/cache/orbit/role-php/')
            ->and($package['urls'])->not->toBeEmpty();
    }
});

it('prints the plan as one JSON line', function (): void {
    $this->artisan('orbit:bootstrap-plan')->assertSuccessful();
    expect(json_decode(json_encode(GatewayBootstrapPlan::make()), true))->toHaveKeys(['packages', 'caddy_package', 'directories', 'bun', 'php_packages']);
});
