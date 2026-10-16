<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

pest()->group('subprocess');

it('waits for real input consumption through stale output and an empty input queue', function (string $transition): void {
    $directory = sys_get_temp_dir().'/orbit-pty-driver-'.Str::uuid();
    new Filesystem()->ensureDirectoryExists($directory);
    $keys = ['a', "\x7f", "\x7f", 'd', "\r"];
    file_put_contents($directory.'/case.json', json_encode([
        'prompt' => 'fixture prompt',
        'keys' => array_map(base64_encode(...), $keys),
    ], JSON_THROW_ON_ERROR));
    file_put_contents($directory.'/reader.py', <<<'PYTHON'
        import base64
        import json
        import os
        import select
        import sys
        import termios
        import tty

        configuration = json.loads(open(sys.argv[1]).read())
        before = termios.tcgetattr(0)
        tty.setraw(0)
        try:
            os.write(1, b'fixture prompt')
            for key in configuration['keys']:
                if not select.select([0], [], [], 5)[0]:
                    raise RuntimeError('No key arrived')
                received = os.read(0, 1024)
                if received != base64.b64decode(key):
                    raise RuntimeError('Keys coalesced: ' + repr(received))
                os.write(1, b'\nkey consumed')
        finally:
            termios.tcsetattr(0, termios.TCSANOW, before)
        PYTHON);

    file_put_contents($directory.'/delayed-delivery.py', <<<'PYTHON'
        import builtins
        import fcntl
        import io
        import os
        import pty
        import runpy
        import select
        import signal
        import struct
        import subprocess
        import sys
        import termios

        # Model the delivery gap deterministically on a real PTY: write() has
        # returned, another render chunk arrives, but FIONREAD still reads zero.
        # Deliver the held key only after the driver has observed that snapshot.
        real_openpty, real_write = pty.openpty, os.write
        real_select, real_ioctl = select.select, fcntl.ioctl
        real_popen = subprocess.Popen
        real_check_output = subprocess.check_output
        real_waitpid, real_kill = os.waitpid, os.kill
        real_tcgetattr = termios.tcgetattr
        transition = sys.argv.pop(1)
        unsafe_mode, mode_child = False, False
        real_open = builtins.open
        terminal = None
        held_key = None
        phase = 0
        children = []

        def openpty():
            global terminal
            terminal = real_openpty()
            return terminal

        def waitpid(pid, options):
            global unsafe_mode, mode_child, transition
            result = real_waitpid(pid, options)
            if transition != 'raw' and options == os.WUNTRACED and os.WIFSTOPPED(result[1]):
                unsafe_mode = transition == 'canonical'
                mode_child = transition == 'child'
                transition = 'raw'
            return result

        def check_output(args, **kwargs):
            if mode_child and args == ['ps', '-A', '-o', 'ppid=']:
                return str(children[-1].pid) + '\n'
            return real_check_output(args, **kwargs)

        def open_file(path, *args, **kwargs):
            if mode_child and str(path).endswith('/children'):
                return io.StringIO('12345')
            return real_open(path, *args, **kwargs)

        def tcgetattr(fd):
            attributes = real_tcgetattr(fd)
            if unsafe_mode:
                attributes[3] |= termios.ICANON
            return attributes

        def kill(pid, sig):
            global unsafe_mode, mode_child
            if sig == signal.SIGCONT:
                unsafe_mode, mode_child = False, False
            return real_kill(pid, sig)

        def write(fd, data):
            global held_key, phase
            if terminal is not None and fd == terminal[0]:
                if unsafe_mode:
                    raise RuntimeError('Key sent during canonical mode transition')
                if mode_child:
                    raise RuntimeError('Key sent while a terminal-mode child is running')
                if held_key is not None:
                    raise RuntimeError('Next key sent before prior key consumption')
                held_key, phase = data, 0
                return len(data)
            return real_write(fd, data)

        def wait(readers, writers, errors, timeout):
            global held_key, phase
            if held_key is not None:
                if phase == 0:
                    real_write(terminal[1], b'\nframe continuation')
                    phase = 1
                elif phase == 2:
                    real_write(terminal[0], held_key)
                    held_key = None
            return real_select(readers, writers, errors, timeout)

        def ioctl(fd, request, argument):
            global phase
            if terminal is not None and fd == terminal[1] and request == termios.FIONREAD and held_key is not None:
                phase = 2
                return struct.pack('i', 0)
            return real_ioctl(fd, request, argument)

        def popen(*args, **kwargs):
            process = real_popen(*args, **kwargs)
            children.append(process)
            return process

        builtins.open = open_file
        os.waitpid, os.kill = waitpid, kill
        termios.tcgetattr = tcgetattr
        pty.openpty, os.write = openpty, write
        select.select, fcntl.ioctl = wait, ioctl
        subprocess.Popen = popen
        subprocess.check_output = check_output
        sys.argv = sys.argv[1:]
        try:
            runpy.run_path(sys.argv[0], run_name='__main__')
        finally:
            for process in children:
                if process.poll() is None:
                    process.kill()
                process.wait()
        PYTHON);

    try {
        $process = new Process([
            'python3', $directory.'/delayed-delivery.py', $transition, dirname(__DIR__, 2).'/Fixtures/Console/command-consent-pty.py',
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
})->with(['raw input' => 'raw', 'between prompts' => 'canonical', 'mode-changing child' => 'child']);
