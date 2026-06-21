<?php

declare(strict_types=1);

use Orbit\Core\Progress\LiveRepaintOutput;
use Symfony\Component\Console\Output\StreamOutput;

it('supports decorated output even when the stream is not a tty', function (): void {
    $stream = fopen('php://memory', 'w+');

    expect($stream)->toBeResource()
        ->and(LiveRepaintOutput::supports(new StreamOutput($stream, decorated: true)))->toBeTrue();
});

it('rejects undecorated output when the stream is not a tty', function (): void {
    $stream = fopen('php://memory', 'w+');

    expect($stream)->toBeResource()
        ->and(LiveRepaintOutput::supports(new StreamOutput($stream, decorated: false)))->toBeFalse();
});

it('keeps live repaint support narrowed to stream tty detection and console decoration', function (): void {
    $source = file_get_contents(__DIR__.'/../../src/Progress/LiveRepaintOutput.php');

    expect($source)->toContain('stream_isatty')
        ->and($source)->toContain('isDecorated')
        ->and($source)->not->toContain('posix_isatty');
});
