"""Remove one selected orphan resource; Incus owns its operation wait."""
import json
import re
import subprocess
import sys


def query(args):
    result = subprocess.run(['/usr/bin/incus', *args], capture_output=True, text=True, timeout=180, check=True)
    return json.loads(result.stdout) if result.stdout.strip() else None


def remove(data):
    name, project, pool = data['name'], data['project'], data['pool']
    prefix = ['--project', project]
    if data['kind'] == 'instance':
        if not re.fullmatch(r'tvm-(?:[0-9]+|image-[0-9]+(?:-attempt-[0-9]+)?(?:-smoke)?)', name):
            raise ValueError()
        observe = [*prefix, 'list', '--format=json']
        delete = [*prefix, 'delete', '--force', '--', name]
    elif data['kind'] == 'volume':
        match = re.fullmatch(r'(tvm-[0-9]+)-topology-seed', name)
        if not match or any(row['name'] == match[1] for row in query([*prefix, 'list', '--format=json'])):
            raise ValueError()
        observe = [*prefix, 'storage', 'volume', 'list', pool, '--format=json']
        delete = [*prefix, 'storage', 'volume', 'delete', pool, name]
    else:
        raise ValueError()
    if any(row['name'] == name for row in query(observe)):
        query(delete)
    if any(row['name'] == name for row in query(observe)):
        raise ValueError()
    return {'name': name, 'deleted': True}


if __name__ == '__main__':
    try:
        print(json.dumps(remove(json.load(sys.stdin))))
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        print('{"error_code":"task_vm.host_command_failed"}')
        print('task_vm.host_command_failed', file=sys.stderr)
        raise SystemExit(1) from None
