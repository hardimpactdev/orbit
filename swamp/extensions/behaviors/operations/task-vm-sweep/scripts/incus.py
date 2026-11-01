"""One bounded Incus sweep phase; public intent arrives unchanged on stdin."""
import json
import re
import subprocess
import sys
import time
from urllib.parse import parse_qs, unquote, urlsplit

NAME = re.compile(r'[A-Za-z0-9][A-Za-z0-9._-]{0,253}')
BUILD = re.compile(r'tvm-image-([0-9]+)(?:-attempt-[0-9]+)?(?:-smoke)?')
ERRORS = (OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError)


def active(intent):
    if time.time() >= intent['deadline_at']:
        raise ValueError('expired sweep')


def rows(raw, volume=False):
    if not isinstance(raw, list) or len(raw) > 4096:
        raise ValueError('unreadable inventory')
    for row in raw:
        if not isinstance(row, dict) or not isinstance(row.get('name'), str) or not NAME.fullmatch(row['name']):
            raise ValueError('unreadable inventory')
        if volume and not isinstance(row.get('type'), str):
            raise ValueError('unreadable volume inventory')
    return raw


def query(args):
    result = subprocess.run(['/usr/bin/incus', *args], capture_output=True, text=True, timeout=180, check=True)
    if args[:2] == ['project', 'show']:
        return None
    return json.loads(result.stdout) if result.stdout.strip() else None


def excluded(scope, name):
    return scope['project'] + '/' + name in scope['operator_owned'] or scope['project'] + '/*' in scope['operator_owned']


def orphan_vm(scope, name):
    match = re.fullmatch(r'tvm-([0-9]+)', name)
    return bool(match and int(match[1]) <= scope['max_task_vm_id'] and name not in scope['live_names'])


def orphan_instance(scope, name):
    if excluded(scope, name) or name in scope['live_names']:
        return False
    if orphan_vm(scope, name):
        return True
    match = BUILD.fullmatch(name)
    return bool(match and int(match[1]) <= scope['max_build_id'] and int(match[1]) not in scope['protected_build_ids']
                and (int(match[1]) in scope['retired_build_ids'] or int(match[1]) not in scope['recorded_build_ids']))


def report_strays(intent, run=query):
    scope = intent['scope']
    def resource(url, prefix):
        parsed = urlsplit(url)
        if parsed.netloc or parsed.scheme or not parsed.path.startswith(prefix):
            raise ValueError('unreadable global inventory')
        return parse_qs(parsed.query).get('project', ['default'])[0], unquote(parsed.path[len(prefix):])
    def operator(project, name):
        return (intent['ci_runner'] and project == 'orbit-ci' or project + '/' + name in scope['operator_owned']
                or project + '/*' in scope['operator_owned'])
    for url in run(['query', '/1.0/instances?all-projects=true']):
        project, name = resource(url, '/1.0/instances/')
        build = BUILD.fullmatch(name)
        if not operator(project, name) and not (project == scope['project'] and (re.fullmatch(r'tvm-[0-9]+', name) or build and int(build[1]) in scope['protected_build_ids'])):
            print(json.dumps({'warning': 'unowned_instance', 'project': project, 'name': name}), file=sys.stderr)
    fingerprints = [row['fingerprint'] for row in run(['--project', scope['project'], 'image', 'list', '--format=json'])]
    for url in run(['query', '/1.0/storage-pools']):
        _, pool = resource(url, '/1.0/storage-pools/')
        prefix = '/1.0/storage-pools/' + pool + '/volumes/custom/'
        for volume in run(['query', prefix[:-1] + '?all-projects=true']):
            project, name = resource(volume, prefix)
            seed = re.fullmatch(r'orbit-topology-seed-([a-f0-9]{12})(?:-new)?', name)
            owned = project == scope['project'] and pool == scope['pool'] and (re.fullmatch(r'tvm-[0-9]+-topology-seed', name) or seed and any(fp.startswith(seed[1]) for fp in fingerprints))
            if not operator(project, name) and not owned:
                print(json.dumps({'warning': 'unowned_volume', 'project': project, 'pool': pool, 'name': name}), file=sys.stderr)
    for identity in intent['stray_vm_ids']:
        print(json.dumps({'warning': 'task_vm_without_group', 'task_vm_id': identity}), file=sys.stderr)


def sweep(intent, run=query):
    active(intent)
    scope = intent['scope']
    for field in ('project', 'pool'):
        if not NAME.fullmatch(scope[field]):
            raise ValueError('invalid scope')
    prefix = ['--project', scope['project']]
    def instances():
        return [row['name'] for row in rows(run([*prefix, 'list', '--format=json']))]
    def volumes():
        return rows(run([*prefix, 'storage', 'volume', 'list', scope['pool'], '--format=json']), True)
    run(['project', 'show', '--', scope['project']])
    observed = instances()
    volumes()  # All required inventories must succeed before the first write.
    deleted = []
    for name in observed:
        if not orphan_instance(scope, name):
            continue
        active(intent)
        present = name in instances()
        active(intent)
        if present:
            run([*prefix, 'delete', '--force', '--', name])
        if name in instances():
            raise ValueError('delete refused')
        deleted.append(name)
    for row in volumes():
        match = re.fullmatch(r'(tvm-[0-9]+)-topology-seed', row['name'])
        if row['type'] != 'custom' or not match or excluded(scope, row['name']) or not orphan_vm(scope, match[1]):
            continue
        active(intent)
        present = match[1] in instances()
        active(intent)
        if present:
            continue
        run([*prefix, 'storage', 'volume', 'delete', scope['pool'], row['name']])
        if any(item['name'] == row['name'] for item in volumes()):
            raise ValueError('delete refused')
        deleted.append(row['name'])
    report_strays(intent, run)
    return {'ready': True, 'observed_names': instances(), 'deleted_names': deleted}


if __name__ == '__main__':
    try:
        result = sweep(json.load(sys.stdin))
    except ERRORS:
        result = {'ready': False, 'error_code': 'task_vm.host_command_failed'}
        print('task_vm.host_command_failed', file=sys.stderr)
    print(json.dumps(result))
    raise SystemExit(0 if result['ready'] else 1)
