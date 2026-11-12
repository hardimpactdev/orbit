#!/usr/bin/env python3
"""Keep Incus bridge endpoint rules in step with admitted intent and Docker DNAT.

One nonblocking attempt only; a busy writer refuses without applying intent.
Every writer holds the same lock while reading and replacing records and ACL rules.
Refresh replaces recorded Docker translations. Reconciliation removes endpoint intent.
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

# instance-bridge-refresh.yml: TimeoutStartSec=30; no lock wait consumes this budget.
REFRESH_BUDGET_SECONDS = 30
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
            def nat_value(flag, rule=rule):
                return rule[rule.index(flag) + 1] if flag in rule else ''
            if (nat_value('-d') in ('', '0.0.0.0/0', host + '/32') and nat_value('-p') == 'tcp'
                    and nat_value('--dport') == str(port) and nat_value('-j') == 'DNAT'):
                target = nat_value('--to-destination').split(':')
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
    if not isinstance(value, int) or re.fullmatch(r'[1-9][0-9]{15}', str(value)) is None:
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


def plan(current, endpoints, cidr, baseline, capture=None, obsolete=None):
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
    for raw in current.get('egress', []):
        if (owned_endpoint(raw, cidr)
                and not any(same_endpoint(raw, rule) for rule in desired['egress'])
                and ((capture is not None and rule_admission(raw) < capture)
                     or any(rule_admission(raw) == rule_admission(old) and same_endpoint(raw, old)
                            for old in obsolete or []))):
            changes.append(('remove', 'egress', raw))
    return changes


def api(name, value=None, etag=None, deadline=None):
    """Use the Incus API's ETag, so a concurrent write cannot replace another endpoint."""
    put = []
    if value is not None:
        if not isinstance(etag, str):
            raise PolicyError('instance.container_bridge_write_failed')
        put = ['--request', 'PUT', '--header', 'Content-Type: application/json',
               '--header', 'If-Match: ' + etag, '--data-binary', '@-']
    timeout = CURL_TIMEOUT_SECONDS if deadline is None else min(CURL_TIMEOUT_SECONDS, remaining(deadline))
    result = subprocess.run(['curl', '--silent', '--show-error', '--fail-with-body', '--max-time', str(CURL_TIMEOUT_SECONDS),
                             '--include', '--unix-socket', '/var/lib/incus/unix.socket',
                             'http://localhost/1.0/network-acls/' + name + '?project=default', *put],
                            input=None if value is None else json.dumps(value).encode(),
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


def write_rules(name, resolved, config, reconcile, deadline=None, root=None, staged=None, retiring=None, obsolete=None):
    capture = config['admitted_at'] if reconcile else None
    # Reserve one existing curl request for readback before releasing the writer lock.
    work_deadline = None if deadline is None else deadline - CURL_TIMEOUT_SECONDS
    changed = 0
    attempting_put = False
    try:
        current, _ = api(name, deadline=work_deadline)
        if root is not None:
            changed += confirm_records(root, current, staged, retiring, resolved, config)
        changes = plan(current, resolved, config['cidr'], config.get('baseline', {}), capture, obsolete)
        for action, direction, rule in changes:
            current, etag = api(name, deadline=work_deadline)
            if (action, direction, rule) not in plan(current, resolved, config['cidr'], config.get('baseline', {}), capture, obsolete):
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
            if root is not None:
                changed += confirm_records(root, updated, staged, retiring, resolved, config)
        return changed
    except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
        if not attempting_put and failure_code(error) != 'instance.bridge_command_timeout':
            raise
        try:
            current, _ = api(name, deadline=deadline)
        except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as read_error:
            raise PolicyError('instance.bridge_state_unresolved') from read_error
        if root is not None:
            changed += confirm_records(root, current, staged, retiring, resolved, config)
        if not plan(current, resolved, config['cidr'], config.get('baseline', {}), capture, obsolete):
            return changed + int(attempting_put)
        raise


def state_file(root, kind, value=None, host=None, port=None, capture=None, remove=False):
    root = Path(root)
    if kind in ('config', 'health', 'pending'):
        path = root / {'config': 'config.json', 'health': 'health.json', 'pending': 'reconcile.pending'}[kind]
    elif kind in ('endpoint', 'staged'):
        host = str(ipaddress.IPv4Address(host))
        if type(port) is not int or not 1 <= port <= 65535 or type(capture) is not int or capture < 0:
            raise PolicyError('instance.container_bridge_write_failed')
        directory = 'endpoints' if kind == 'endpoint' else '.staged'
        path = root / directory / (host + '-' + str(port) + '-' + str(capture) + '.json')
    else:
        raise PolicyError('instance.container_bridge_write_failed')
    if remove:
        path.unlink(missing_ok=True)
        return 1
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
    with (root / 'writer.lock').open('a') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise PolicyError('instance.bridge_busy') from None
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
            code = 'instance.bridge_state_unresolved' if unresolved_health(root) else failure_code(error)
            state_file(root, 'health', {'error_code': code})
            raise


def confirm_records(root, current, staged, retiring, resolved, config):
    """Apply only ACL-confirmed ownership changes before another rule is attempted."""
    changed = 0
    capture = config['admitted_at']
    for endpoint in staged:
        target = endpoint['translated']
        wanted = endpoint_rule(target['host'], target['port'], config['cidr'], endpoint['admitted_at'])
        if any(owned_endpoint(rule, config['cidr']) and same_endpoint(rule, wanted)
               and rule_admission(rule) >= endpoint['admitted_at'] for rule in current.get('egress', [])):
            confirmed = dict(endpoint)
            history = [old for old in endpoint.get('translations', [])
                       if (old['destination'].removesuffix('/32'), int(old['destination_port'])) not in resolved
                       and any(owned_endpoint(rule, config['cidr']) and same_endpoint(rule, old)
                               and rule_admission(rule) == rule_admission(old) for rule in current.get('egress', []))]
            if history:
                confirmed['translations'] = history
            else:
                confirmed.pop('translations', None)
            changed += state_file(root, 'endpoint', confirmed, host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'])
    for path, endpoint, target in retiring:
        if not path.exists():
            continue
        wanted = endpoint_rule(target['host'], target['port'], config['cidr'], capture)
        if ((target['host'], target['port']) not in resolved
                and any(owned_endpoint(rule, config['cidr']) and same_endpoint(rule, wanted)
                        for rule in current.get('egress', []))):
            continue
        # Keep truthful health across a crash or a later failed removal.
        changed += state_file(root, 'pending', {'admitted_at': capture})
        changed += state_file(root, 'endpoint', host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'], remove=True)
    return changed


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
                endpoints[key] = dict(row)
    if incoming is not None:
        for row in incoming['endpoints']:
            key = (row['host'], row['port'])
            if key not in endpoints or capture > endpoints[key]['admitted_at']:
                endpoints[key] = {**row, 'admitted_at': capture}
    endpoints = list(endpoints.values())
    retained = {(row['host'], row['port']) for row in endpoints}
    retiring = []
    if incoming is not None and reconcile:
        for path, row in records:
            key = (row['host'], row['port'])
            if row['admitted_at'] >= capture or key in retained:
                continue
            previous = recorded[key]
            target = previous.get('translated')
            if target is None:
                # A Docker record without a saved tuple cannot prove a partial removal. Fail before any ACL mutation.
                host, port = resolve([previous], '')[0]
                target = {'host': host, 'port': port}
            retiring.append((path, row, target))
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
    obsolete = []
    if incoming is None:
        for endpoint in endpoints:
            if not endpoint['docker']:
                continue
            history = []
            for _, previous in records:
                if (previous['host'], previous['port']) != (endpoint['host'], endpoint['port']):
                    continue
                target = previous['translated']
                known = [endpoint_rule(target['host'], target['port'], config['cidr'], previous['admitted_at']),
                         *previous.get('translations', [])]
                for rule in known:
                    if rule not in history:
                        history.append(rule)
            obsolete.extend(history)
            target = endpoint['translated']
            wanted = endpoint_rule(target['host'], target['port'], config['cidr'], endpoint['admitted_at'])
            previous = recorded[(endpoint['host'], endpoint['port'])]
            if previous['translated'] != target:
                if wanted not in history:
                    history.append(wanted)
                endpoint['translations'] = history
                # Persist attempted rules before PUT, while translated still names the
                # last confirmed address. A crash cannot orphan a successful new allow.
                changed += state_file(root, 'endpoint', {**previous, 'translations': history},
                                      host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'])
    # Stage outside the inventory. Only confirmed ACL tuples become refresh input.
    staged = []
    try:
        for endpoint in endpoints:
            remaining(deadline)
            state_file(root, 'staged', endpoint, host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'])
            staged.append(endpoint)
        changed += write_rules(config['network'] + '-egress', resolved, config, reconcile, deadline, root, staged, retiring, obsolete)
    finally:
        for endpoint in staged:
            state_file(root, 'staged', host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'], remove=True)
    if incoming is not None and reconcile:
        for path, endpoint in records:
            if endpoint['admitted_at'] < capture and path.exists():
                changed += state_file(root, 'endpoint', host=endpoint['host'], port=endpoint['port'], capture=endpoint['admitted_at'], remove=True)
        changed += state_file(root, 'config', {key: value for key, value in config.items() if key != 'endpoints'})
        state_file(root, 'pending', remove=True)
    # Only a full refresh/converge proves Node-wide health; a successful attach checks its own subset.
    if incoming is None or reconcile:
        health = root / 'health.json'
        if not reconcile and (root / 'reconcile.pending').exists():
            if not health.exists() or json.loads(health.read_text()).get('error_code') is None:
                changed += state_file(root, 'health', {'error_code': 'instance.bridge_state_unresolved'})
        elif reconcile or not unresolved_health(root):
            changed += state_file(root, 'health', {'error_code': None})
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
