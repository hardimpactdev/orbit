<?php

declare(strict_types=1);

use App\Services\E2E\IncusE2EImagePreparationOptions;
use App\Services\E2E\IncusE2EImagePreparer;
use Mockery as m;
use Tests\E2E\Support\IncusHost;

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

it('returns dry-run plans for every requested role', function (): void {
    $host = m::mock(IncusHost::class);
    $preparer = new IncusE2EImagePreparer($host);

    $result = $preparer->prepare(baseOptions(force: false, roles: ['blank', 'control', 'gateway', 'devapp', 'prodapp']));

    expect($result->images)->toHaveCount(5);
    expect(array_column($result->images, 'role'))->toBe(['blank', 'control', 'gateway', 'devapp', 'prodapp']);
    expect(array_column($result->images, 'alias'))->toBe([
        'orbit-blank-ubuntu-26.04',
        'orbit-ready-control',
        'orbit-ready-gateway',
        'orbit-ready-devapp',
        'orbit-ready-prodapp',
    ]);
    expect(array_unique(array_column($result->images, 'action')))->toBe(['planned']);
});

it('throws for non-blank roles when forced', function (string $role): void {
    $host = m::mock(IncusHost::class);
    $preparer = new IncusE2EImagePreparer($host);

    expect(fn () => $preparer->prepare(baseOptions(force: true, roles: [$role])))
        ->toThrow(RuntimeException::class, "Role [{$role}] image build is not yet implemented.");
})->with([
    'control',
    'gateway',
    'devapp',
    'prodapp',
]);
