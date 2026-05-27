<?php

declare(strict_types=1);

it('keeps root gateway forwarding helper scripts available', function (): void {
    expect(repo_path('bin/orbit-gateway-artisan'))->toBeFile()
        ->and(repo_path('bin/orbit-gateway-pest'))->toBeFile()
        ->and(repo_path('bin/orbit-gateway-vendor-bin'))->toBeFile()
        ->and(repo_path('bin/orbit-cli-artisan'))->toBeFile()
        ->and(repo_path('bin/orbit-cli-pest'))->toBeFile()
        ->and(repo_path('artisan'))->not->toBeFile()
        ->and(repo_path('phpunit.xml'))->not->toBeFile();
});

it('routes root composer scripts through app-aware helpers', function (): void {
    $composer = json_decode(
        (string) file_get_contents(repo_path('composer.json')),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer['scripts']['docs-lint'])
        ->each->toContain('bin/orbit-docs-artisan')
        ->and($composer['scripts']['test'][1])->toContain('bin/orbit-gateway-artisan config:clear')
        ->and($composer['scripts']['test'][2])->toContain('bin/orbit-gateway-pest')
        ->and($composer['scripts']['test'][3])->toContain('bin/orbit-cli-pest')
        ->and($composer['scripts']['test'][4])->toContain('bin/orbit-docs-pest')
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
        ->and($composer['scripts']['format'][0])->toContain('bin/orbit-gateway-vendor-bin pint')
        ->and($composer['scripts']['format'])->toContain('cd apps/cli && vendor/bin/pint --config ../../pint.json')
        ->and($composer['scripts']['format'])->toContain('cd apps/docs && vendor/bin/pint --config ../../pint.json')
        ->and($composer['scripts']['rector'])->toContain('bin/orbit-gateway-vendor-bin rector')
        ->and($composer)->not->toHaveKeys(['autoload', 'autoload-dev']);
});

it('keeps public orbit launcher pointed at the gateway app only', function (): void {
    $launcher = file_get_contents(repo_path('bin/orbit')) ?: '';

    expect($launcher)
        ->toContain('${ORBIT_REPO}/apps/gateway/artisan')
        ->toContain('${ORBIT_REPO}/apps/gateway/.env')
        ->not->toContain('${ORBIT_REPO}/artisan');
});
