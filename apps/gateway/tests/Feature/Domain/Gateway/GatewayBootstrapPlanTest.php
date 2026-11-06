<?php

declare(strict_types=1);

use App\Domain\Gateway\GatewayBootstrapPlan;

it('plans the packages, Caddy and PHP of a fresh Gateway from the recipe alone', function (): void {
    $plan = GatewayBootstrapPlan::make('https://10.45.0.1', '/home/orbit/.orbit', '/home/orbit/orbit/apps/gateway');

    expect($plan['packages'])->toContain('wireguard', 'logrotate', 'dnsmasq', 'python3-venv', 'composer', 'git', 'sqlite3')
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
    $this->artisan('orbit:bootstrap-plan --url=https://10.45.0.1 --home=/h/.orbit --checkout=/h/orbit/apps/gateway')->assertSuccessful();
    $this->artisan('orbit:bootstrap-plan')->assertFailed();
    $this->artisan('orbit:bootstrap-plan --environment --url=https://10.45.0.1 --home=/h/.orbit --checkout=/h/orbit/apps/gateway')->expectsOutputToContain('APP_URL=https://10.45.0.1')->assertSuccessful();
    expect(json_decode(json_encode(GatewayBootstrapPlan::make('https://10.45.0.1', '/home/orbit/.orbit', '/home/orbit/orbit/apps/gateway')), true))->toHaveKeys(['packages', 'caddy_package', 'directories', 'bun', 'php_packages', 'environment']);
});

it('renders the environment from the example with production values and an empty application key', function (): void {
    $environment = GatewayBootstrapPlan::environment("APP_ENV=local\nAPP_KEY=\nAPP_URL=http://localhost:8000\nORBIT_HOME=\nCACHE_STORE=file\n# APP_URL=ignored\n",
        ['APP_ENV' => 'production', 'APP_URL' => 'https://10.45.0.1', 'ORBIT_HOME' => '/home/orbit/.orbit']);

    expect($environment)->toBe("APP_ENV=production\nAPP_KEY=\nAPP_URL=https://10.45.0.1\nORBIT_HOME=/home/orbit/.orbit\nCACHE_STORE=file\n# APP_URL=ignored\n");
    $plan = GatewayBootstrapPlan::make('https://10.45.0.1', '/home/orbit/.orbit', '/home/orbit/orbit/apps/gateway');
    expect($plan['environment'])->toContain("APP_ENV=production\n", "APP_DEBUG=false\n", "APP_URL=https://10.45.0.1\n", "APP_KEY=\n", "DB_DATABASE=/home/orbit/.orbit/gateway.sqlite\n");
});
