"""Own one Linux fixture's children from launch through deadline cleanup."""
import ctypes
import json
import os
import signal
import subprocess
import sys
import time
from pathlib import Path


def children():
    return [int(pid) for pid in Path(f'/proc/self/task/{os.getpid()}/children').read_text().split()]


def signal_children(sig):
    # These are our unreaped children. No other thread reaps them, so their PIDs
    # cannot be reused between this snapshot and pidfd_open. The handle makes
    # signalling atomic even if a child exits before the signal is sent.
    for pid in children():
        fd = os.pidfd_open(pid)
        try:
            signal.pidfd_send_signal(fd, sig)
        except ProcessLookupError:
            pass
        finally:
            os.close(fd)


def main():
    seconds, receipt, *command = sys.argv[1:]
    libc = ctypes.CDLL(None, use_errno=True)
    if libc.prctl(36, 1, 0, 0, 0) != 0:  # PR_SET_CHILD_SUBREAPER
        raise OSError(ctypes.get_errno(), 'PR_SET_CHILD_SUBREAPER')
    leader = subprocess.Popen(command)
    code = None

    def reap():
        nonlocal code
        while True:
            try:
                pid, status = os.waitpid(-1, os.WNOHANG)
            except ChildProcessError:
                return False
            if pid == 0:
                return True
            if pid == leader.pid:
                code = os.waitstatus_to_exitcode(status)
                leader.returncode = code

    deadline = time.monotonic() + float(seconds)
    while reap():
        if time.monotonic() >= deadline:
            break
        time.sleep(0.01)
    else:
        Path(receipt).write_text(json.dumps({'timedOut': False}))
        return code if code is not None and code >= 0 else 1

    signal_children(signal.SIGTERM)
    time.sleep(1)
    kill_deadline = time.monotonic() + 2
    while reap():
        # Killing a parent adopts its children here, even when it forked in its
        # TERM handler or detached into another session. Repeat until reaped.
        signal_children(signal.SIGKILL)
        if time.monotonic() >= kill_deadline:
            raise RuntimeError('fixture children could not be reaped within 2s')
        time.sleep(0.01)
    Path(receipt).write_text(json.dumps({'timedOut': True}))
    return 124


if __name__ == '__main__':
    sys.exit(main())
