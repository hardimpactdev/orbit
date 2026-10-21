"""Own one test run's temporary files and processes before PHP or ParaTest starts."""
import ctypes
import os
import re
import selectors
import shutil
import signal
import subprocess
import sys
import tempfile
import time
from pathlib import Path

ANSI = re.compile(rb'\x1b\[[\d;?]*[a-zA-Z]')
PARTIAL_ANSI = re.compile(rb'\x1b(?:\[[\d;?]*)?$')


def children():
    """Linux adopts orphaned grandchildren into this run, including children that call setsid."""
    if sys.platform != 'linux':
        return []
    return [int(pid) for pid in Path(f'/proc/self/task/{os.getpid()}/children').read_text().split()]


def reap():
    while True:
        try:
            if os.waitpid(-1, os.WNOHANG)[0] == 0:
                return
        except ChildProcessError:
            return


def signal_group(group, sig):
    try:
        os.killpg(group, sig)
    except ProcessLookupError:
        pass


def stop_children(group):
    """Stop only this run's process group and adopted children; wait before removing their files."""
    for sig in (signal.SIGTERM, signal.SIGKILL):
        deadline = time.monotonic() + 1
        while True:
            signal_group(group, sig)
            for pid in children():
                try:
                    os.kill(pid, sig)
                except ProcessLookupError:
                    pass
            reap()
            try:
                os.killpg(group, 0)
                group_alive = True
            except ProcessLookupError:
                group_alive = False
            if not group_alive and not children():
                return True
            if time.monotonic() >= deadline:
                break
            time.sleep(0.02)
    return False


def main(command):
    if not command:
        print('usage: bin/pest-plain command [arguments]', file=sys.stderr)
        return 2
    if sys.platform == 'linux':
        # Keep browser servers owned by this wrapper even after their PHP parent exits.
        if ctypes.CDLL(None, use_errno=True).prctl(36, 1, 0, 0, 0) != 0:
            raise OSError(ctypes.get_errno(), 'Could not own test descendants')
    parent = os.getppid()
    root = Path(__file__).resolve().parent.parent / '.test-runtime'
    root.mkdir(mode=0o755, exist_ok=True)
    directory = tempfile.mkdtemp(prefix='run-', dir=root)
    os.chmod(directory, 0o755)  # Cross-user Gateway fixtures must traverse this directory.
    environment = {**os.environ, 'TMPDIR': directory, 'TMP': directory, 'TEMP': directory,
                   'ORBIT_TEST_TMPDIR': directory}
    process = None
    pending = b''
    interrupted = None
    deadline = None

    def forward(sig, _frame):
        nonlocal interrupted, deadline
        interrupted = sig
        deadline = time.monotonic() + 1
        if process is not None:
            signal_group(process.pid, sig)

    previous = {sig: signal.signal(sig, forward) for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP)}
    try:
        if sys.platform == 'linux':
            # Cancel this run if the agent or shell that owns the launcher disappears.
            if ctypes.CDLL(None, use_errno=True).prctl(1, signal.SIGTERM, 0, 0, 0) != 0:
                raise OSError(ctypes.get_errno(), 'Could not bind test lifetime to its parent')
            if os.getppid() != parent:
                return 143
        process = subprocess.Popen(command, env=environment, stdout=subprocess.PIPE,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        stopped = False
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while selector.get_map():
                if process.poll() is not None and not stopped:
                    # Stop inherited pipe writers as soon as the command exits, even if they keep logging.
                    stopped = stop_children(process.pid)
                    if not stopped:
                        break
                if deadline is not None and time.monotonic() >= deadline:
                    signal_group(process.pid, signal.SIGKILL)
                    deadline = None
                events = selector.select(0.05)
                for key, _mask in events:
                    chunk = os.read(key.fd, 8192)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        continue
                    clean = ANSI.sub(b'', pending + chunk)
                    match = PARTIAL_ANSI.search(clean)
                    pending = clean[match.start():] if match else b''
                    sys.stdout.buffer.write(clean[:match.start()] if match else clean)
                    sys.stdout.buffer.flush()
        while process.poll() is None:
            if deadline is not None and time.monotonic() >= deadline:
                signal_group(process.pid, signal.SIGKILL)
                deadline = None
            time.sleep(0.02)
        code = process.returncode
        sys.stdout.buffer.write(pending)
        sys.stdout.buffer.flush()
        return 128 + interrupted if interrupted else (128 - code if code < 0 else code)
    finally:
        if process is None or stop_children(process.pid):
            shutil.rmtree(directory)
        else:
            print(f'Test processes still hold {directory}; preserved for inspection.', file=sys.stderr)
        if process is not None:
            process.stdout.close()
        for sig, handler in previous.items():
            signal.signal(sig, handler)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
