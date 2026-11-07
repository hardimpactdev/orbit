"""Renew the swamp role's authenticated connections through Swamp's native drain."""
import datetime
import fcntl
import json
import pwd
import subprocess
import sys
import time
from pathlib import Path

RENEW_AFTER = 3 * 60 * 60
STOP_TIMEOUT = 75 * 60
ENROLL_TIMEOUT = 120
ACCEPTING = {'idle', 'busy'}


class Runtime:
    def __init__(self, user, node_id):
        home = pwd.getpwnam(user).pw_dir
        self.prefix = ['runuser', '-u', user, '--', '/usr/bin/env', f'HOME={home}',
                       f'SWAMP_HOME={home}/.swamp', f'SWAMP_CONFIG_DIR={home}/.config/swamp',
                       f'SWAMP_API_KEY_FILE={home}/.config/swamp/api-key',
                       'SWAMP_NO_UPDATE_CHECK=1', 'SWAMP_NO_TELEMETRY=1', '/usr/local/bin/swamp', 'worker']
        self.remote = ['--server', 'http://127.0.0.1:9418', '--token-file',
                       f'{home}/.config/swamp/serve-driver-token', '--json']
        self.names = {slot: f'swamp-role-{node_id}-{slot}' for slot in ('a', 'b')}

    @staticmethod
    def run(argv, timeout):
        # Never put CLI output (or credential material) in the journal on failure.
        result = subprocess.run(argv, capture_output=True, text=True, timeout=timeout, check=False)
        if result.returncode:
            raise RuntimeError(f'{argv[0]} failed with exit {result.returncode}')
        return result.stdout

    def workers(self):
        result = json.loads(self.run([*self.prefix, 'list', '--all', *self.remote], 60))
        return {slot: next((w for w in result['workers'] if w['name'] == name), None)
                for slot, name in self.names.items()}

    def verify(self, slot):
        result = json.loads(self.run([*self.prefix, 'verify', self.names[slot], *self.remote], 125))
        if (result['total'] != 1 or result['passed'] != 1 or result['failed'] != 0
                or result['workers'][0]['name'] != self.names[slot]):
            raise RuntimeError(f'{slot} is not connected and accepting dispatches')

    def restart(self, slot):
        unit = f'orbit-swamp-worker@{slot}.service'
        value = self.run(['busctl', 'get-property', 'org.freedesktop.systemd1',
                         '/org/freedesktop/systemd1/unit/orbit_2dswamp_2dworker_40' + slot + '_2eservice',
                         'org.freedesktop.systemd1.Service', 'TimeoutStopUSec'], 30).split()
        if len(value) != 2 or value[0] != 't' or int(value[1]) < STOP_TIMEOUT * 1_000_000:
            raise RuntimeError(f'{slot} does not have the required 75 minute stop timeout')
        print(f'Renewing {slot}: native SIGTERM drain', flush=True)
        self.run(['systemctl', 'restart', unit], STOP_TIMEOUT + 120)


def accepting(worker):
    return worker is not None and worker.get('status') in ACCEPTING and bool(worker.get('instanceUuid'))


def due(worker, now):
    if not accepting(worker):
        return True
    enrolled = datetime.datetime.fromisoformat(worker['enrolledAt']).timestamp()
    return now < enrolled or now - enrolled >= RENEW_AFTER


def renew(runtime, now=time.time, monotonic=time.monotonic, sleep=time.sleep):
    for slot, peer in (('a', 'b'), ('b', 'a')):
        workers = runtime.workers()
        if not due(workers[slot], now()):
            continue
        if not accepting(workers[peer]):
            raise RuntimeError(f'Cannot renew {slot}: {peer} is not accepting dispatches')
        # Unlike list's durable records, verify probes the live scheduling/data path.
        runtime.verify(peer)
        old_instance = workers[slot]['instanceUuid'] if workers[slot] else None
        runtime.restart(slot)
        deadline = monotonic() + ENROLL_TIMEOUT
        while monotonic() < deadline:
            workers = runtime.workers()
            if not accepting(workers[peer]):
                raise RuntimeError(f'Peer {peer} stopped accepting during renewal of {slot}')
            if accepting(workers[slot]) and workers[slot]['instanceUuid'] != old_instance:
                runtime.verify(slot)
                print(f'Renewed {slot}: new instance enrolled and accepting dispatches', flush=True)
                break
            sleep(2)
        else:
            raise RuntimeError(f'{slot} did not re-enroll; leaving {peer} accepting')


def main():
    # systemd serializes timer starts; the lock also excludes a manual invocation.
    with Path('/run/lock/orbit-swamp-worker-renewal.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        renew(Runtime(sys.argv[1], int(sys.argv[2])))


if __name__ == '__main__':
    try:
        main()
    except RuntimeError as error:
        print(f'Worker renewal failed: {error}', file=sys.stderr)
        sys.exit(1)
    except (ValueError, KeyError, IndexError, OSError, subprocess.SubprocessError) as error:
        # Exceptions from JSON parsing or subprocess timeouts can contain captured output.
        print(f'Worker renewal failed ({type(error).__name__}); peer is not intentionally stopped', file=sys.stderr)
        sys.exit(1)
