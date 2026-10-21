"""Fixed SSH program. Its input contains only validated private endpoint rules."""
import fcntl
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys

CHAIN = 'ORBIT-DATABASE'
DIRECTORY = Path('/etc/orbit')
RULES = DIRECTORY / 'database-firewall.rules'
HOOK = Path('/etc/ufw/before.init')
RUNNER = DIRECTORY / 'database-firewall'
BEGIN = '# BEGIN ORBIT DATABASE FIREWALL\n'
END = '# END ORBIT DATABASE FIREWALL\n'


def run(argv, data=None):
    return subprocess.run(argv, input=data, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=True).stdout


def active():
    return 'Status: active' in run(['ufw', 'status'])


def live():
    try:
        return run(['iptables', '-w', '10', '-t', 'raw', '-S', CHAIN]).splitlines()[1:]
    except subprocess.CalledProcessError:
        return []


def normalized(lines):
    # iptables -S adds its TCP match and canonicalizes addresses.
    return [sorted(zip(tokens[::2], tokens[1::2])) for line in lines
            for tokens in [shlex.split(line.replace(' -m tcp', '').replace('/32', ''))]]


def atomic(path, content, mode):
    candidate = path.with_name(path.name + '.orbit-new')
    candidate.write_text(content)
    candidate.chmod(mode)
    os.replace(candidate, path)


RUNNER_CONTENT = '''#!/bin/sh
set -eu
[ "${1:-start}" = start ] || exit 0
iptables-restore --wait 10 --noflush < /etc/orbit/database-firewall.rules
iptables -w 10 -t raw -I PREROUTING 1 -j ORBIT-DATABASE
# Keep the first jump; remove only duplicate jumps to this owned chain.
iptables -w 10 -t raw -S PREROUTING | awk '$0 == "-A PREROUTING -j ORBIT-DATABASE" {n++; if(n>1) print NR-1}' | sort -rn | while read -r number; do
    iptables -w 10 -t raw -D PREROUTING "$number"
done
'''


def inspect(expected, content):
    jumps = run(['iptables', '-w', '10', '-t', 'raw', '-S', 'PREROUTING']).splitlines()
    return (active() and normalized(live()) == normalized(expected)
            and jumps.count('-A PREROUTING -j ' + CHAIN) == 1
            and RULES.exists() and RULES.read_text() == content
            and RUNNER.exists() and RUNNER.read_text() == RUNNER_CONTENT
            and HOOK.exists() and (BEGIN + '/etc/orbit/database-firewall "${1:-start}"\n' + END) in HOOK.read_text())


def main():
    payload = json.load(sys.stdin)
    DIRECTORY.mkdir(mode=0o755, exist_ok=True)
    with (DIRECTORY / 'database-firewall.lock').open('w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        if not active():
            raise RuntimeError('inactive firewall')
        expected = ['-A ' + CHAIN + ' ' + rule for rule in payload['rules']]
        if payload.get('expand'):
            # Widen before an address changes; retain every old allow until prune.
            old = live()
            additions = [line for line in expected if line.endswith('-j RETURN') and line not in old]
            expected = additions + old if old else expected
        content = '*raw\n:' + CHAIN + ' - [0:0]\n-F ' + CHAIN + '\n' + '\n'.join(expected) + '\nCOMMIT\n'
        if payload['action'] == 'inspect':
            print('matching' if inspect(expected, content) else 'drift')
            return
        hook = HOOK.read_text() if HOOK.exists() else '#!/bin/sh\n'
        if BEGIN in hook:
            first, rest = hook.split(BEGIN, 1)
            _, rest = rest.split(END, 1)
            hook = first + rest
        if not hook.startswith('#!'):
            raise RuntimeError('invalid hook')
        first, rest = hook.split('\n', 1)
        hook = first + '\n' + BEGIN + '/etc/orbit/database-firewall "${1:-start}"\n' + END + rest
        # Check a candidate without changing unrelated tables or Docker chains.
        run(['iptables-restore', '--wait', '10', '--test', '--noflush'], content)
        prior = {path: (path.read_bytes(), path.stat().st_mode & 0o777) if path.exists() else None
                 for path in (RULES, RUNNER, HOOK)}
        old_live = live()
        try:
            atomic(RULES, content, 0o600)
            atomic(RUNNER, RUNNER_CONTENT, 0o700)
            atomic(HOOK, hook, 0o700)
            run([str(RUNNER), 'start'])
            if not inspect(expected, content):
                raise RuntimeError('verification failed')
        except Exception:
            for path, saved in prior.items():
                if saved is None:
                    path.unlink(missing_ok=True)
                else:
                    atomic(path, saved[0].decode(), saved[1])
            if old_live:
                rollback = '*raw\n:' + CHAIN + ' - [0:0]\n-F ' + CHAIN + '\n' + '\n'.join(old_live) + '\nCOMMIT\n'
                run(['iptables-restore', '--wait', '10', '--noflush'], rollback)
            # If this is a first install, keep its restrictive filter; no Process has published yet.
            raise
        print('matching')


try:
    main()
except Exception:
    # Never return remote command output through Gateway errors.
    sys.exit(1)
