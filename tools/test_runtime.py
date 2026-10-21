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
PR_SET_CHILD_SUBREAPER = 36
PR_SET_PDEATHSIG = 1


def children():
    """Linux adopts orphaned grandchildren into this run, including children that call setsid."""
    if sys.platform != 'linux':
        return []
    return [int(pid) for pid in Path(f'/proc/self/task/{os.getpid()}/children').read_text().split()]


def reap(leader):
    """Reap adopted orphans during the run; Popen alone owns the command's exit status."""
    for pid in children():
        if pid == leader:
            continue
        try:
            os.waitpid(pid, os.WNOHANG)
        except ChildProcessError:
            pass


def signal_group(group, sig):
    try:
        os.killpg(group, sig)
    except ProcessLookupError:
        pass
    except PermissionError:
        return False
    return True


def stop_children(process):
    """Stop only this run's process group and adopted children; wait before removing their files."""
    for sig in (signal.SIGTERM, signal.SIGKILL):
        deadline = time.monotonic() + 1
        while True:
            signal_group(process.pid, sig)
            for pid in children():
                try:
                    os.kill(pid, sig)
                except (ProcessLookupError, PermissionError):
                    pass
            process.poll()
            reap(process.pid)
            try:
                os.killpg(process.pid, 0)
                group_alive = True
            except ProcessLookupError:
                group_alive = False
            except PermissionError:
                group_alive = True
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
    parent = os.getppid()
    if sys.platform == 'linux':
        # Keep browser servers owned by this wrapper even after their PHP parent exits.
        if ctypes.CDLL(None, use_errno=True).prctl(PR_SET_CHILD_SUBREAPER, 1, 0, 0, 0) != 0:
            raise OSError(ctypes.get_errno(), 'Could not own test descendants')
    root = tempfile.gettempdir()
    # Match Gateway's bare-bootstrap escape from Orbit's role-private TMPDIRs.
    if re.search(r'/(?:orbit/tmp/(?:agent|check)-[0-9]+|orbit-check-[0-9]+-[^/]+)$', root):
        root = '/tmp'
    directory = tempfile.mkdtemp(prefix='run-', dir=root)
    os.chmod(directory, 0o755)  # Cross-user Gateway fixtures must traverse this directory.
    environment = {**os.environ, 'TMPDIR': directory, 'TMP': directory, 'TEMP': directory,
                   'ORBIT_TEST_TMPDIR': directory}
    process = None
    stopped = None
    pending = b''
    interrupted = None

    def forward(sig, _frame):
        nonlocal interrupted
        if interrupted is None:
            interrupted = sig
            forwarded = sig
        else:
            forwarded = signal.SIGKILL
        if process is not None and stopped is not True:
            signal_group(process.pid, forwarded)
            if forwarded == signal.SIGKILL:
                for pid in children():
                    try:
                        os.kill(pid, forwarded)
                    except (ProcessLookupError, PermissionError):
                        pass

    previous = {sig: signal.signal(sig, forward) for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP)}
    try:
        if sys.platform == 'linux':
            # Cancel this run if the agent or shell that owns the launcher disappears.
            if ctypes.CDLL(None, use_errno=True).prctl(PR_SET_PDEATHSIG, signal.SIGTERM, 0, 0, 0) != 0:
                raise OSError(ctypes.get_errno(), 'Could not bind test lifetime to its parent')
            if os.getppid() != parent:
                return 143
        if interrupted is not None:
            return 128 + interrupted
        process = subprocess.Popen(command, env=environment, stdout=subprocess.PIPE,
                                   stderr=subprocess.STDOUT, start_new_session=True)
        with selectors.DefaultSelector() as selector:
            selector.register(process.stdout, selectors.EVENT_READ)
            while process.poll() is None or selector.get_map():
                reap(process.pid)
                if process.returncode is not None and stopped is None:
                    # Stop inherited pipe writers as soon as the command exits, even if they keep logging.
                    stopped = stop_children(process)
                    if not stopped:
                        break
                for key, _mask in selector.select(0.05):
                    chunk = os.read(key.fd, 8192)
                    if not chunk:
                        selector.unregister(key.fileobj)
                        continue
                    clean = ANSI.sub(b'', pending + chunk)
                    match = PARTIAL_ANSI.search(clean)
                    pending = clean[match.start():] if match else b''
                    sys.stdout.buffer.write(clean[:match.start()] if match else clean)
                    sys.stdout.buffer.flush()
        code = process.returncode
        sys.stdout.buffer.write(pending)
        sys.stdout.buffer.flush()
        return 128 + interrupted if interrupted else (128 - code if code < 0 else code)
    finally:
        # Once cleanup proves the group empty, never signal its now-reusable PGID again.
        if stopped is None:
            stopped = process is None or stop_children(process)
        if stopped:
            shutil.rmtree(directory)
        else:
            print(f'Test processes still hold {directory}; preserved for inspection.', file=sys.stderr)
        if process is not None:
            process.stdout.close()
        for sig, handler in previous.items():
            signal.signal(sig, handler)


if __name__ == '__main__':
    sys.exit(main(sys.argv[1:]))
