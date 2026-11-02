"""Check work is pushed: fetch origin, then refuse a dirty source or a HEAD no origin branch contains.

argv[1] is the public destroy intent, argv[2] the source capture of Verify the source. The short-lived read
token arrives on stdin and enters only the fetch's environment; Git's stderr stays on the Node. Members that
are failed creations or have no commit are skipped. Prints {"ready": true} or {"error_code": ...}.
"""
import base64
import json
import os
import subprocess
import sys
from pathlib import Path

SETTINGS = ['core.hooksPath=/dev/null', 'core.fsmonitor=false', 'credential.helper=', 'protocol.allow=never', 'protocol.ext.allow=never',
            'protocol.https.allow=always', 'core.sshCommand=false', 'core.alternateRefsCommand=', 'http.followRedirects=false']


def fetch(checkout, repository, token):
    environment = {'PATH': '/usr/bin:/bin', 'HOME': os.environ.get('HOME', '/'), 'GIT_ALLOW_PROTOCOL': 'https', 'GIT_TERMINAL_PROMPT': '0',
                   'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}
    if token:
        entries = [('http.https://github.com/.extraheader', 'Authorization: Basic ' + base64.b64encode(('x-access-token:' + token).encode()).decode()),
                   ('url.https://github.com/.insteadOf', 'git@github.com:'), ('url.https://github.com/.insteadOf', 'ssh://git@github.com/')]
        environment['GIT_CONFIG_COUNT'] = str(len(entries))
        for index, (key, value) in enumerate(entries):
            environment[f'GIT_CONFIG_KEY_{index}'], environment[f'GIT_CONFIG_VALUE_{index}'] = key, value
    argv = ['/usr/bin/git', *[part for setting in SETTINGS for part in ('-c', setting)], '-C', checkout,
            'fetch', '--quiet', '--no-tags', '--prune', '--', repository, '+refs/heads/*:refs/remotes/origin/*']
    # Git errors may echo a credential; they stay on the Node.
    return subprocess.run(argv, env=environment, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                          timeout=120, check=False).returncode == 0


def git(checkout, *arguments):
    return subprocess.run(['/usr/bin/git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', '-C', checkout, *arguments],
                          stdin=subprocess.DEVNULL, capture_output=True, text=True, check=True, timeout=60).stdout.strip()


def pushed(intent, inspection, material):
    if isinstance(material, str):
        material = json.loads(material)
    token = (material or {}).get('github_token') or (material or {}).get('token')
    captured = {row['instance_id']: row for row in inspection['members']}
    for member in intent['members']:
        source = captured[member['instance_id']]
        checkout = member['checkout_path']
        if member['failed_creation'] or not source['source_commit'] or not Path(checkout).is_dir():
            continue
        if not fetch(checkout, intent['repository'], token):
            return {'error_code': 'instance.remove_refused'}
        if git(checkout, 'status', '--porcelain', '--untracked-files=all') or git(checkout, 'rev-list', '--count', 'HEAD', '--not', '--remotes=origin') != '0':
            return {'error_code': 'instance.unpublished_content'}
    return {'ready': True}


if __name__ == '__main__':
    try:
        raw = sys.stdin.read()
        result = pushed(json.loads(sys.argv[1]), json.loads(sys.argv[2])['source_inspection'], json.loads(raw) if raw.strip() else {})
    except (ValueError, OSError, KeyError, TypeError, subprocess.SubprocessError):
        result = {'error_code': 'instance.remove_refused'}
    print(json.dumps(result))
    sys.exit(0 if result.get('ready') else 1)
