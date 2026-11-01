"""Read one Incus project's inventory and the host's public stray inventory."""
import json
import subprocess
import sys
from urllib.parse import parse_qs, unquote, urlsplit


def run(args):
    result = subprocess.run(['/usr/bin/incus', *args], capture_output=True, text=True, timeout=60, check=True)
    return json.loads(result.stdout) if result.stdout.strip() else None


def inventory(data):
    project, pool = data['project'], data['pool']
    subprocess.run(['/usr/bin/incus', 'project', 'show', '--', project], capture_output=True, timeout=60, check=True)
    instances = run(['--project', project, 'list', '--format=json'])
    volumes = run(['--project', project, 'storage', 'volume', 'list', pool, '--format=json'])
    def resource(url, prefix):
        parsed = urlsplit(url)
        if parsed.netloc or parsed.scheme or not parsed.path.startswith(prefix):
            raise ValueError()
        return {'project': parse_qs(parsed.query).get('project', ['default'])[0], 'name': unquote(parsed.path[len(prefix):])}
    all_instances = [resource(url, '/1.0/instances/') for url in run(['query', '/1.0/instances?all-projects=true'])]
    all_volumes = []
    for url in run(['query', '/1.0/storage-pools']):
        storage_pool = resource(url, '/1.0/storage-pools/')['name']
        prefix = '/1.0/storage-pools/' + storage_pool + '/volumes/custom/'
        all_volumes.extend({**resource(url, prefix), 'pool': storage_pool} for url in run(['query', prefix[:-1] + '?all-projects=true']))
    images = run(['--project', project, 'image', 'list', '--format=json'])
    return {'instances': [{'name': row['name']} for row in instances],
            'volumes': [{'name': row['name'], 'type': row['type']} for row in volumes],
            'inventory': {'instances': all_instances, 'volumes': all_volumes, 'images': [row['fingerprint'] for row in images]}}


if __name__ == '__main__':
    try:
        print(json.dumps(inventory(json.load(sys.stdin))))
    except (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        print('{"error_code":"task_vm.host_command_failed"}')
        print('task_vm.host_command_failed', file=sys.stderr)
        raise SystemExit(1) from None
