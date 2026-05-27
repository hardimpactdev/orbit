<?php

declare(strict_types=1);

it('keeps root gateway forwarding helper scripts available', function (): void {
    expect(base_path('bin/orbit-gateway-artisan'))->toBeFile()
        ->and(base_path('bin/orbit-gateway-pest'))->toBeFile()
        ->and(base_path('bin/orbit-gateway-vendor-bin'))->toBeFile();
});

it('routes root composer gateway scripts through path-aware helpers', function (): void {
    $composer = json_decode(
        (string) file_get_contents(base_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['docs-lint'])
        ->each->toContain('bin/orbit-gateway-artisan')
        ->and($composer['scripts']['test'][1])->toContain('bin/orbit-gateway-artisan config:clear')
        ->and($composer['scripts']['test'][2])->toContain('bin/orbit-gateway-pest')
        ->and($composer['scripts']['test:slow'][1])->toContain('bin/orbit-gateway-artisan config:clear')
        ->and($composer['scripts']['test:slow'][2])->toContain('bin/orbit-gateway-pest')
        ->and($composer['scripts']['test:e2e'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
        ->and($composer['scripts']['test:e2e:docker'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
        ->and($composer['scripts']['test:e2e:docker:canary'][1])->toContain('bin/orbit-gateway-artisan e2e:test --canary')
        ->and($composer['scripts']['test:e2e:incus'][1])->toContain('bin/orbit-gateway-artisan e2e:test')
        ->and($composer['scripts']['test:e2e:provision'][1])->toContain('bin/orbit-gateway-artisan test')
        ->and($composer['scripts']['e2e:preflight'])->toContain('bin/orbit-gateway-artisan e2e:preflight')
        ->and($composer['scripts']['e2e:prepare-docker-topology'][1])->toContain('bin/orbit-gateway-artisan e2e:prepare-docker-topology')
        ->and($composer['scripts']['analyse'])->toContain('bin/orbit-gateway-vendor-bin phpstan')
        ->and($composer['scripts']['format'])->toContain('bin/orbit-gateway-vendor-bin pint')
        ->and($composer['scripts']['rector'])->toContain('bin/orbit-gateway-vendor-bin rector');
});

it('keeps public orbit launcher able to resolve current and relocated gateway apps', function (): void {
    $launcher = file_get_contents(base_path('bin/orbit')) ?: '';

    expect($launcher)
        ->toContain('${ORBIT_REPO}/apps/gateway/artisan')
        ->toContain('${ORBIT_REPO}/artisan');
});
