"""Release-only serve restart for new manifest additionalFiles (https://swamp-club.com/lab/4282).

Gateway renders grants; the release workflow writes them with official cfgmgmt file resources.
Run only `release-restart <commit>` from the serve checkout. Remove this helper when Lab #4282 is fixed.
"""

import json
import subprocess
import sys

def done(value):
    print(json.dumps(value))
    sys.exit(0)


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


if __name__ == '__main__':
    if len(sys.argv) == 3 and sys.argv[1] == 'release-restart':
        release_restart(sys.argv[2])
    sys.exit(1)
