#!/usr/bin/env python3
"""Keep Incus bridge endpoint rules in step with admitted intent and Docker DNAT.

One attempt only. Ansible owns admission retries; the systemd timer serializes refreshes.
The refresh only adds rules. Only host reconciliation removes endpoint rules or intent.
"""
import argparse
import ipaddress
import json
import re
import shlex
import subprocess
import sys
import tempfile
from pathlib import Path


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
            if (value('-d') == host + '/32' and value('-p') == 'tcp'
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


def api(name, value=None, etag=None):
    """Use the Incus API's ETag, so a concurrent write cannot replace another endpoint."""
    argv = ['curl', '--silent', '--show-error', '--fail-with-body', '--max-time', '5', '--include', '--unix-socket',
            '/var/lib/incus/unix.socket', 'http://localhost/1.0/network-acls/' + name + '?project=default']
    if value is not None:
        if not isinstance(etag, str):
            raise PolicyError('instance.container_bridge_write_failed')
        argv += ['--request', 'PUT', '--header', 'Content-Type: application/json',
                 '--header', 'If-Match: ' + etag, '--data-binary', '@-']
    result = subprocess.run(argv, input=None if value is None else json.dumps(value).encode(),
                            capture_output=True, check=False)
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


def write_rules(name, resolved, config, reconcile):
    capture = config['admitted_at'] if reconcile else None
    current, _ = api(name)
    changes = plan(current, resolved, config['cidr'], config.get('baseline', {}), capture)
    changed = 0
    for action, direction, rule in changes:
        # Reread just before each conditional PUT. The If-Match binds the exact list we merged.
        current, etag = api(name)
        # Recheck ownership and freshness under this ETag, including same-tuple readmissions.
        if (action, direction, rule) not in plan(current, resolved, config['cidr'], config.get('baseline', {}), capture):
            continue
        updated = {key: current.get(key, {} if key == 'config' else [] if key in ['egress', 'ingress'] else '')
                   for key in ['description', 'config', 'egress', 'ingress']}
        if action == 'add':
            # A readmission must bump the ACL ETag even if its allow selectors are unchanged.
            updated[direction] = [row for row in updated[direction]
                                  if not (rule_admission(rule) is not None and owned_endpoint(row, config['cidr'])
                                          and same_endpoint(row, rule))] + [rule]
        else:
            updated[direction] = [row for row in updated[direction] if row != rule]
        api(name, updated, etag)
        changed += 1
    return changed


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


def apply(root, incoming, reconcile=False):
    config = incoming if incoming is not None else json.loads((root / 'config.json').read_text())
    capture = admission(config['admitted_at'])
    records = [(path, json.loads(path.read_text())) for path in (root / 'endpoints').glob('*.json')]
    endpoints = {}
    for _, row in records:
        stamp = admission(row['admitted_at'])
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
    nat = subprocess.check_output(['iptables-save', '-t', 'nat'], text=True) if any(row['docker'] for row in endpoints) else ''
    resolved = {}
    for endpoint in endpoints:  # Resolve the entire set before any file or ACL mutation.
        for target in resolve([endpoint], nat):
            resolved[target] = max(resolved.get(target, 0), endpoint['admitted_at'])
    changed = 0
    if incoming is not None:
        for endpoint in endpoints:
            # Immutable names prevent read/unlink from deleting a concurrently readmitted tuple.
            path = root / 'endpoints' / (endpoint['host'] + '-' + str(endpoint['port']) + '-' + str(endpoint['admitted_at']) + '.json')
            changed += write_file(path, endpoint)
    changed += write_rules(config['network'] + '-egress', resolved, config, reconcile)
    if incoming is not None and reconcile:
        for path, endpoint in records:
            if endpoint['admitted_at'] < capture:
                path.unlink(missing_ok=True)
                changed += 1
        changed += write_file(root / 'config.json', {key: value for key, value in config.items() if key != 'endpoints'})
    # Only a full refresh/converge proves Node-wide health; a successful attach checks its own subset.
    if incoming is None or reconcile:
        changed += write_file(root / 'health.json', {'error_code': None})
    return changed


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--state-dir', type=Path, default=Path('/var/lib/orbit/instance-bridge'))
    parser.add_argument('--intent', action='store_true')
    parser.add_argument('--reconcile', action='store_true')
    args = parser.parse_args()
    try:
        changed = apply(args.state_dir, json.load(sys.stdin) if args.intent else None, args.reconcile)
        print(json.dumps({'changed': changed}))
    except (PolicyError, ValueError, OSError, subprocess.SubprocessError) as error:
        code = str(error) if str(error).startswith('instance.') else 'instance.container_bridge_write_failed'
        write_file(args.state_dir / 'health.json', {'error_code': code})
        print(json.dumps({'error_code': code}))
        print(str(error), file=sys.stderr)
        raise SystemExit(1) from error


if __name__ == '__main__':
    main()
