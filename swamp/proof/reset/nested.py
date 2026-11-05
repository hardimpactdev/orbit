#!/usr/bin/env python3
"""`orbit-nested`: sync the author's checkout into the nested fleet, then run the CLI or a command in a nested VM.

    orbit-nested ARGS...            the candidate CLI against the nested Gateway
    orbit-nested exec NODE -- CMD   CMD as user orbit in the nested VM NODE (gateway, app-dev or app-prod)

The sync is the one the reset uses (`phases.sync_source`). When nothing changed it is one rsync per VM and nothing else.
When it moved files into the Gateway, the apply step follows (`guest.py candidate`); composer runs where a lock file changed.
"""
import fcntl
import json
import os
import subprocess
import sys
import time
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))
import phases
import ready

# Where a command starts: the Gateway app, the checkout of app-dev; app-prod holds no checkout.
DIRECTORIES = {'gateway': ready.CHECKOUT + '/apps/gateway', 'app-dev': ready.CHECKOUT, 'app-prod': '/home/orbit'}
USAGE = 'usage: orbit-nested ARGS... | orbit-nested exec {gateway|app-dev|app-prod} -- COMMAND...'


def sync(config):
    """Bring the nested checkouts level with the author's. A failed call is repeated in full by the next one."""
    topology = config['construction']
    instances = {role: topology['nodes'][role]['instance'] for role in ready.CHECKOUT_ROLES}
    with open(phases.NESTED_LOCK, 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        started = time.time()
        repair = phases.NESTED_PENDING.exists()
        phases.NESTED_PENDING.touch()
        timings = phases.sync_source(Path(config['candidate']), instances, reinstall=repair)
        synced = time.time()
        applying = repair or timings['gateway']['moved']
        if applying:
            facts = phases.guest_facts(topology, config['group'], config['generation'])
            phases.run_guest(topology, facts, 'candidate')
        phases.NESTED_PENDING.unlink()
        if applying or any(timing['moved'] for timing in timings.values()):
            composer = sorted({directory for timing in timings.values() for directory in timing['composer']})
            print(f'orbit-nested: synced in {synced - started:.1f} s, applied in {time.time() - synced:.1f} s' + (f', composer in {", ".join(composer)}' if composer else ''), file=sys.stderr)


def main(argv):
    command = None
    if argv[:1] == ['exec']:
        if len(argv) < 4 or argv[1] not in DIRECTORIES or argv[2] != '--':
            sys.exit(USAGE)
        node, command = argv[1], argv[3:]
    config = json.loads(phases.NESTED_CONFIG.read_text())
    try:
        sync(config)
    except (RuntimeError, OSError, subprocess.TimeoutExpired) as error:
        sys.exit(f'orbit-nested: the sync failed; the next call repeats it. {error}')
    if command is None:
        cli = str(Path(config['candidate']) / 'apps/cli/orbit')
        os.execv(cli, [cli, *argv])
    else:
        instance = config['construction']['nodes'][node]['instance']
        directory = DIRECTORIES[node]
        os.execvp('incus', ['incus', 'exec', instance, '--user', '1000', '--env', 'HOME=/home/orbit', '--cwd', directory, '--', *command])


if __name__ == '__main__':
    main(sys.argv[1:])
