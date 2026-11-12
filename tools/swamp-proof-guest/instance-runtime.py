"""Read actual container runtime and exercise its workspace. Used only on nested-app-dev."""
import json
import re
import subprocess
import sys
from pathlib import Path


def main(payload):
    if Path('/etc/hostname').read_text().strip() != 'nested-app-dev':
        raise RuntimeError('proof.instance_fixture_not_nested')
    member = payload['member']
    runtime = member['runtime']
    name = runtime['container_name']
    project = runtime['incus_project']
    if project != 'orbit-instances' or name != 'instance-' + str(member['id']):
        raise ValueError('invalid proof container identity')
    failed, evidence = [], {}

    def call(argv):
        return subprocess.run(argv, capture_output=True, text=True, timeout=30, stdin=subprocess.DEVNULL)

    if payload['state'] == 'absent':
        for label, argv in (
            ('container', ['incus', '--project', project, 'list', '--format', 'json']),
            ('acl', ['incus', '--project', 'default', 'network', 'acl', 'list', '--format', 'json']),
        ):
            done = call(argv)
            if done.returncode:
                raise RuntimeError('cannot read ' + label + ' inventory')
            present = name in [row['name'] for row in json.loads(done.stdout)]
            evidence[label + '_present'] = present
            if present:
                failed.append(label + ' remains after destroy')
        mirror = Path(member['mirror'])
        evidence['mirror_present'] = mirror.exists()
        if mirror.exists():
            failed.append('mirror remains after destroy')
    else:
        done = call(['incus', '--project', project, 'list', name, '--format', 'json'])
        if done.returncode:
            raise RuntimeError('cannot read container')
        containers = [row for row in json.loads(done.stdout) if row['name'] == name]
        evidence['running'] = len(containers) == 1 and containers[0]['status'] == 'Running'
        if not evidence['running']:
            failed.append('container is not running')
        else:
            inside = ['incus', '--project', project, 'exec', name, '--']
            active = call(inside + ['systemctl', 'is-active', 't3.service'])
            evidence['t3_active'] = active.returncode == 0 and active.stdout.strip() == 'active'
            if not evidence['t3_active']:
                failed.append('T3 unit is not active')
            environment = call(inside + ['curl', '--fail', '--silent', '--max-time', '5',
                                          'http://127.0.0.1:3773/.well-known/t3/environment'])
            try:
                identity = json.loads(environment.stdout).get('environmentId') if environment.returncode == 0 else None
            except ValueError:
                identity = None
            evidence['t3_identity_matches'] = bool(identity) and member['conn'] == [identity]
            if not evidence['t3_identity_matches']:
                failed.append('served T3 identity differs from its Conn record')
            for user in ('orbit', 'agent'):
                git = call(inside + ['runuser', '-u', user, '--', 'git', '-C', '/srv/workspace', 'rev-parse', 'HEAD'])
                writable = call(inside + ['runuser', '-u', user, '--', 'sh', '-ec',
                                          'test -s /srv/workspace/README; p=$(mktemp /srv/workspace/.proof-XXXXXX); rm "$p"'])
                evidence[user + '_workspace'] = git.returncode == 0 and git.stdout.strip() == payload['seed_commit'] and writable.returncode == 0
                if not evidence[user + '_workspace']:
                    failed.append(user + ' cannot use the seeded Git workspace')
    return {'failed': failed, **evidence}


if __name__ == '__main__':
    try:
        print(json.dumps(main(json.load(sys.stdin))))
    except Exception as error:
        # Do not expose tool stdout, exception arguments or private container state.
        print(type(error).__name__, file=sys.stderr)
        sys.exit(1)
