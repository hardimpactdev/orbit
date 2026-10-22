<?php

declare(strict_types=1);

use App\Infrastructure\Processes\ProcessCancelledException;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\ComposerUpdateFixture;
use Tests\Support\SubprocessFixture;

describe('Composer update supervisor', function (): void {
    it('stops delayed Composer hooks after cancellation', function (): void {
        $root = ComposerUpdateFixture::directory();

        try {
            ComposerUpdateFixture::write($root, SubprocessFixture::DelayedHook);
            $started = microtime(true);
            $cancelled = false;

            try {
                ComposerUpdateFixture::run(
                    $root,
                    '30',
                    function () use (&$cancelled, $started, $root): bool {
                        if ($cancelled) {
                            return true;
                        }
                        if (is_file($root.'/start-marker') || microtime(true) - $started >= 2.0) {
                            $cancelled = true;

                            return true;
                        }

                        return false;
                    },
                    15.0,
                );
                $this->fail('The cancelled Composer supervisor unexpectedly completed.');
            } catch (ProcessCancelledException) {
            }

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

    it('updates a recorded Composer root that contains a space', function (): void {
        $root = ComposerUpdateFixture::directory(' spaced');

        try {
            ComposerUpdateFixture::write($root, 'printf start > start-marker');
            expect($root)->toContain(' ');

            $result = ComposerUpdateFixture::run($root, '30', null, 15.0);

            expect($result->exitCode)->toBe(0);
            expect(is_file($root.'/done-marker'))->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('rejects a relative root before starting Composer', function (): void {
        $result = ComposerUpdateFixture::run('relative/root', '30', null, 5.0);

        expect($result->exitCode)->toBe(1);
    });
});
