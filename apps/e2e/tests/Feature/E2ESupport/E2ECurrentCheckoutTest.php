<?php

declare(strict_types=1);

use App\E2E\Support\DockerHost;
use App\E2E\Support\DockerInstance;
use App\E2E\Support\E2EConfig;
use App\E2E\Support\E2ECurrentCheckout;

it('hydrates reused vendor dependencies inside the current checkout instead of symlinking to the base checkout', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'reusePreparedVendorWithLocalAutoloadCommand');

    $command = $method->invoke(
        null,
        'apps/gateway',
        '/home/orbit/orbit-current-base-1234567890',
        "else echo 'missing vendor'; exit 127",
    );

    expect($command)
        ->toContain('cp -al "$path" "$target"/')
        ->toContain('cp -a --reflink=always "$path" "$target"/')
        ->not->toContain('ln -s');
});

it('uses the archive checkout path for prepared docker host-launcher instances', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'sourceMountedCheckoutPath');
    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-gateway');

    expect($method->invoke(null, $instance, 'orbit', true))->toBeNull();
});

it('keeps explicit source-mounted docker checkout paths', function (): void {
    $method = new ReflectionMethod(E2ECurrentCheckout::class, 'sourceMountedCheckoutPath');
    $instance = new DockerInstance(new DockerHost(E2EConfig::fromEnvironment()), 'orbit-e2e-gateway', null, '/home/orbit/orbit');

    expect($method->invoke(null, $instance, 'orbit', true))->toBe('/home/orbit/orbit');
});

it('passes Docker gateway state environment through the current checkout wrapper', function (): void {
    $script = E2ECurrentCheckout::orbitWrapperScript('/home/orbit/orbit-current', dockerRuntime: true);

    expect($script)
        ->toContain("--env 'ORBIT_CONFIG_ROOT=/home/orbit/.config/orbit'")
        ->toContain("--env 'DB_CONNECTION=sqlite'")
        ->toContain("--env 'DB_DATABASE=/home/orbit/.config/orbit/gateway.sqlite'")
        ->toContain("--env 'SESSION_DRIVER=file'");
});
