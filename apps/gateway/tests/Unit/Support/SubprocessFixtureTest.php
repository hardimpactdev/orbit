<?php

declare(strict_types=1);

use PHPUnit\Framework\ExpectationFailedException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;

it('waits for a complete readiness line and ignores later output', function (): void {
    $server = Mockery::mock(Process::class);
    $server->shouldReceive('getTimeout')->once()->andReturn(10.0);
    $server->shouldReceive('checkTimeout')->twice();
    $server->shouldReceive('isRunning')->andReturnTrue();
    $server->shouldReceive('getOutput')->andReturn('12', "12345\nlater output\n", "12345\nlater output\n");

    expect(SubprocessFixture::line($server))->toBe('12345');
});

it('reports stderr when a fixture exits with an incomplete readiness line', function (): void {
    $server = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "12"); fwrite(STDERR, "fixture bind failed"); exit(1);'], timeout: 10);
    $server->start();

    try {
        expect(fn () => SubprocessFixture::line($server))->toThrow(RuntimeException::class, 'fixture bind failed');
    } finally {
        $server->stop();
    }
});

it('rejects an empty readiness line from a running fixture', function (): void {
    $input = new InputStream;
    $server = new Process([PHP_BINARY, '-r', 'echo "\n"; fflush(STDOUT); fread(STDIN, 1);'], input: $input, timeout: 10);
    $server->start();

    try {
        expect(fn () => SubprocessFixture::line($server))->toThrow(RuntimeException::class, 'empty readiness line');
    } finally {
        $input->close();
        $server->stop();
    }
});

it('uses the process deadline when a fixture never becomes ready', function (): void {
    $input = new InputStream;
    $server = new Process([PHP_BINARY, '-r', 'fread(STDIN, 1);'], input: $input, timeout: 10);

    try {
        $server->start();
        $server->setTimeout(0.05);

        expect(fn () => SubprocessFixture::line($server))->toThrow(function (RuntimeException $exception): void {
            expect($exception::class)->toBe(RuntimeException::class);
            expect($exception->getMessage())->toContain('Fixture server timed out before readiness.');
            expect($exception->getPrevious())->toBeInstanceOf(ProcessTimedOutException::class);
        });
        expect($server->isRunning())->toBeFalse();
    } finally {
        $input->close();
        $server->stop();
    }
});

it('includes stdout and stderr when a fixture times out', function (): void {
    $server = Mockery::mock(Process::class);
    $timeout = new ProcessTimedOutException(new Process(['fixture'], timeout: 10), ProcessTimedOutException::TYPE_GENERAL);
    $server->shouldReceive('getTimeout')->once()->andReturn(10.0);
    $server->shouldReceive('checkTimeout')->once()->andThrow($timeout);
    $server->shouldReceive('getOutput')->once()->andReturn('fixture stdout');
    $server->shouldReceive('getErrorOutput')->once()->andReturn('fixture stderr');

    expect(fn () => SubprocessFixture::waitUntil($server, static fn (): bool => false))
        ->toThrow(function (RuntimeException $exception) use ($timeout): void {
            expect($exception::class)->toBe(RuntimeException::class);
            expect($exception->getMessage())->toContain('fixture stdout', 'fixture stderr');
            expect($exception->getPrevious())->toBe($timeout);
        });
});

it('rejects a process without a timeout before checking readiness', function (): void {
    $server = new Process([PHP_BINARY, '-r', 'exit(0);'], timeout: null);
    $checked = false;
    $condition = static function () use (&$checked): bool {
        $checked = true;

        return true;
    };

    expect(fn () => SubprocessFixture::waitUntil($server, $condition))
        ->toThrow(RuntimeException::class, 'Fixture server must have a timeout');
    expect($checked)->toBeFalse();
});

it('rejects executable fixture processes and accepts them only after exit', function (): void {
    $input = new InputStream;
    $server = new Process([PHP_BINARY, '-r', 'echo "ready\n"; fflush(STDOUT); fread(STDIN, 1);'], input: $input, timeout: 5);
    $server->start();

    try {
        expect(SubprocessFixture::line($server))->toBe('ready');
        $pid = $server->getPid() ?? throw new RuntimeException('Fixture has no PID.');
        expect(fn () => SubprocessFixture::assertExited($pid))
            ->toThrow(ExpectationFailedException::class, 'still executable');
        $input->write('x');
        $input->close();
        $server->wait();
        SubprocessFixture::assertExited($pid);
    } finally {
        $input->close();
        $server->stop();
    }
});

it('rejects empty or invalid fixture PID lists before querying the process table', function (array $pids): void {
    expect(fn () => SubprocessFixture::assertExited(...$pids))
        ->toThrow(ExpectationFailedException::class);
})->with(['empty' => [[]], 'invalid' => [[0]]]);
