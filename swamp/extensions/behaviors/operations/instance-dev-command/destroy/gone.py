"""Check it is gone: the checkout, its removal quarantine, each Process unit and container are absent.

argv[1] is the public destroy intent. Runs as root and only reads. Prints {"ready": true} or
{"error_code": "instance.removal_incomplete"}.
"""
import json
import subprocess
import sys
from pathlib import Path


def present(argv):
    return subprocess.run(argv, stdin=subprocess.DEVNULL, capture_output=True, timeout=30, check=False).returncode == 0


def active(unit):
    state = subprocess.run(['/usr/bin/systemctl', 'is-active', unit], stdin=subprocess.DEVNULL, capture_output=True, text=True,
                           timeout=30, check=False).stdout.strip()
    return state in ('active', 'activating', 'reloading', 'deactivating')


def gone(intent):
    for member in intent['members']:
        checkout = Path(member['checkout_path'])
        quarantine = checkout.with_name(checkout.name + '.orbit-removing-' + intent['removal_id'])
        if checkout.exists() or checkout.is_symlink() or quarantine.exists():
            return False
    for process in intent['processes']:
        if process['runtime'] == 'systemd':
            if Path('/etc/systemd/system', process['name']).exists() or active(process['name']):
                return False
        elif present(['/usr/bin/docker', '--host=unix:///var/run/docker.sock', 'container', 'inspect', process['name']]):
            return False
    return True


if __name__ == '__main__':
    try:
        ready = gone(json.loads(sys.argv[1]))
    except (ValueError, OSError, KeyError, TypeError, subprocess.SubprocessError):
        ready = False
    print(json.dumps({'ready': True} if ready else {'error_code': 'instance.removal_incomplete'}))
    sys.exit(0 if ready else 1)
