<?php

declare(strict_types=1);

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\Support\SubprocessFixture;

pest()->group('subprocess');

it('waits for a complete readiness line and ignores later output', function (): void {
    $server = Mockery::mock(Process::class);
    $server->shouldReceive('checkTimeout')->twice();
    $server->shouldReceive('isRunning')->andReturnTrue();
    $server->shouldReceive('getOutput')->andReturn('12', "12345\nlater output\n", "12345\nlater output\n");

    expect(SubprocessFixture::line($server))->toBe('12345');
});

it('reports stderr when a fixture exits with an incomplete readiness line', function (): void {
    $server = new Process([PHP_BINARY, '-r', 'fwrite(STDOUT, "12"); fwrite(STDERR, "fixture bind failed"); exit(1);']);
    $server->start();

    try {
        expect(fn () => SubprocessFixture::line($server))->toThrow(RuntimeException::class, 'fixture bind failed');
    } finally {
        $server->stop();
    }
});

it('rejects an empty readiness line from a running fixture', function (): void {
    $input = new InputStream;
    $server = new Process([PHP_BINARY, '-r', 'echo "\n"; fflush(STDOUT); fread(STDIN, 1);'], input: $input);
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
    $server = new Process([PHP_BINARY, '-r', 'fread(STDIN, 1);'], input: $input, timeout: 0.05);
    $server->start();

    try {
        expect(fn () => SubprocessFixture::line($server))->toThrow(ProcessTimedOutException::class);
    } finally {
        $input->close();
        $server->stop();
    }
});
