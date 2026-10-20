<?php

declare(strict_types=1);

use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Symfony\Component\Process\Process;
use Tests\Support\TestToolchain;

describe('setup', function (): void {
    beforeEach(function (): void {
        (new Process(['sudo', '-n', 'true']))->run();
    });

    it('rejects sudo from setup', function (): void {
        expect(true)->toBeTrue();
    });
});

describe('cleanup', function (): void {
    afterEach(function (): void {
        (new Process(['sudo', '-n', 'true']))->run();
    });

    it('rejects sudo from cleanup', function (): void {
        expect(true)->toBeTrue();
    });
});

it('rejects an ignored direct sudo failure', function (): void {
    (new Process(['sudo', '-n', 'true']))->run();
});

it('rejects sudo in a child shell even when the shell succeeds', function (): void {
    (new Process(['bash', '-c', 'sudo -n true || true']))->mustRun();
});

it('rejects ignored sudo through the native runner', function (): void {
    new NativeProcessRunner()->run(new ProcessInvocation(['sudo', '-n', 'true']));
});

it('keeps the guard when a fixture pins its toolchain path', function (): void {
    (new Process(['bash', '-c', 'sudo -n true || true'], env: ['PATH' => TestToolchain::path()]))->mustRun();
});
