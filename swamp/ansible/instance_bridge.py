#!/usr/bin/env python3
"""Keep Incus bridge endpoint rules in step with admitted intent and Docker DNAT.

One attempt only. Ansible owns admission retries; the systemd timer serializes refreshes.
The refresh only adds rules. Only host reconciliation removes endpoint rules or intent.
"""
import argparse
import ipaddress
import json
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


def endpoint_rule(host, port, cidr):
    return {'action': 'allow', 'source': cidr, 'destination': host + '/32', 'protocol': 'tcp',
                'destination_port': str(port), 'state': 'enabled'}


def plan(current, endpoints, cidr, baseline, reconcile):
    desired = {'egress': [endpoint_rule(host, port, cidr) for host, port in endpoints], 'ingress': []}
    for direction in desired:
        desired[direction] += baseline.get(direction, [])
    changes = []
    for direction, wanted in desired.items():
        existing = current.get(direction, [])
        for rule in wanted:
            if not any(all(row.get(key) == value for key, value in rule.items()) for row in existing):
                changes.append(('add', direction, rule))
    if reconcile:
        for raw in current.get('egress', []):
            rule = {key: value for key, value in raw.items() if value != ''}
            if (set(rule) == {'action', 'source', 'destination', 'protocol', 'destination_port', 'state'}
                    and rule['action'] == 'allow' and rule['source'] == cidr and rule['protocol'] == 'tcp'
                    and rule['state'] == 'enabled' and rule['destination'].endswith('/32')
                    and rule['destination_port'].isdigit() and rule not in desired['egress']):
                changes.append(('remove', 'egress', rule))
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
    current, _ = api(name)
    changes = plan(current, resolved, config['cidr'], config.get('baseline', {}), reconcile)
    changed = 0
    for action, direction, rule in changes:
        # Reread just before each conditional PUT. The If-Match binds the exact list we merged.
        current, etag = api(name)
        def matches(row, rule=rule):
            return all(row.get(key) == value for key, value in rule.items())
        present = any(matches(row) for row in current.get(direction, []))
        if present == (action == 'add'):
            continue
        updated = {key: current.get(key, {} if key == 'config' else [] if key in ['egress', 'ingress'] else '')
                   for key in ['description', 'config', 'egress', 'ingress']}
        updated[direction] = ([*updated[direction], rule] if action == 'add'
                              else [row for row in updated[direction] if not matches(row)])
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
    files = list((root / 'endpoints').glob('*.json'))
    endpoints = (incoming['endpoints'] if incoming is not None
                 else [json.loads(path.read_text()) for path in files])
    nat = subprocess.check_output(['iptables-save', '-t', 'nat'], text=True) if any(row['docker'] for row in endpoints) else ''
    resolved = resolve(endpoints, nat)  # Resolve the entire set before any file or ACL mutation.
    changed = write_rules(config['network'] + '-egress', resolved, config, reconcile)
    if incoming is not None:
        desired_files = set()
        for endpoint in endpoints:
            path = root / 'endpoints' / (endpoint['host'] + '-' + str(endpoint['port']) + '.json')
            desired_files.add(path)
            changed += write_file(path, endpoint)
        if reconcile:
            for path in files:
                if path not in desired_files:
                    path.unlink()
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
