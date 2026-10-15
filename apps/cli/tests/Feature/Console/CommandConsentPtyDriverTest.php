<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('keeps each key separate while a prompt frame continues before reading input', function (int $delayedRead): void {
    $directory = sys_get_temp_dir().'/orbit-pty-driver-'.Str::uuid();
    new Filesystem()->ensureDirectoryExists($directory);
    $keys = ['a', "\x7f", "\x7f", 'd', "\r"];
    file_put_contents($directory.'/case.json', json_encode([
        'prompt' => 'fixture prompt',
        'keys' => array_map(base64_encode(...), $keys),
        'delayed_read' => $delayedRead,
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/reader.py', <<<'PYTHON'
        import base64
        import fcntl
        import json
        import os
        import select
        import struct
        import sys
        import termios
        import time
        import tty

        configuration = json.loads(open(sys.argv[1]).read())
        before = termios.tcgetattr(0)
        tty.setraw(0)
        try:
            os.write(1, b'fixture prompt')
            for index, key in enumerate(configuration['keys']):
                if not select.select([0], [], [], 5)[0]:
                    raise RuntimeError('No key arrived')
                if index == configuration['delayed_read']:
                    deadline = time.monotonic() + 0.2
                    while time.monotonic() < deadline:
                        os.write(1, b'\nframe continuation')
                        pending = struct.unpack('i', fcntl.ioctl(0, termios.FIONREAD, struct.pack('i', 0)))[0]
                        if pending > 1:
                            break
                        select.select([], [], [], 0.005)
                received = os.read(0, 1024)
                if received != base64.b64decode(key):
                    raise RuntimeError('Keys coalesced: ' + repr(received))
                os.write(1, b'\nkey consumed')
        finally:
            termios.tcsetattr(0, termios.TCSANOW, before)
        PYTHON);

    try {
        $process = new Process([
            'python3', dirname(__DIR__, 2).'/Fixtures/Console/command-consent-pty.py',
            'python3', $directory.'/reader.py', $directory.'/case.json',
        ], timeout: 15);
        $process->mustRun();
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($result['status'])->toBe(0, (string) base64_decode($result['raw'], strict: true))
            ->and($result['prompt_seen'])->toBeTrue()
            ->and($result['keys_remaining'])->toBe(0)
            ->and($result['restored'])->toBeTrue();
    } finally {
        new Filesystem()->deleteDirectory($directory);
    }
})->with(['first prompt frame' => 0, 'later prompt frame' => 1]);
