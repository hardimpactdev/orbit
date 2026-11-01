"""Observe one admitted Incus VM until started; the caller supplies a literal timeout."""
import json
import subprocess
import sys
import time


def observe(project, name):
    rows = json.loads(subprocess.check_output([
        '/usr/bin/sudo', '-n', '--', '/usr/bin/incus', '--project', project,
        'list', '--format', 'json', '--', name,
    ], text=True, stderr=subprocess.PIPE))
    if not isinstance(rows, list) or len(rows) != 1:
        raise SystemExit('task_vm.provider_identity_invalid: expected exactly one Incus VM')
    row = rows[0]
    if row.get('name') != name or row.get('type') != 'virtual-machine':
        raise SystemExit('task_vm.provider_identity_invalid: Incus returned another instance')
    return row


def wait_started(project, name):
    while True:
        row = observe(project, name)
        state = row['status']
        if state == 'Running':
            return [{'name': row['name'], 'type': row['type'], 'status': state}]
        if state not in ('Stopped', 'Starting'):
            raise SystemExit('task_vm.not_settled: Incus reached an unsupported start state')
        time.sleep(1)


def main():
    try:
        print(json.dumps(wait_started(sys.argv[1], sys.argv[2])))
    except (subprocess.CalledProcessError, OSError):
        print('task_vm.provider_observation_failed: Incus state read failed', file=sys.stderr)
        return 1
    except (ValueError, KeyError, IndexError, TypeError):
        print('task_vm.provider_observation_invalid: invalid Incus state receipt', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
