"""The release Swamp load's two gaps, run as the serve account after each checkout (docs/cli/gateway/swamp-load.mmd).

`grants <repo>` renders serve's grants: the Gateway's from its manifest, whose schedule producer marks serve's format
can't hold, and serve's others. No official behavior renders grants.
`release-restart <commit>` restarts serve after draining when the commit changes a manifest's additionalFiles
(https://swamp-club.com/lab/4282). Remove that mode when Lab #4282 is fixed.

The swamp role's runtime runs as official cfgmgmt steps (node/role_swamp_runtime_workflow.ts). This file keeps its
name and manifest entries: a changed additionalFiles list restarts serve at the release, which needs a quiet window.
"""

import json
import os
import stat
import subprocess
import sys

GRANTS_MANIFEST = 'config/gateway-workflow-grants.json'
GRANTS_FILE = 'grants/orbit-gateway.yaml'
SERVE_GRANTS = 'config/serve-grants.json'
SERVE_GRANTS_FILE = 'grants/orbit-serve.yaml'


def done(value):
    print(json.dumps(value))
    sys.exit(0)


def publish(path, content, mode, gid=0, uid=0):
    try:
        current = os.stat(path)
        with open(path, 'rb') as handle:
            if (handle.read() == content and stat.S_IMODE(current.st_mode) == mode and current.st_gid == gid
                    and current.st_uid == uid):
                return False
    except FileNotFoundError:
        pass
    if not os.path.isdir(os.path.dirname(path)):
        os.makedirs(os.path.dirname(path), mode=0o755)
        if os.geteuid() == 0:
            os.chown(os.path.dirname(path), uid, gid)
    temporary = path + '.orbit-new'
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_TRUNC | os.O_NOFOLLOW, 0o600)
    with os.fdopen(fd, 'wb') as handle:
        handle.write(content)
    if os.geteuid() == 0:
        os.chown(temporary, uid, gid)
    os.chmod(temporary, mode)
    os.replace(temporary, path)
    return True


def grants(repo):
    """The manifest's grants for its principal, in serve's grants file format. JSON is valid YAML."""
    with open(os.path.join(repo, GRANTS_MANIFEST)) as handle:
        manifest = json.load(handle)
    result = [{'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'],
               'resource': 'vault:' + entry['vault'], 'condition': 'key.matches(' + json.dumps(entry['key_pattern']) + ')'}
              for entry in manifest['vaults']]
    result += [{'subject': manifest['principal'], 'effect': 'allow', 'actions': entry['actions'],
                'resource': 'workflow:' + entry['workflow']} for entry in manifest['workflows']]
    return (json.dumps({'grants': result}, indent=2) + '\n').encode()


def serve_grants(repo):
    """Serve's other grants, kept in the repository in serve's grants file format."""
    with open(os.path.join(repo, SERVE_GRANTS)) as handle:
        return (json.dumps({'grants': json.load(handle)['grants']}, indent=2) + '\n').encode()


def additional_files(commit):
    """Every local extension manifest's additionalFiles at a commit, by manifest path."""
    import yaml
    listed = subprocess.run(['git', 'ls-tree', '-r', '--name-only', commit, '--', 'swamp/extensions'],
                            capture_output=True, text=True, check=True).stdout.split()
    files = {}
    for path in (p for p in listed if p.endswith('/manifest.yaml')):
        shown = subprocess.run(['git', 'show', commit + ':' + path], capture_output=True, text=True, check=True).stdout
        files[path] = sorted((yaml.safe_load(shown) or {}).get('additionalFiles') or [])
    return files


def added(before, after):
    """Manifests whose additionalFiles at `after` list an entry that `before` did not."""
    return sorted(p for p in after if set(after[p]) - set(before.get(p, [])))


def release_restart(commit):
    """Restart serve when the commit lists an additionalFiles entry that serve did not start with.

    Swamp's extension reload keeps each manifest's additionalFiles from serve's start, so a new shipped file reaches
    workers only after a restart: https://swamp-club.com/lab/4282. Remove this mode when that is fixed. A removed
    entry needs no restart: serve fingerprints a listed file that is gone as missing and serves nothing for it. Each
    restart also resets serve's hourly bookkeeping GC (https://swamp-club.com/lab/4287). Serve's own shutdown drain
    waits for runs in flight; the release waits for the new serve and loads again.
    """
    started = subprocess.run(['/usr/bin/systemctl', 'show', 'orbit-swamp-serve.service', '-p', 'ExecMainStartTimestamp',
                              '--value'], capture_output=True, text=True, check=True).stdout.strip()
    # The checkout moves only in the release load, so its reflog names the commit serve started from.
    served = subprocess.run(['git', 'rev-parse', '--verify', 'HEAD@{' + started + '}'], capture_output=True, text=True,
                            check=True).stdout.strip()
    changed = added(additional_files(served), additional_files(commit))
    if not changed:
        done({})
    # Delayed, so this step reports first.
    subprocess.run(['/usr/bin/sudo', '-n', '/usr/bin/systemd-run', '--no-block', '--on-active=5s',
                    '--unit=orbit-swamp-serve-release-' + commit[:12], '/usr/bin/systemctl', 'restart',
                    'orbit-swamp-serve.service'], capture_output=True, check=True)
    done({'restart': {'reason': 'additional_files', 'served': served, 'manifests': changed,
                      'issue': 'https://swamp-club.com/lab/4282'}})


if len(sys.argv) == 3 and sys.argv[1] == 'release-restart':
    release_restart(sys.argv[2])
if len(sys.argv) == 3 and sys.argv[1] == 'grants':
    publish(os.path.join(sys.argv[2], GRANTS_FILE), grants(sys.argv[2]), 0o640, os.getgid(), os.getuid())
    publish(os.path.join(sys.argv[2], SERVE_GRANTS_FILE), serve_grants(sys.argv[2]), 0o640, os.getgid(), os.getuid())
    sys.exit(0)
sys.exit(1)
