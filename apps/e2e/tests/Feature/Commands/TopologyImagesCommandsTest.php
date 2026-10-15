<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeIncus;

it('imports one recipe base for every requested topology role without building', function (): void {
    $incus = new FakeIncus()->install();
    $directory = temporaryPath('orbit-command-import-', 5);
    mkdir($directory, 0700, true);
    $identity = 'sha256:'.str_repeat('a', 64);
    $fingerprint = str_repeat('b', 64);
    file_put_contents($directory.'/base.tar', json_encode(['fingerprint' => $fingerprint,
        'properties' => ['orbit.recipe' => $identity, 'orbit.profile' => 'base']], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/manifest.json', json_encode(['recipe_identity' => $identity,
        'images' => ['base' => ['fingerprint' => $fingerprint, 'type' => 'virtual-machine']]], JSON_THROW_ON_ERROR));

    expect(Artisan::call('topology-images:ensure', ['--nodes' => 'app-prod', '--recipe-images' => $directory.'/manifest.json', '--json' => true]))->toBe(0);
    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($output['built'])->toBe([]);
    expect($output['imported'])->toBe(['gateway' => $fingerprint, 'app-prod' => $fingerprint, 'operator' => $fingerprint]);
    expect($incus->callsOf('image', 'import'))->toHaveCount(1);
    expect($incus->callsOf('init'))->toBe([]);
    expect($incus->callsOf('publish'))->toBe([]);
    expect(Artisan::call('topology-images:status', ['--json' => true]))->toBe(0);
    expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['images'][0])->toMatchArray(['fingerprint' => $fingerprint, 'recipe_identity' => $identity]);
});

it('refuses missing imports and requires pre-recipe groups to drain without a builder fallback', function (?string $manifest): void {
    $incus = new FakeIncus()->install();
    expect(Artisan::call('topology-images:ensure', ['--recipe-images' => $manifest, '--json' => true]))->toBe(1);
    expect($incus->callsOf('init'))->toBe([]);
})->with([null, '/no-such-recipe/manifest.json']);

it('has no local image build command', function (): void {
    expect(Artisan::all())->not->toHaveKey('topology-images:build');
});
