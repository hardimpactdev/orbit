<?php

declare(strict_types=1);

use App\Domain\Instances\VpEnvironment\InstanceVpEnvironmentMaterial;
use App\Domain\Tools\VpEnvironment;

it('retains live and candidate runtime references and retires only releases actually removed', function (): void {
    [, , , $instance] = deployment_api_fixture();
    $selected = $instance->checkout_path;
    $candidate = $instance->production_home.'/releases/candidate';
    $old = new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '22.18.0', 'npm', '10.9.0');
    $new = new VpEnvironment('/opt/orbit/vite-plus', '0.3.3', '24.22.0', 'npm', '11.12.0');
    $instance->update(['vp_environment' => $old->record('active')]);
    $material = app(InstanceVpEnvironmentMaterial::class);

    $material->recordReference($instance, $candidate, $new, 'checking');
    $material->recordReference($instance, $candidate, $new, 'active');

    $record = $instance->refresh()->getAttribute('vp_environment');
    expect($record['status'])->toBe('active')
        ->and($record['previous']['checkout_path'])->toBe($selected)
        ->and($record['previous']['node_version'])->toBe('22.18.0')
        ->and($record['previous']['previous'])->toBeNull();
    expect($material->stores($instance))->toBe(['/opt/orbit/vite-plus']);
    expect(array_column($material->references($instance->node_id, '/opt/orbit/vite-plus'), 'node_version'))->toBe(['24.22.0', '22.18.0']);

    $material->retire($instance, [$selected]);
    expect($instance->refresh()->getAttribute('vp_environment')['previous'])->not->toBeNull();
    $instance->update(['checkout_path' => $candidate]);
    $material->retire($instance, [$selected]);
    expect($instance->refresh()->getAttribute('vp_environment')['previous'])->toBeNull();
});
