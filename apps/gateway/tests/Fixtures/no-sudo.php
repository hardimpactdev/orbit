<?php

declare(strict_types=1);

use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Symfony\Component\Process\Process;
use Tests\Support\ForkedPythonPrograms;
use Tests\Support\TestToolchain;

beforeAll(function (): void {
    no_sudo_programs();
});

afterAll(function (): void {
    no_sudo_programs()->close();
});

function no_sudo_programs(): ForkedPythonPrograms
{
    static $programs = null;

    return $programs ??= new ForkedPythonPrograms;
}

it('keeps the current guard in a Python worker started before the test', function (bool $pinnedPath): void {
    $bootstrap = file_get_contents(__DIR__.'/../../resources/remote/bootstrap.py');
    $result = no_sudo_programs()->run($bootstrap, 'sudo-guard-proof', "import subprocess\nsubprocess.run(['sudo', '-n', 'true'])\0{}", $pinnedPath ? ['PATH' => '/usr/bin:/bin'] : []);

    expect($result->exitCode)->toBe(0);
})->with(['inherited PATH' => false, 'pinned PATH' => true]);

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

it('keeps the guard when a fixture clears the environment and pins its toolchain path', function (): void {
    (new Process(['env', '-i', 'PATH='.TestToolchain::path(), 'bash', '-c', 'sudo -n true || true']))->mustRun();
});
