<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\RoleImages;
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

it('resolves all roles to the same verified recipe base', function (): void {
    $incus = new FakeIncus()->install();
    $identity = 'sha256:'.str_repeat('a', 64);
    $base = $incus->addImage('orbit-e2e-base', properties: ['orbit.recipe' => $identity, 'orbit.profile' => 'base']);
    $images = new RoleImages(new IncusHost);

    expect($images->resolve(RoleImage::cases()))->toBe(['gateway' => $base, 'app-dev' => $base, 'app-prod' => $base, 'operator' => $base]);
    expect($images->status()[0])->toMatchArray(['state' => 'current', 'fingerprint' => $base, 'recipe_identity' => $identity]);
});

it('rejects legacy, missing, or invalid base images', function (string $defect): void {
    $incus = new FakeIncus()->install();
    if ($defect !== 'missing') {
        $incus->addImage($defect === 'legacy' ? 'orbit-e2e-gateway' : 'orbit-e2e-base', $defect === 'container' ? 'container' : 'virtual-machine', [
            'orbit.recipe' => $defect === 'recipe' ? 'invalid' : 'sha256:'.str_repeat('a', 64),
            'orbit.profile' => $defect === 'profile' ? 'gateway' : 'base',
        ]);
    }
    $images = new RoleImages(new IncusHost);

    expect(fn () => $images->resolve(RoleImage::cases()))->toThrow(RuntimeException::class, 'Pre-recipe groups must drain or be cancelled');
    expect($images->status()[0]['state'])->toBe('missing');
})->with(['legacy', 'missing', 'container', 'recipe', 'profile']);
