"""The publish check that runs IN a task copy, as root, before any token exists (docs/reference/tasks.md#the-copy-check).

It reads the secret values that live in the copy, compares the objects the publish would push against them, and prints one JSON
line. The line names a path, a line number and the NAME of a secret, never a value: no value leaves this process. Git runs as the
`agent` user, so root reads only Orbit's own files; the publish compares the object set it read against the bundle it pushes.

Usage: python3 -I -c <this file> <request JSON>. The request holds `head`, `start`, `branch`, `nested` and `proposed_body_path`.
"""

import fnmatch
import glob
import hashlib
import json
import os
import re
import subprocess
import sys
import time

# Values shorter than this match by chance; they are skipped and counted.
MINIMUM = 16
# At most this many hits and paths go into the result; the count says how many more there were.
LIMIT = 20
WORKSPACE = '/srv/workspace'
WORKFLOWS = '.github/workflows/'
# A private key block with a real body: a fake marker or a short test stub is no key.
PRIVATE_KEY = re.compile(
    rb'-----BEGIN ((?:[A-Z0-9]+ )*)PRIVATE KEY-----\r?\n'
    rb'(?:[A-Za-z0-9-]+: [^\r\n]*\r?\n)*(?:\r?\n)?'
    rb'((?:[A-Za-z0-9+/=]{16,}\r?\n){2,})'
    rb'-----END \1PRIVATE KEY-----')
PRIVATE_KEY_BODY = 100

# Where the copy's secrets live, by name. `env` reads each value of KEY=VALUE lines; `file` the whole file and each line; `wireguard`
# the PrivateKey and PresharedKey values. Paths of the inner machines are under their root file systems in the inner storage pool.
OUTER = [
    ('club-key', '/run/orbit-club/swamp-club-api-key', 'file'),
    ('operator-wireguard', '/etc/wireguard/*.key', 'file'),
    ('operator-wireguard', '/etc/wireguard/*.conf', 'wireguard'),
]
INNER = [
    ('env', '/var/lib/orbit-checkout-state/apps/gateway/.env', 'env'),
    ('env', '/home/orbit/orbit/apps/gateway/.env', 'env'),
    ('swamp-token', '/etc/orbit/swamp-role-worker/*/*.token', 'file'),
    ('swamp-token', '/home/*/.config/swamp/*token*', 'file'),
    ('orbit-token', '/etc/orbit/**/*token*', 'file'),
    ('wireguard', '/etc/wireguard/*.key', 'file'),
    ('wireguard', '/etc/wireguard/*.conf', 'wireguard'),
]
INNER_ROOTS = '/var/lib/incus/storage-pools/*/containers/*nested-*/rootfs'


class Unchecked(Exception):
    """The check could not run; its message is a fixed public reason, never a value."""


def env_values(raw):
    """Each value of a KEY=VALUE line, also in a commented-out line, with quotes and an inline comment removed."""
    out = []
    for line in raw.decode('utf-8', 'replace').splitlines():
        line = line.strip()
        if line.startswith('#'):
            line = line[1:].strip()
        match = re.match(r'(?:export\s+)?[A-Za-z_][A-Za-z0-9_.-]*\s*=\s*(.*)$', line)
        if not match:
            continue
        key = line.split('=', 1)[0].replace('export', '').strip()
        value = match.group(1)
        if value[:1] in ('"', "'"):
            end = value.find(value[0], 1)
            value = value[1:end] if end > 0 else value[1:]
        else:
            value = re.sub(r'\s+#.*$', '', value)
        out.append((key, value))
    return out


def read_values(name, path, kind):
    """(name, value) pairs of one source file, with the KEY in the name of an env value."""
    try:
        with open(path, 'rb') as stream:
            raw = stream.read(1 << 20)
    except OSError:
        return None
    if kind == 'env':
        return [(name + ':' + key, value) for key, value in env_values(raw)]
    text = raw.decode('utf-8', 'replace')
    if kind == 'wireguard':
        return [(name + ':' + key, value.strip()) for key, value in re.findall(r'(?m)^\s*(PrivateKey|PresharedKey)\s*=\s*(\S+)', text)]
    return [(name, text.strip())] + [(name, line.strip()) for line in text.splitlines()]


def collect(root='/', nested=False):
    """The secret values of the copy by name, the names read, and the count of short values skipped per name."""
    values, read, short = {}, set(), {}

    def take(name, pattern, kind, base):
        for path in sorted(glob.glob(base.rstrip('/') + pattern, recursive=True)):
            if not os.path.isfile(path):
                continue
            pairs = read_values(name + ':' + path[len(base.rstrip('/')):], path, kind)
            if pairs is None:
                continue
            read.add(name)
            for label, value in pairs:
                if not value:
                    continue
                if len(value.encode()) < MINIMUM:
                    short[name] = short.get(name, 0) + 1
                    continue
                values.setdefault(value.encode(), label)

    for name, pattern, kind in OUTER:
        take(name, pattern, kind, root)
    inner = sorted(glob.glob(root.rstrip('/') + INNER_ROOTS))
    if nested and not inner:
        raise Unchecked('the nested copy shows no inner machine')
    for machine_root in inner:
        machine = os.path.basename(os.path.dirname(machine_root)).split('_')[-1]
        for name, pattern, kind in INNER:
            take(machine + ':' + name, pattern, kind, machine_root)
    return values, sorted(read), short


class Git:
    """Git as the agent user, on the copy's checkout. Root never runs Git on a tree the agent controls."""

    def __init__(self, prefix, workspace=WORKSPACE):
        self.argv = prefix + ['/usr/bin/git', '--no-replace-objects', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                              '-c', 'safe.directory=' + workspace, '-C', workspace]

    env = {'PATH': '/usr/local/bin:/usr/bin:/bin', 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1',
           'GIT_NO_REPLACE_OBJECTS': '1', 'GIT_TERMINAL_PROMPT': '0', 'HOME': '/nonexistent', 'LC_ALL': 'C'}

    def run(self, *args, data=None):
        done = subprocess.run(self.argv + list(args), input=data, capture_output=True, env=self.env, timeout=120)
        if done.returncode != 0:
            raise Unchecked('git ' + args[0] + ' failed')
        return done.stdout


def unpublished(git, start, values):
    """The values the start tree does not hold. A value the repository already shows, such as a path or a public key, is no secret
    of the copy. The patterns reach Git on its stdin only: no argument, file or environment holds them."""
    if not values:
        return values
    done = subprocess.run(git.argv + ['grep', '-h', '-o', '-a', '-F', '-f', '-', start, '--'], input=b''.join(value + b'\n' for value in values),
                          capture_output=True, env=git.env, timeout=120)
    if done.returncode not in (0, 1):
        raise Unchecked('git grep failed')
    public = set(done.stdout.splitlines())
    return {value: name for value, name in values.items() if value not in public}


def objects(git, start, head):
    """Every object new in start..head with its path, as the publish's own range reads it, in rev-list order."""
    rows = []
    for line in git.run('rev-list', '--objects', head, '--not', start, '--').decode('utf-8', 'surrogateescape').splitlines():
        object_id, _, path = line.partition(' ')
        rows.append((object_id, path))
    return rows


def contents(git, rows):
    """(id, type, bytes) of each row, from one cat-file batch."""
    data = git.run('cat-file', '--batch', data=''.join(object_id + '\n' for object_id, _ in rows).encode())
    position = 0
    for object_id, _ in rows:
        end = data.index(b'\n', position)
        header = data[position:end].split(b' ')
        if len(header) != 3 or header[0].decode() != object_id:
            raise Unchecked('cat-file returned another object')
        size = int(header[2])
        yield object_id, header[1].decode(), data[end + 1:end + 1 + size]
        position = end + 1 + size + 1


def named(path, values):
    """A path is named only when it holds no value."""
    raw = path.encode('utf-8', 'surrogateescape')
    return 'a path that holds a value' if any(value in raw or value in raw.replace(b'/', b'') for value in values) else path


def scan(git, start, head, values, body=None):
    """The hits in the range and the proposed body, the sorted object digest and the object count."""
    rows = objects(git, start, head)
    hits = []
    for (object_id, path), (_, kind, data) in zip(rows, contents(git, rows)):
        where = named(path, values)
        for value, name in values.items():
            index = data.find(value)
            if index < 0:
                continue
            if kind == 'commit':
                hits.append({'path': 'commit ' + object_id, 'secret': name})
            elif kind == 'tree':
                hits.append({'path': 'a file name in ' + (where or '.'), 'secret': name})
            else:
                hits.append({'path': where, 'line': data.count(b'\n', 0, index) + 1, 'secret': name})
        if kind in ('blob', 'commit'):
            for block in PRIVATE_KEY.finditer(data):
                if len(re.sub(rb'\s', b'', block.group(2))) >= PRIVATE_KEY_BODY:
                    hits.append({'path': where if kind == 'blob' else 'commit ' + object_id, 'line': data.count(b'\n', 0, block.start()) + 1,
                                 'secret': 'private-key-block'})
    if body is not None:
        for value, name in values.items():
            index = body.find(value)
            if index >= 0:
                hits.append({'path': 'the proposed pull request body', 'line': body.count(b'\n', 0, index) + 1, 'secret': name})
    ids = sorted({object_id for object_id, _ in rows})
    digest = hashlib.sha256(''.join(object_id + '\n' for object_id in ids).encode()).hexdigest()
    return hits, digest, len(ids)


def workflow_changes(git, start, head):
    """The workflow paths that commits new to the repository change: not in start, not on any remote branch the copy holds.

    A merge counts a path only when it differs from every parent, so a sync of the default branch brings no hit of its own. This check
    serves the person: GitHub refuses such a push of a token without `workflows`, whatever the copy's refs say.
    """
    commits = git.run('rev-list', head, '--not', start, '--remotes', '--').decode().split()
    paths = set()
    for commit in commits:
        listed = git.run('diff-tree', '-r', '-c', '--root', '--no-commit-id', '--name-only', commit, '--', WORKFLOWS)
        paths.update(line for line in listed.decode('utf-8', 'surrogateescape').splitlines() if fnmatch.fnmatch(line, WORKFLOWS + '*'))
    return sorted(paths)


def proposed_body(git_prefix, path, workspace=WORKSPACE):
    """The copy's proposed body, read as agent: a link the agent planted reads nothing of root's."""
    if not path:
        return None
    done = subprocess.run(git_prefix + ['/usr/bin/head', '-c', '65537', '--', workspace + '/' + path], capture_output=True, timeout=10,
                          env={'PATH': '/usr/bin:/bin'})
    return done.stdout if done.returncode == 0 else None


def check(request, root='/', prefix=None, workspace=WORKSPACE):
    started = time.monotonic()
    prefix = ['/usr/sbin/runuser', '-u', 'agent', '--'] if prefix is None else prefix
    git = Git(prefix, workspace)
    values, read, short = collect(root, bool(request.get('nested')))
    values = unpublished(git, request['start'], values)
    hits, digest, count = scan(git, request['start'], request['head'], values, proposed_body(prefix, request.get('proposed_body_path'), workspace))
    workflows = workflow_changes(git, request['start'], request['head'])
    return {'scan': {
        'secrets': hits[:LIMIT], 'secrets_more': max(0, len(hits) - LIMIT),
        'workflows': workflows[:LIMIT], 'workflows_more': max(0, len(workflows) - LIMIT),
        'sources': read, 'values': len(values), 'short_skipped': dict(sorted(short.items())),
        'objects': count, 'objects_sha256': digest, 'seconds': round(time.monotonic() - started, 2),
    }, 'changed': False}


def main(argv):
    try:
        request = json.loads(argv[1])
        if not re.fullmatch(r'[0-9a-f]{40}([0-9a-f]{24})?', request['head']) or not re.fullmatch(r'[0-9a-f]{40}([0-9a-f]{24})?', request['start']):
            raise Unchecked('the request names no commit')
        print(json.dumps(check(request)))
        return 0
    except Unchecked as error:
        print(json.dumps({'error_code': 'tasks.publish_secret_unchecked', 'reason': str(error)}))
    except Exception as error:  # noqa: BLE001 - the class name only: an exception message can hold bytes of a value.
        print(json.dumps({'error_code': 'tasks.publish_secret_unchecked', 'reason': type(error).__name__}))
    return 1


if __name__ == '__main__':
    sys.exit(main(sys.argv))
