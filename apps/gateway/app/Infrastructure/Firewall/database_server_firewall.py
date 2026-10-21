"""Fixed SSH program. Its input contains only validated private endpoint rules."""
import fcntl
import json
import os
from pathlib import Path
import shlex
import subprocess
import sys

CHAIN = 'ORBIT-DATABASE'
LOCAL_CHAIN = 'ORBIT-DATABASE-LOCAL'
DIRECTORY = Path('/etc/orbit')
RULES = DIRECTORY / 'database-firewall.rules'
HOOK = Path('/etc/ufw/before.init')
RUNNER = DIRECTORY / 'database-firewall'
DROPIN = Path('/etc/systemd/system/docker.service.d/orbit-database-firewall.conf')
BEGIN = '# BEGIN ORBIT DATABASE FIREWALL\n'
END = '# END ORBIT DATABASE FIREWALL\n'
HOOK_BLOCK = BEGIN + '/etc/orbit/database-firewall "${1:-start}" || exit $?\n' + END
DROPIN_CONTENT = '''[Unit]
After=ufw.service
Wants=ufw.service

[Service]
ExecStartPre=/etc/orbit/database-firewall docker
'''


def run(argv, data=None):
    return subprocess.run(argv, input=data, text=True, stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE, check=True).stdout


def active():
    return 'Status: active' in run(['ufw', 'status'])


def live(chain=CHAIN, table='raw'):
    try:
        return run(['iptables', '-w', '10', '-t', table, '-S', chain]).splitlines()[1:]
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
case "${1:-start}" in
    docker) ufw status | grep -q '^Status: active$' ;;
    start) ;;
    *) exit 0 ;;
esac
iptables-restore --wait 10 --noflush < /etc/orbit/database-firewall.rules
for spec in 'raw PREROUTING ORBIT-DATABASE' 'filter INPUT ORBIT-DATABASE-LOCAL'; do
    set -- $spec
    iptables -w 10 -t "$1" -I "$2" 1 -j "$3"
    # Keep the first jump; remove only duplicate jumps to this owned chain.
    iptables -w 10 -t "$1" -S "$2" | awk -v rule="-A $2 -j $3" '$0 == rule {n++; if(n>1) print NR-1}' | sort -rn | while read -r number; do
        iptables -w 10 -t "$1" -D "$2" "$number"
    done
    [ "$(iptables -w 10 -t "$1" -S "$2" | sed -n '2p')" = "-A $2 -j $3" ]
done
'''


def local_rules(expected):
    # docker-proxy hairpin traffic reaches INPUT. Scope its allow to these endpoints.
    return [line.replace('-A ' + CHAIN, '-A ' + LOCAL_CHAIN, 1).replace('-j RETURN', '-j ACCEPT')
            for line in expected if ' -i docker0 ' in line and line.endswith('-j RETURN')]


def render(expected):
    def table(name, chain, rules):
        return '*' + name + '\n:' + chain + ' - [0:0]\n-F ' + chain + '\n' + '\n'.join(rules) + '\nCOMMIT\n'
    return table('raw', CHAIN, expected) + table('filter', LOCAL_CHAIN, local_rules(expected))


def jump_matches(table, parent, chain):
    jumps = run(['iptables', '-w', '10', '-t', table, '-S', parent]).splitlines()
    jump = '-A ' + parent + ' -j ' + chain
    return jumps.count(jump) == 1 and len(jumps) > 1 and jumps[1] == jump


def inspect(expected, content):
    return (active() and normalized(live()) == normalized(expected)
            and normalized(live(LOCAL_CHAIN, 'filter')) == normalized(local_rules(expected))
            and jump_matches('raw', 'PREROUTING', CHAIN)
            and jump_matches('filter', 'INPUT', LOCAL_CHAIN)
            and RULES.exists() and RULES.read_text() == content
            and RUNNER.exists() and RUNNER.read_text() == RUNNER_CONTENT
            and HOOK.exists() and HOOK_BLOCK in HOOK.read_text()
            and DROPIN.exists() and DROPIN.read_text() == DROPIN_CONTENT)


def reconcile(payload):
    if not active():
        raise RuntimeError('inactive firewall')
    expected = ['-A ' + CHAIN + ' ' + rule for rule in payload['rules']]
    if payload.get('expand'):
        # Widen before an address changes; retain every old allow until prune.
        old = live()
        existing = normalized(old)
        additions = [line for line in expected if line.endswith('-j RETURN') and normalized([line])[0] not in existing]
        expected = additions + old if old else expected
    content = render(expected)
    hook = HOOK.read_text() if HOOK.exists() else '#!/bin/sh\n'
    if BEGIN in hook:
        first, rest = hook.split(BEGIN, 1)
        _, rest = rest.split(END, 1)
        hook = first + rest
    if not hook.startswith('#!'):
        raise RuntimeError('invalid hook')
    first, rest = hook.split('\n', 1)
    hook = first + '\n' + HOOK_BLOCK + rest
    # Check a candidate without changing unrelated tables or Docker chains.
    run(['iptables-restore', '--wait', '10', '--test', '--noflush'], content)
    prior = {path: (path.read_bytes(), path.stat().st_mode & 0o777) if path.exists() else None
             for path in (RULES, RUNNER, HOOK, DROPIN)}
    old_live = live()
    old_local = live(LOCAL_CHAIN, 'filter')
    try:
        DROPIN.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
        atomic(RULES, content, 0o600)
        atomic(RUNNER, RUNNER_CONTENT, 0o700)
        atomic(HOOK, hook, 0o700)
        atomic(DROPIN, DROPIN_CONTENT, 0o644)
        run(['systemctl', 'daemon-reload'])
        run([str(RUNNER), 'start'])
        if not inspect(expected, content):
            raise RuntimeError('verification failed')
    except Exception:
        for path, saved in prior.items():
            if saved is None:
                path.unlink(missing_ok=True)
            else:
                atomic(path, saved[0].decode(), saved[1])
        run(['systemctl', 'daemon-reload'])
        if old_live:
            rollback = render(old_live)
            # Restore the exact old local rules as well as raw ingress.
            rollback = rollback[:rollback.index('*filter')] + '*filter\n:' + LOCAL_CHAIN + ' - [0:0]\n-F ' + LOCAL_CHAIN + '\n' + '\n'.join(old_local) + '\nCOMMIT\n'
            run(['iptables-restore', '--wait', '10', '--noflush'], rollback)
        # First install keeps its restrictive filter; no Process has published yet.
        raise
    print('matching')


def main():
    payload = json.load(sys.stdin)
    if payload['action'] == 'inspect':
        # Doctor must not create directories, files or locks on the Node.
        expected = ['-A ' + CHAIN + ' ' + rule for rule in payload['rules']]
        print('matching' if inspect(expected, render(expected)) else 'drift')
        return
    DIRECTORY.mkdir(mode=0o755, exist_ok=True)
    with (DIRECTORY / 'database-firewall.lock').open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        reconcile(payload)


if __name__ == '__main__':
    try:
        main()
    except Exception:
        # Never return remote command output through Gateway errors.
        sys.exit(1)
