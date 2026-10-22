<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Tests\Support\ComposerUpdateFixture;
use Tests\Support\SubprocessFixture;
use Tests\Support\VitePlusUpdateFixture;

pest()->group('subprocess');

it('stops delayed Composer hooks when the remote deadline expires', function (): void {
    $root = ComposerUpdateFixture::directory();

    try {
        ComposerUpdateFixture::write($root, SubprocessFixture::DelayedHook);
        // Two seconds leave at least one full second for Composer to start its hook.
        $result = ComposerUpdateFixture::run($root, '2');

        expect($result->exitCode)->toBe(124);
        expect(is_file($root.'/start-marker'))->toBeTrue();
        SubprocessFixture::assertExited(
            (int) file_get_contents($root.'/hook-pid'),
            (int) file_get_contents($root.'/child-pid'),
        );
        expect(is_file($root.'/later-marker'))->toBeFalse();
        expect(is_file($root.'/done-marker'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);
    }
});

it('stops delayed Vite+ hooks when the remote deadline expires', function (VitePlusUpdateFixture $fixture): void {
    $root = $fixture->directory();
    $vp = $fixture->vp(SubprocessFixture::DelayedHook);

    try {
        $result = $fixture->run($root, $vp, '2');

        expect($result->exitCode)->toBe(124);
        expect(is_file($root.'/start-marker'))->toBeTrue();
        SubprocessFixture::assertExited(
            (int) file_get_contents($root.'/hook-pid'),
            (int) file_get_contents($root.'/child-pid'),
        );
        expect(is_file($root.'/later-marker'))->toBeFalse();
    } finally {
        new Filesystem()->deleteDirectory($root);
        new Filesystem()->deleteDirectory(dirname($vp));
    }
})->with([
    'Bun' => fn (): VitePlusUpdateFixture => VitePlusUpdateFixture::bun(),
    'npm' => fn (): VitePlusUpdateFixture => VitePlusUpdateFixture::npm(),
]);
