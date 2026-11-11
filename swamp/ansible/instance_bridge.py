#!/usr/bin/env python3
"""Keep Incus bridge endpoint rules in step with admitted intent and Docker DNAT.

One attempt only. Ansible owns admission retries; the systemd timer serializes refreshes.
Every writer holds the same lock while reading and replacing records and ACL rules.
The refresh only adds rules. Reconciliation removes endpoint rules or intent.
"""
import argparse
import fcntl
import ipaddress
import json
import re
import shlex
import subprocess
import sys
import tempfile
import time
from contextlib import contextmanager
from pathlib import Path


# instance-bridge-refresh.yml: TimeoutStartSec=30; wait for at least one full refresh.
REFRESH_BUDGET_SECONDS = 30
LOCK_WAIT_SECONDS = REFRESH_BUDGET_SECONDS
# Lifecycle workflow phases use `timeout 300 ansible-playbook`; reads share the remaining helper budget.
LIFECYCLE_BUDGET_SECONDS = 300
# Incus requests retain #3348's --max-time 5; this is not a global writer limit.
CURL_TIMEOUT_SECONDS = 5


class PolicyError(Exception):
    pass


def resolve(endpoints, nat):
    """Resolve exactly one live DOCKER DNAT rule per admitted local Docker publication."""
    rules = [shlex.split(line) for line in nat.splitlines() if line.startswith('-A DOCKER ')]
    resolved = set()
    for endpoint in endpoints:
        host, port = str(ipaddress.IPv4Address(endpoint['host'])), int(endpoint['port'])
        if not 1 <= port <= 65535:
            raise PolicyError('instance.container_endpoint_unresolved')
        if not endpoint['docker']:
            resolved.add((host, port))
            continue
        matches = []
        for rule in rules:
            def value(flag, rule=rule):
                return rule[rule.index(flag) + 1] if flag in rule else ''
            if (value('-d') in ('', '0.0.0.0/0', host + '/32') and value('-p') == 'tcp'
                    and value('--dport') == str(port) and value('-j') == 'DNAT'):
                target = value('--to-destination').split(':')
                if len(target) != 2 or not target[1].isdigit():
                    raise PolicyError('instance.container_endpoint_unresolved')
                if not 1 <= int(target[1]) <= 65535:
                    raise PolicyError('instance.container_endpoint_unresolved')
                matches.append((str(ipaddress.IPv4Address(target[0])), int(target[1])))
        if len(matches) != 1:
            raise PolicyError('instance.container_endpoint_unresolved')
        resolved.add(matches[0])
    return sorted(resolved)


def admission(value):
    """Gateway epoch microseconds; one fixed representation in files and ACL descriptions."""
    if type(value) is not int or re.fullmatch(r'[1-9][0-9]{15}', str(value)) is None:
        raise PolicyError('instance.container_bridge_write_failed')
    return value


def rule_admission(rule):
    description = rule.get('description')
    match = re.fullmatch(r'orbit:endpoint:([1-9][0-9]{15})', description) if isinstance(description, str) else None
    return int(match[1]) if match else None


def endpoint_rule(host, port, cidr, admitted_at):
    return {'action': 'allow', 'source': cidr, 'destination': host + '/32', 'protocol': 'tcp',
            'destination_port': str(port), 'state': 'enabled', 'description': 'orbit:endpoint:' + str(admission(admitted_at))}


def owned_endpoint(rule, cidr):
    row = {key: value for key, value in rule.items() if value != ''}
    return (set(row) == {'action', 'source', 'destination', 'protocol', 'destination_port', 'state', 'description'}
            and rule_admission(row) is not None and row['action'] == 'allow' and row['source'] == cidr
            and row['protocol'] == 'tcp' and row['state'] == 'enabled'
            and re.fullmatch(r'[0-9.]+/32', row['destination']) is not None
            and row['destination_port'].isdigit())


def same_endpoint(row, rule):
    return all(row.get(key) == value for key, value in rule.items() if key != 'description')


def plan(current, endpoints, cidr, baseline, capture=None):
    desired = {'egress': [endpoint_rule(host, port, cidr, stamp) for (host, port), stamp in sorted(endpoints.items())], 'ingress': []}
    for direction in desired:
        desired[direction] += baseline.get(direction, [])
    changes = []
    for direction, wanted in desired.items():
        existing = current.get(direction, [])
        for rule in wanted:
            stamp = rule_admission(rule)
            if not any((owned_endpoint(row, cidr) and same_endpoint(row, rule) and rule_admission(row) >= stamp)
                       if stamp is not None else all(row.get(key) == value for key, value in rule.items()) for row in existing):
                changes.append(('add', direction, rule))
    if capture is not None:
        for raw in current.get('egress', []):
            if (owned_endpoint(raw, cidr) and rule_admission(raw) < capture
                    and not any(same_endpoint(raw, rule) for rule in desired['egress'])):
                changes.append(('remove', 'egress', raw))
    return changes


def api(name, value=None, etag=None, deadline=None):
    """Use the Incus API's ETag, so a concurrent write cannot replace another endpoint."""
    argv = ['curl', '--silent', '--show-error', '--fail-with-body', '--max-time', str(CURL_TIMEOUT_SECONDS), '--include', '--unix-socket',
            '/var/lib/incus/unix.socket', 'http://localhost/1.0/network-acls/' + name + '?project=default']
    if value is not None:
        if not isinstance(etag, str):
            raise PolicyError('instance.container_bridge_write_failed')
        argv += ['--request', 'PUT', '--header', 'Content-Type: application/json',
                 '--header', 'If-Match: ' + etag, '--data-binary', '@-']
    timeout = CURL_TIMEOUT_SECONDS if deadline is None else min(CURL_TIMEOUT_SECONDS, remaining(deadline))
    result = subprocess.run(argv, input=None if value is None else json.dumps(value).encode(),
                            capture_output=True, check=False, timeout=timeout)
    if result.returncode == 28:
        raise PolicyError('instance.bridge_command_timeout')
    if result.returncode:
        detail = result.stderr.decode().strip()
        if b'\r\n\r\n' in result.stdout:
            detail += ' ' + json.loads(result.stdout.split(b'\r\n\r\n', 1)[1]).get('error', '')
        raise PolicyError(detail or 'instance.container_bridge_write_failed')
    headers, body = result.stdout.split(b'\r\n\r\n', 1)
    tags = [line.split(b':', 1)[1].strip().decode() for line in headers.splitlines()
            if line.lower().startswith(b'etag:')]
    response = json.loads(body)
    if response.get('error_code', 0):
        raise PolicyError(response.get('error', 'instance.container_bridge_write_failed'))
    return response['metadata'], tags[0] if tags else None


def write_rules(name, resolved, config, reconcile, deadline=None, confirmed=None):
    capture = config['admitted_at'] if reconcile else None
    # Reserve one existing curl request for readback before releasing the writer lock.
    work_deadline = None if deadline is None else deadline - CURL_TIMEOUT_SECONDS
    changed = 0
    attempting_put = False
    try:
        current, _ = api(name, deadline=work_deadline)
        if confirmed is not None:
            confirmed(current)
        changes = plan(current, resolved, config['cidr'], config.get('baseline', {}), capture)
        for action, direction, rule in changes:
            current, etag = api(name, deadline=work_deadline)
            if (action, direction, rule) not in plan(current, resolved, config['cidr'], config.get('baseline', {}), capture):
                continue
            updated = {key: current.get(key, {} if key == 'config' else [] if key in ['egress', 'ingress'] else '')
                       for key in ['description', 'config', 'egress', 'ingress']}
            if action == 'add':
                updated[direction] = [row for row in updated[direction]
                                      if not (rule_admission(rule) is not None and owned_endpoint(row, config['cidr'])
                                              and same_endpoint(row, rule))] + [rule]
            else:
                updated[direction] = [row for row in updated[direction] if row != rule]
            attempting_put = True
            api(name, updated, etag, deadline=work_deadline)
            attempting_put = False
            changed += 1
            if confirmed is not None:
                confirmed(updated)
        return changed
    except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
        if not attempting_put and failure_code(error) != 'instance.bridge_command_timeout':
            raise
        try:
            current, _ = api(name, deadline=deadline)
        except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as read_error:
            raise PolicyError('instance.bridge_state_unresolved') from read_error
        if confirmed is not None:
            confirmed(current)
        if not plan(current, resolved, config['cidr'], config.get('baseline', {}), capture):
            return changed + int(attempting_put)
        raise


def write_file(path, value):
    content = json.dumps(value, sort_keys=True) + '\n'
    if path.exists() and path.read_text() == content:
        return 0
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, delete=False) as stream:
        stream.write(content)
        temporary = Path(stream.name)
    temporary.chmod(0o644)
    temporary.replace(path)
    return 1


@contextmanager
def writer_lock(root):
    root.mkdir(parents=True, exist_ok=True)
    # Keep this inode for every mode: replacing or unlinking it would split ownership.
    with (root / 'writer.lock').open('a') as lock:
        deadline = time.monotonic() + LOCK_WAIT_SECONDS
        while True:
            if time.monotonic() >= deadline:
                raise PolicyError('instance.bridge_lock_timeout')
            try:
                fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                time.sleep(min(0.05, max(0, deadline - time.monotonic())))
            else:
                # A descheduled waiter must not apply old intent after its deadline.
                if time.monotonic() >= deadline:
                    raise PolicyError('instance.bridge_lock_timeout')
                break
        yield


def remaining(deadline):
    seconds = deadline - time.monotonic()
    if seconds <= 0:
        raise PolicyError('instance.bridge_command_timeout')
    return seconds


def failure_code(error):
    if isinstance(error, subprocess.TimeoutExpired):
        return 'instance.bridge_command_timeout'
    return str(error) if str(error).startswith('instance.') else 'instance.container_bridge_write_failed'


def unresolved_health(root):
    path = root / 'health.json'
    return path.exists() and json.loads(path.read_text()).get('error_code') == 'instance.bridge_state_unresolved'


def apply(root, incoming, reconcile=False, withdraw=False):
    deadline = time.monotonic() + (REFRESH_BUDGET_SECONDS if incoming is None else LIFECYCLE_BUDGET_SECONDS)
    with writer_lock(root):
        try:
            return apply_locked(root, incoming, reconcile, withdraw, deadline)
        except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
            # Record failure while still owning the lock; never wait again after failure.
            code = 'instance.bridge_state_unresolved' if unresolved_health(root) else failure_code(error)
            write_file(root / 'health.json', {'error_code': code})
            raise


def apply_locked(root, incoming, reconcile, withdraw, deadline):
    remaining(deadline)
    if withdraw and (incoming is None or not reconcile):
        raise PolicyError('instance.container_bridge_write_failed')
    config = incoming if incoming is not None else json.loads((root / 'config.json').read_text())
    capture = admission(config['admitted_at'])
    records = [(path, json.loads(path.read_text())) for path in (root / 'endpoints').glob('*.json')]
    endpoints = {}
    recorded = {}
    for _, row in records:
        stamp = admission(row['admitted_at'])
        key = (row['host'], row['port'])
        if key not in recorded or stamp > recorded[key]['admitted_at']:
            recorded[key] = row
        if incoming is None or (reconcile and stamp >= capture):
            key = (row['host'], row['port'])
            if key not in endpoints or stamp > endpoints[key]['admitted_at']:
                endpoints[key] = row
    if incoming is not None:
        for row in incoming['endpoints']:
            key = (row['host'], row['port'])
            if key not in endpoints or capture > endpoints[key]['admitted_at']:
                endpoints[key] = {**row, 'admitted_at': capture}
    endpoints = list(endpoints.values())
    nat = subprocess.check_output(['iptables-save', '-t', 'nat'], text=True, timeout=remaining(deadline)) if not withdraw and any(row['docker'] for row in endpoints) else ''
    resolved = {}
    for endpoint in endpoints:  # Resolve the entire set before any file or ACL mutation.
        if withdraw:
            previous = recorded.get((endpoint['host'], endpoint['port']), {})
            if previous.get('docker') != endpoint['docker'] or 'translated' not in previous:
                raise PolicyError('instance.container_endpoint_unresolved')
            # Withdrawal may retain existing ownership, never admit a new publication.
            target = previous['translated']
            targets = resolve([{'host': target['host'], 'port': target['port'], 'docker': False}], '')
        else:
            targets = resolve([endpoint], nat)
        host, port = targets[0]
        endpoint['translated'] = {'host': host, 'port': port}
        resolved[(host, port)] = max(resolved.get((host, port), 0), endpoint['admitted_at'])
    changed = 0
    # Readers never admit staging files, including any left behind by a killed writer.
    with tempfile.TemporaryDirectory(prefix='.staged-', dir=root) as directory:
        staged = []
        for endpoint in endpoints:
            remaining(deadline)
            path = Path(directory) / (endpoint['host'] + '-' + str(endpoint['port']) + '-' + str(endpoint['admitted_at']) + '.json')
            write_file(path, endpoint)
            staged.append((path, endpoint))

        def commit_confirmed(current):
            nonlocal changed
            for path, endpoint in staged:
                if not path.exists():
                    continue
                target = endpoint['translated']
                wanted = endpoint_rule(target['host'], target['port'], config['cidr'], endpoint['admitted_at'])
                if not any(owned_endpoint(rule, config['cidr']) and same_endpoint(rule, wanted)
                           and rule_admission(rule) >= endpoint['admitted_at'] for rule in current.get('egress', [])):
                    continue
                active = root / 'endpoints' / path.name
                if active.exists() and active.read_text() == path.read_text():
                    path.unlink()
                else:
                    active.parent.mkdir(parents=True, exist_ok=True)
                    path.replace(active)
                    changed += 1

        acl_changed = write_rules(config['network'] + '-egress', resolved, config, reconcile, deadline, commit_confirmed)
        changed += acl_changed
    if incoming is not None and reconcile:
        for path, endpoint in records:
            if endpoint['admitted_at'] < capture:
                path.unlink(missing_ok=True)
                changed += 1
        changed += write_file(root / 'config.json', {key: value for key, value in config.items() if key != 'endpoints'})
    # Only a full refresh/converge proves Node-wide health; a successful attach checks its own subset.
    if incoming is None or reconcile:
        health = root / 'health.json'
        if reconcile or not unresolved_health(root):
            changed += write_file(health, {'error_code': None})
    return changed


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--state-dir', type=Path, default=Path('/var/lib/orbit/instance-bridge'))
    parser.add_argument('--intent', action='store_true')
    parser.add_argument('--reconcile', action='store_true')
    parser.add_argument('--withdraw', action='store_true')
    args = parser.parse_args()
    try:
        changed = apply(args.state_dir, json.load(sys.stdin) if args.intent else None, args.reconcile, args.withdraw)
        print(json.dumps({'changed': changed}))
    except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
        code = failure_code(error)
        print(json.dumps({'error_code': code}))
        print(str(error), file=sys.stderr)
        raise SystemExit(1) from error


if __name__ == '__main__':
    main()
