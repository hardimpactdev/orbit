<?php

declare(strict_types=1);

use App\Infrastructure\Instances\NpmDependencyUpdateProgram;
use App\Infrastructure\Processes\ProcessCancelledException;
use Illuminate\Filesystem\Filesystem;
use Tests\Support\SubprocessFixture;
use Tests\Support\VitePlusUpdateFixture;

describe('npm update supervisor', function (): void {
    beforeEach(function (): void {
        $this->fixture = VitePlusUpdateFixture::npm();
    });

    it('stops delayed Vite+ hooks after cancellation', function (): void {
        $root = $this->fixture->directory();
        $vp = $this->fixture->vp(SubprocessFixture::DelayedHook);

        try {
            $started = microtime(true);
            $cancelled = false;

            try {
                $this->fixture->run(
                    $root,
                    $vp,
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
                $this->fail('The cancelled npm supervisor unexpectedly completed.');
            } catch (ProcessCancelledException) {
            }

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
    });

    it('runs vp update --no-save in a recorded root that contains a space', function (): void {
        $root = $this->fixture->directory(' spaced');
        $vp = $this->fixture->vp("printf done > done-marker\n");

        try {
            expect($root)->toContain(' ');
            $result = $this->fixture->run($root, $vp, '30', null, 30.0);
            expect($result->exitCode)->toBe(0);
            expect(is_file($root.'/done-marker'))->toBeTrue();
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('propagates a failed Vite+ exit status', function (): void {
        $root = $this->fixture->directory();
        $vp = $this->fixture->vp("exit 3\n");

        try {
            $result = $this->fixture->run($root, $vp, '30', null, 15.0);
            expect($result->exitCode)->toBe(3);
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('does not substitute a raw npm command', function (): void {
        expect(NpmDependencyUpdateProgram::render())->toContain('"$vp" update --no-save');
        expect(NpmDependencyUpdateProgram::render())->toContain('--no-save');
        expect(NpmDependencyUpdateProgram::render())->toContain('export VP_HOME=/opt/orbit/vite-plus');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('/usr/bin/npm');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('--latest');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('--dev');
        expect(NpmDependencyUpdateProgram::render())->not->toContain('--prod');
    });

    it('records vp update --no-save as the only child arguments', function (): void {
        $root = $this->fixture->directory();
        $vp = $this->fixture->vp("printf '%s\\n' \"\$*\" > argv-marker\n");

        try {
            $result = $this->fixture->run($root, $vp, '30', null, 15.0);
            expect($result->exitCode)->toBe(0);
            expect(trim((string) file_get_contents($root.'/argv-marker')))->toBe('update --no-save');
        } finally {
            new Filesystem()->deleteDirectory($root);
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });

    it('rejects a relative root and a missing Vite+ binary before starting', function (): void {
        $vp = $this->fixture->vp("exit 0\n");

        try {
            expect($this->fixture->run('relative/root', $vp, '30', null, 5.0)->exitCode)->toBe(1);
            expect($this->fixture->run(sys_get_temp_dir(), $vp.'-missing', '30', null, 5.0)->exitCode)->toBe(1);
        } finally {
            new Filesystem()->deleteDirectory(dirname($vp));
        }
    });
});
