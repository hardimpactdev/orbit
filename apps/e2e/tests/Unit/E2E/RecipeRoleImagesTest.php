<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\RecipeRoleImages;
use App\E2E\Value\RoleImage;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;
use Tests\Support\FakeIncus;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

it('imports role archives by fingerprint, validates their recipe and points the composition aliases without builders', function (): void {
    $incus = new FakeIncus()->install();
    $directory = temporaryPath('orbit-recipe-import-', 5);
    mkdir($directory, 0700, true);
    $identity = 'sha256:'.str_repeat('a', 64);
    $fingerprint = str_repeat('b', 64);
    file_put_contents($directory.'/base.tar', json_encode(['fingerprint' => $fingerprint,
        'properties' => ['orbit.recipe' => $identity, 'orbit.profile' => 'base']], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/manifest.json', json_encode(['recipe_identity' => $identity,
        'images' => ['base' => ['fingerprint' => $fingerprint, 'type' => 'virtual-machine']]], JSON_THROW_ON_ERROR));
    $importer = new RecipeRoleImages(new IncusHost);

    $imported = $importer->import($directory.'/manifest.json', RoleImage::cases());
    expect($imported)->toHaveCount(4);
    expect($incus->callsOf('image', 'import'))->toHaveCount(1);
    expect($incus->callsOf('init'))->toBe([]);
    expect($incus->images[$imported['operator']]['type'])->toBe('virtual-machine');
    expect($incus->images[$imported['gateway']]['aliases'])->toContain('orbit-e2e-base');
    $importer->import($directory.'/manifest.json', RoleImage::cases());
    expect($incus->callsOf('image', 'import'))->toHaveCount(1);
});

it('rejects imported images with another recipe, profile or type', function (string $defect): void {
    $incus = new FakeIncus()->install();
    $identity = 'sha256:'.str_repeat('a', 64);
    $fingerprint = $incus->addImage('imported-gateway', $defect === 'type' ? 'container' : 'virtual-machine', [
        'orbit.recipe' => $defect === 'recipe' ? 'sha256:'.str_repeat('b', 64) : $identity,
        'orbit.profile' => $defect === 'profile' ? 'app-dev' : 'base',
    ]);
    $directory = temporaryPath('orbit-recipe-invalid-', 5);
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/manifest.json', json_encode(['recipe_identity' => $identity,
        'images' => ['base' => ['fingerprint' => $fingerprint, 'type' => 'virtual-machine']]], JSON_THROW_ON_ERROR));

    expect(fn () => new RecipeRoleImages(new IncusHost)->import($directory.'/manifest.json', [RoleImage::Gateway]))
        ->toThrow(RuntimeException::class, 'does not match the claimed recipe');
    expect($incus->callsOf('image', 'alias'))->toBe([]);
})->with(['recipe', 'profile', 'type']);

it('fails closed for a missing manifest or archive', function (bool $manifestExists): void {
    $incus = new FakeIncus()->install();
    $directory = temporaryPath('orbit-recipe-missing-', 5);
    mkdir($directory, 0700, true);
    if ($manifestExists) {
        file_put_contents($directory.'/manifest.json', json_encode(['recipe_identity' => 'sha256:'.str_repeat('a', 64),
            'images' => ['base' => ['fingerprint' => str_repeat('b', 64), 'type' => 'virtual-machine']]], JSON_THROW_ON_ERROR));
    }

    expect(fn () => new RecipeRoleImages(new IncusHost)->import($directory.'/manifest.json', [RoleImage::Gateway]))->toThrow(RuntimeException::class);
    expect($incus->callsOf('init'))->toBe([]);
})->with([true, false]);
