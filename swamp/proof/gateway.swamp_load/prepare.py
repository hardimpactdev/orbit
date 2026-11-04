"""Give the disposable proof serve the production release checkout and loopback API paths."""
import importlib.util
import json
import os
import pwd
import shutil
import subprocess
import sys
from pathlib import Path

source = Path.cwd()
state = Path('/home/orbit/.orbit/swamp-proof')
account = pwd.getpwnam('orbit')


def run(argv):
    result = subprocess.run(argv, text=True, capture_output=True, timeout=60)
    if result.returncode:
        sys.stderr.write(result.stdout + result.stderr)
        result.check_returncode()
    return result.stdout.strip()


def link(path, target):
    path.parent.mkdir(parents=True, exist_ok=True)
    if not path.exists() and not path.is_symlink():
        path.symlink_to(target)


# The release loader already needs jq and Python YAML on production 209; minimal has no release package set.
if shutil.which('jq') is None or importlib.util.find_spec('yaml') is None:
    run(['apt-get', 'update'])
    run(['apt-get', 'install', '-y', '--no-install-recommends', 'jq', 'python3-yaml'])

link(Path('/home/orbit/apps/orbit-monorepo-staging/checkout'), source)
link(Path('/home/orbit/.local/bin/swamp'), Path('/usr/local/bin/swamp'))
link(Path('/home/orbit/.swamp'), state / 'home')
link(Path('/home/orbit/.config/swamp/api-key'), Path('/home/orbit/.config/swamp/club-api-key'))
link(Path('/home/orbit/.config/swamp/serve-driver-token'), state / 'credentials/operator')
# Production's load uses serve's loopback plaintext listener, which the topology runs as the swamp role does (v17).
# Prepare the target release's immutable manifest files as the real release builder does.
sha = run(['runuser', '-u', 'orbit', '--', 'git', 'rev-parse', 'HEAD'])
release = source.parent / 'releases' / sha[:12]
release.mkdir(parents=True, exist_ok=True)
(release / 'REVISION').write_text(sha + '\n')
(release / 'swamp/config').mkdir(parents=True, exist_ok=True)
for name in ('gateway-workflow-grants.json', 'serve-grants.json'):
    shutil.copyfile(source / 'swamp/config' / name, release / 'swamp/config' / name)
# A real local Git mirror holds the target commit, as origin does in production.
mirror = source.parent / 'release-origin.git'
if mirror.exists():
    shutil.rmtree(mirror)
mirror.mkdir()
os.chown(mirror, account.pw_uid, account.pw_gid)
run(['runuser', '-u', 'orbit', '--', 'git', 'clone', '--bare', str(source), str(mirror)])
run(['runuser', '-u', 'orbit', '--', 'git', '--git-dir', str(mirror), 'fetch', str(source), '+HEAD:refs/heads/release'])
run(['runuser', '-u', 'orbit', '--', 'git', 'remote', 'set-url', 'origin', str(mirror)])
run(['runuser', '-u', 'orbit', '--', 'git', 'config', 'core.logAllRefUpdates', 'true'])
for path in (release, release / 'REVISION', release / 'swamp', Path('/home/orbit/apps/orbit-monorepo-staging')):
    os.chown(path, account.pw_uid, account.pw_gid, follow_symlinks=False)
print(json.dumps({'prepared': sha}))
