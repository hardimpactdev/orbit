<?php

declare(strict_types=1);

use App\E2E\Support\IncusHost;
use App\Services\E2E\IncusE2EImagePreparationOptions;
use App\Services\E2E\IncusE2EImagePreparer;
use Mockery as m;

afterEach(function (): void {
    m::close();
});

function baseOptions(bool $force = false, array $roles = ['blank']): IncusE2EImagePreparationOptions
{
    return new IncusE2EImagePreparationOptions(
        roles: $roles,
        force: $force,
        sourceImage: 'images:ubuntu/26.04/cloud',
        blankImageAlias: 'orbit-blank-ubuntu-26.04',
        bootstrapUser: 'provisioner',
        controlUser: 'control',
        installScriptPath: '/tmp/install-orbit',
        serverType: '2cpu/2GiB',
        cpus: 2,
        memory: '2GiB',
        timeoutSeconds: 600,
    );
}

it('returns dry-run result when force is false', function (): void {
    $host = m::mock(IncusHost::class);
    $preparer = new IncusE2EImagePreparer($host);

    $result = $preparer->prepare(baseOptions(force: false, roles: ['blank']));

    expect($result->images)->toHaveCount(1);
    expect($result->images[0])->toBe([
        'role' => 'blank',
        'alias' => 'orbit-blank-ubuntu-26.04',
        'action' => 'planned',
    ]);
});

it('throws for unknown roles when forced', function (): void {
    $host = m::mock(IncusHost::class);
    $preparer = new IncusE2EImagePreparer($host);

    expect(fn () => $preparer->prepare(baseOptions(force: true, roles: ['mystery'])))
        ->toThrow(RuntimeException::class, 'Unknown role [mystery].');
});

it('rejects retired role-specific roles when forced', function (): void {
    $host = m::mock(IncusHost::class);
    $preparer = new IncusE2EImagePreparer($host);

    expect(fn () => $preparer->prepare(baseOptions(force: true, roles: ['control'])))
        ->toThrow(RuntimeException::class, 'Unknown role [control].');
});
