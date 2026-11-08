"""Cross-process fleet and Node locks, plus the restored-batch barrier xdist does not provide."""
import contextlib
import fcntl
import json
import subprocess
import time
from concurrent.futures import ThreadPoolExecutor
from pathlib import Path

ROLES = ('gateway', 'app-dev', 'app-prod')


@contextlib.contextmanager
def lock(path, shared=False):
    with Path(path).open('a') as stream:
        fcntl.flock(stream, fcntl.LOCK_SH if shared else fcntl.LOCK_EX)
        try:
            yield
        finally:
            fcntl.flock(stream, fcntl.LOCK_UN)


@contextlib.contextmanager
def case_lock(root, node, exclusive):
    with lock(root / 'fleet.lock', shared=not exclusive):
        with lock(root / f'{node}.lock'):
            yield


def restore(fleet):
    """Restore only the three nested guests in the allocated task VM. Never access a host fleet.

    Provision has already applied this checkout before taking base. No production code changes
    are part of this spike, so restore needs no code migration or playbook application.
    """
    started = time.monotonic()

    def one(role):
        guest = 'nested-' + role
        for argv, accepted in [(['incus', 'snapshot', 'restore', guest, 'base'], [0]),
                               (['incus', 'start', guest], [0, 1]),
                               (['incus', 'exec', guest, '--', 'cloud-init', 'status', '--wait'], [0, 2])]:
            done = subprocess.run(argv, capture_output=True, text=True, timeout=65)
            if done.returncode not in accepted:
                raise RuntimeError(f'{role}: restore failed: {done.stderr[-300:]}')
    with ThreadPoolExecutor(3) as pool:
        list(pool.map(one, ROLES))
    deadline = time.monotonic() + 120
    while time.monotonic() < deadline:
        result = fleet.cli(['doctor'])
        if result['rc'] == 0 and (result['json'] or {}).get('healthy') is True:
            return time.monotonic() - started
        time.sleep(1)
    raise RuntimeError('restored fleet did not reach a healthy Doctor in 120 s')


def batch_ready(root, batch, prior, discover, prepare):
    deadline = time.monotonic() + 2400
    while any(not (root / 'done' / case).exists() for case in prior):
        if time.monotonic() > deadline:
            raise RuntimeError('preceding batch did not complete; no result is a pass')
        time.sleep(1)
    with lock(root / 'batch.lock'):
        ready = root / (batch + '.json')
        if not ready.exists():
            fleet = discover()
            seconds = restore(fleet)
            prepare(fleet, batch)
            ready.write_text(json.dumps({'nodes': fleet.nodes, 'restore_seconds': seconds}))
        return json.loads(ready.read_text())['nodes']
