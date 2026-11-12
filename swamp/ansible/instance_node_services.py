"""Scoped Node-service network and socket proxies; no workspace or application work.

The phase admits network before unit writes. Pending activation survives a partial
attempt. This behavior accepts public endpoints and can serve other callers later.
"""
import json
import os
import re
import socket
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

MARKER = '# Managed by Orbit: attached Node service.\n'
PUBLIC = {'process.target_inactive', 'instance.host_unavailable',
          'instance.node_service_port_in_use', 'instance.node_service_unit_conflict',
          'instance.node_service_unreachable', 'instance.bridge_busy', 'instance.node_service_apply_failed'}
# Incus installs this type-wide drop-in in every systemd guest. It cannot replace
# ExecStart, User or a listener; all unit-specific and other effective drop-ins refuse.
LXC_DROPIN = '/run/systemd/system/service.d/zzz-lxc-service.conf'
LXC_CONTENT = ('[Service]\nProcSubset=all\nProtectProc=default\nProtectControlGroups=no\n'
               'ProtectKernelTunables=no\nNoNewPrivileges=no\nLoadCredential=\n'
               'PrivateNetwork=no\nImportCredential=\n')


def public_target(target):
    if not isinstance(target, dict):
        return {}
    value = {key: target[key] for key in ('id', 'process_id', 'port')
             if type(target.get(key)) is int and target[key] > 0}
    name = target.get('process_name')
    if isinstance(name, str) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,63}', name):
        value['process_name'] = name
    return value


class TargetFailure(RuntimeError):
    def __init__(self, code, target=None):
        super().__init__(code)
        self.target = public_target(target)

    def evidence(self):
        return {'error_code': str(self), **({'node_service_target': self.target} if self.target else {})}



def command(argv, content=None, public_guest=False):
    result = subprocess.run(argv, input=content, text=True, capture_output=True, check=False, timeout=30)
    if result.returncode and argv[:2] != ['systemctl', 'show']:
        code = result.stderr.removesuffix('\n')
        if public_guest and code in PUBLIC:
            try:
                evidence = json.loads(result.stdout)
            except (ValueError, TypeError):
                evidence = {}
            target = evidence.get('node_service_target') if isinstance(evidence, dict) and evidence.get('error_code') == code else None
            raise TargetFailure(code, target)
        raise RuntimeError('instance.node_service_apply_failed')
    return result.stdout



def activate(run, argv, target):
    try:
        run(argv)
    except (RuntimeError, OSError, subprocess.SubprocessError) as error:
        raise TargetFailure('instance.node_service_unreachable', target) from error


def atomic(path, content, mode):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    descriptor, temporary = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(descriptor, 'w') as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(content)
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def unit_name(port):
    return 'orbit-node-service-' + str(port)


def units(targets):
    files = {}
    for target in targets:
        if not target.get('docker', True):
            continue
        port = target['port']
        name = unit_name(port)
        files[name + '.socket'] = MARKER + f'''[Unit]
Description=Orbit attached Node service {port}
[Socket]
ListenStream=127.0.0.1:{port}
NoDelay=true
[Install]
WantedBy=sockets.target
'''
        files[name + '.service'] = MARKER + f'''[Unit]
Description=Orbit attached Node service proxy {port}
Requires={name}.socket
After=network.target
[Service]
ExecStart=/usr/lib/systemd/systemd-socket-proxyd --connections-max=256 {target['host']}:{port}
PrivateTmp=true
NoNewPrivileges=true
DynamicUser=yes
'''
    return files


def socket_rows(run, ports):
    if not ports:
        return {}
    names = [unit_name(p) + ext for p in ports for ext in ('.socket', '.service')]
    output = run(['systemctl', 'show', '--property=Id', '--property=ActiveState',
                  '--property=SubState', '--property=MainPID', '--property=Job',
                  '--property=FragmentPath', '--property=DropInPaths',
                  '--property=Listen', '--property=UnitFileState', '--'] + names)
    return {row.get('Id'): row for block in output.strip().split('\n\n')
            if (row := dict(line.split('=', 1) for line in block.splitlines() if '=' in line))}


def foreign_dropins(row, root):
    for name in row.get('DropInPaths', '').split():
        path = root / name.lstrip('/')
        if (name != LXC_DROPIN or path.is_symlink() or not path.is_file()
                or path.stat().st_uid != 0 or path.read_text() != LXC_CONTENT):
            return True
    return False


def service_pid(rows, port):
    row = rows.get(unit_name(port) + '.service', {})
    pid = row.get('MainPID', '')
    return int(pid) if row.get('ActiveState') == 'active' and row.get('SubState') == 'running' and not row.get('Job') and str(pid).isdigit() and int(pid) > 0 else None


def tcp_sockets(pid, root):
    """Kernel evidence owned by this proxy process, not PID 1's listening backlog."""
    inodes = set()
    for descriptor in (root / 'proc' / str(pid) / 'fd').iterdir():
        try:
            link = descriptor.readlink().as_posix()
        except FileNotFoundError:
            continue
        match = re.fullmatch(r'socket:\[([0-9]+)\]', link)
        if match:
            inodes.add(match[1])

    def address(raw):
        host, port = raw.split(':')
        return socket.inet_ntoa(bytes.fromhex(host)[::-1]), int(port, 16)

    return {parts[9]: (address(parts[1]), address(parts[2]))
            for line in (root / 'proc/net/tcp').read_text().splitlines()[1:]
            if len(parts := line.split()) > 9 and parts[3] == '01' and parts[9] in inodes}


def probe_proxy(target, root, run, connect):
    port = target['port']
    rows = socket_rows(run, [port])
    pid = service_pid(rows, port)
    if pid is None or not ready(rows.get(unit_name(port) + '.socket', {}), port):
        raise TargetFailure('instance.node_service_unreachable', target)
    try:
        before = set(tcp_sockets(pid, root))
        with connect(('127.0.0.1', port), timeout=2) as client:
            source = client.getsockname()
            # Re-read systemd after connecting: a listening PID 1 socket alone is insufficient.
            rows = socket_rows(run, [port])
            sockets = tcp_sockets(pid, root)
            accepted = any(local == ('127.0.0.1', port) and peer == source for local, peer in sockets.values())
            upstream = any(inode not in before and peer == (target['host'], port) for inode, (_, peer) in sockets.items())
            if not (accepted and upstream and service_pid(rows, port) == pid
                    and ready(rows.get(unit_name(port) + '.socket', {}), port)):
                raise TargetFailure('instance.node_service_unreachable', target)
    except OSError as error:
        raise TargetFailure('instance.node_service_unreachable', target) from error


def owns(row, port):
    return row.get('ActiveState') == 'active' and row.get('Listen') == f'127.0.0.1:{port} (Stream)'


def ready(row, port):
    return owns(row, port) and row.get('UnitFileState') == 'enabled'


def guest(action, plan, root=Path('/'), run=command, connect=socket.create_connection):
    directory = root / 'etc/systemd/system'
    desired = units(plan['targets'])
    local = [t for t in plan['targets'] if t.get('docker', True)]
    removed = set(plan.get('removed_ports', [])) - {t['port'] for t in local}
    ports = sorted({t['port'] for t in local})
    if ports and not os.access(root / 'usr/lib/systemd/systemd-socket-proxyd', os.X_OK):
        raise RuntimeError('instance.host_unavailable')
    changed = []
    rows = socket_rows(run, sorted(set(ports) | removed))
    by_port = {target['port']: target for target in local}
    # Inspect every owned path before changing anything. Never follow a foreign link.
    for name in set(desired) | {unit_name(p) + ext for p in removed for ext in ('.socket', '.service')}:
        path = directory / name
        port = int(name.removeprefix('orbit-node-service-').split('.')[0])
        effective = rows.get(name, {})
        if (effective.get('FragmentPath') not in (None, '', '/etc/systemd/system/' + name)
                or foreign_dropins(effective, root) or path.is_symlink()
                or (path.exists() and not path.read_text().startswith(MARKER))):
            raise TargetFailure('instance.node_service_unit_conflict', by_port.get(port, {'port': port}))
        if name in desired:
            info = path.stat() if path.exists() else None
            if info is None or path.read_text() != desired[name] or info.st_uid != 0 or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != 0o644:
                changed.append(name)
    if action == 'inspect':
        if ports:
            expression = '( ' + ' or '.join('sport = :' + str(p) for p in ports) + ' )'
            for line in run(['ss', '-H', '-ltn', expression]).splitlines():
                fields = line.split()
                local = fields[3] if len(fields) > 3 else ''
                for port in ports:
                    if local.rsplit(':', 1)[-1] == str(port) and not owns(rows.get(unit_name(port) + '.socket', {}), port):
                        raise TargetFailure('instance.node_service_port_in_use', by_port[port])
        return {'changed_units': sorted(changed), 'sockets': rows, 'removed': sorted(p for p in removed if any((directory / (unit_name(p) + ext)).exists() for ext in ('.socket', '.service')))}
    if action != 'install':
        raise RuntimeError('instance.node_service_apply_failed')
    mutation = False
    for port in sorted(removed):
        names = [unit_name(port) + ext for ext in ('.socket', '.service')]
        existing = [name for name in names if (directory / name).exists()]
        if existing:
            run(['systemctl', 'disable', '--now', '--', names[0]])
            run(['systemctl', 'stop', '--', names[1]])
            for name in existing:
                (directory / name).unlink()
            mutation = True
    for name in plan['changed_units']:
        atomic(directory / name, desired[name], 0o644)
        os.chown(directory / name, 0, 0, follow_symlinks=False)
    pending = plan['activation_units']
    failed = [name for name, row in rows.items() if row.get('ActiveState') == 'failed']
    if pending or mutation or failed or plan.get('pending_removal', False):
        run(['systemctl', 'daemon-reload'])
        if failed:
            run(['systemctl', 'reset-failed', '--'] + failed)
        services = [name for name in pending if name.endswith('.service')]
        for name in services:
            port = int(name.removeprefix('orbit-node-service-').split('.')[0])
            activate(run, ['systemctl', 'try-restart', '--', name], by_port[port])
    drift = [unit_name(p) + '.socket' for p in ports if not ready(rows.get(unit_name(p) + '.socket', {}), p)]
    for port in ports:
        name = unit_name(port) + '.socket'
        if name in drift:
            activate(run, ['systemctl', 'enable', '--now', '--', name], by_port[port])
    final = socket_rows(run, ports)
    for port in ports:
        if not ready(final.get(unit_name(port) + '.socket', {}), port):
            raise TargetFailure('process.target_inactive', by_port[port])
    started = []
    if plan.get('probe', True):
        for target in by_port.values():
            port = target['port']
            if service_pid(final, port) is None:
                activate(run, ['systemctl', 'start', '--', unit_name(port) + '.service'], target)
                started.append(port)
            probe_proxy(target, root, run, connect)
    environment = plan.get('proxy_environment')
    if environment is not None:
        assignments = ' '.join(key + '=' + environment[key] for key in ('NO_PROXY', 'no_proxy'))
        files = {'etc/systemd/system.conf.d/99-orbit-node-services.conf': MARKER + '[Manager]\nDefaultEnvironment=' + assignments + '\n',
                 'etc/profile.d/99-orbit-node-services.sh': MARKER + 'export ' + assignments + '\n'}
        for name, content in files.items():
            path = root / name
            if path.is_symlink() or (path.exists() and not path.read_text().startswith(MARKER)):
                raise RuntimeError('instance.node_service_unit_conflict')
            if not path.exists() or path.read_text() != content:
                atomic(path, content, 0o644)
                os.chown(path, 0, 0, follow_symlinks=False)
                mutation = True
        current = dict(line.split('=', 1) for line in run(['systemctl', 'show-environment']).splitlines() if '=' in line)
        if any(current.get(key) != value for key, value in environment.items()):
            run(['systemctl', 'set-environment'] + [key + '=' + value for key, value in environment.items()])
            mutation = True
    if plan.get('probe', True):
        for target in plan['targets']:
            if not target.get('docker', True):
                try:
                    with connect((target['host'], target['port']), timeout=2):
                        pass
                except OSError as error:
                    raise TargetFailure('instance.node_service_unreachable', target) from error
    return {'changed': bool(changed or pending or drift or mutation or failed or started or plan.get('pending_removal', False))}


def admits_endpoint(acl, endpoint):
    return any(rule.get('action') == 'allow' and rule.get('state') == 'enabled'
               and rule.get('protocol') == 'tcp' and rule.get('destination') == endpoint['host'] + '/32'
               and rule.get('destination_port') == str(endpoint['port'])
               for rule in acl.get('egress', []))


def withdrawal_endpoints(container, endpoint, acl, run, bridge_state):
    """Keep applied bridge records; only the detached tuple may be withdrawn."""
    inventories = json.loads(run(['incus', 'query', '--', '/1.0/network-acls?project=default&recursion=1']))
    retained = admits_endpoint(acl, endpoint) or any(
        re.fullmatch(r'instance-[0-9]+', other.get('name', '')) and other['name'] != container['acl']
        and admits_endpoint(other, endpoint) for other in inventories)
    records = [json.loads(path.read_text()) for path in sorted((bridge_state / 'endpoints').glob('*.json'))]
    return [{key: row[key] for key in ('host', 'port', 'docker')} for row in records
            if retained or (row['host'], row['port']) != (endpoint['host'], endpoint['port'])]


def apply(container, bundle, run=command, state=Path('/var/lib/orbit/instance-node-services'),
          bridge_state=Path('/var/lib/orbit/instance-bridge')):
    targets = container.get('node_services', [])
    removed = container.get('node_service_removed_ports', [])
    removed_hosts = container.get('node_service_removed_hosts', [])
    if not targets and not removed and not removed_hosts:
        return {'status': 'unchanged', 'ports': []}
    current = json.loads(run(['incus', 'query', '--', '/1.0/instances/' + container['name'] + '?project=' + container['project']]))
    if current['status'] != 'Running':
        raise RuntimeError('process.target_inactive')
    withdrawing = container.get('node_service_withdraw', False)
    plan = {'targets': targets, 'removed_ports': removed, 'probe': not withdrawing}
    direct = any(not t.get('docker', True) for t in targets) or bool(removed_hosts)
    if direct:
        environment = dict(entry.split('=', 1) for entry in container['environment'] if entry.startswith(('NO_PROXY=', 'no_proxy=')))
        if set(environment) != {'NO_PROXY', 'no_proxy'}:
            raise RuntimeError('instance.node_service_apply_failed')
        plan['proxy_environment'] = environment
    execute = ['incus', 'exec', '--project', container['project'], container['name'], '--', 'python3', '-c', bundle['node_services']]
    probe = json.loads(run(execute + ['inspect'], json.dumps(plan), public_guest=True))
    if targets and not withdrawing:
        incoming = {'network': container['network'], 'cidr': container['bridge_cidr'],
                    'endpoints': [{'host': t['host'], 'port': t['port'], 'docker': t.get('docker', True)} for t in targets],
                    'admitted_at': container['bridge_admitted_at'], 'baseline': {}}
        run(['python3', '-c', bundle['bridge'], '--state-dir', '/var/lib/orbit/instance-bridge', '--intent'], json.dumps(incoming), public_guest=True)
    route = '/1.0/network-acls/' + container['acl'] + '?project=default'
    acl = json.loads(run(['incus', 'query', '--', route]))
    desired = [{'action': 'allow', 'destination': t['host'] + '/32', 'protocol': 'tcp',
                'destination_port': str(t['port']), 'state': 'enabled', 'description': 'Orbit Node service:' + str(t['port'])}
               for t in {(t['host'], t['port']): t for t in targets}.values()]
    prior = [r for r in acl['egress'] if r.get('description', '').startswith('Orbit Node service:')]
    def normalize(rules):
        return sorted(json.dumps({k: v for k, v in r.items() if v != ''}, sort_keys=True) for r in rules)

    acl_changed = normalize(prior) != normalize(desired)
    if acl_changed:
        acl = {k: acl[k] for k in ('description', 'ingress', 'egress')}
        acl['egress'] = [r for r in acl['egress'] if not r.get('description', '').startswith('Orbit Node service:')] + desired
        run(['incus', 'network', 'acl', 'edit', container['acl'], '--project', 'default'], json.dumps(acl))
    if withdrawing and removed_hosts:
        endpoint = container['node_service_withdraw_endpoint']
        incoming = {'network': container['network'], 'cidr': container['bridge_cidr'],
                    'endpoints': withdrawal_endpoints(container, endpoint, acl, run, bridge_state),
                    'admitted_at': container['bridge_admitted_at'], 'baseline': {}}
        run(['python3', '-c', bundle['bridge'], '--state-dir', str(bridge_state),
             '--intent', '--reconcile', '--withdraw'], json.dumps(incoming), public_guest=True)
        for rule in container.get('node_service_removed_rules', []):
            if admits_endpoint(acl, {'host': rule['to_ip'], 'port': rule['to_port']}):
                continue
            run(['ufw', '--force', 'route', 'delete', rule['rule'], 'in', 'on', rule['interface_in'],
                 'out', 'on', rule['interface_out'], 'from', rule['from_ip'],
                 'to', rule['to_ip'], 'port', rule['to_port'], 'proto', rule['proto']])
    config_changed = False
    if direct:
        changes = [f'environment.{key}={value}' for key, value in plan['proxy_environment'].items()
                   if current.get('config', {}).get('environment.' + key) != value]
        if changes:
            run(['incus', 'config', 'set', container['name'], '--project', container['project']] + changes)
            config_changed = True
    pending = state / container['name'] / 'activation.json'
    previous = json.loads(pending.read_text()) if pending.exists() else {'units': [], 'removed': []}
    activation = sorted((set(previous['units']) | set(probe['changed_units'])) & set(units(targets)))
    pending_removed = sorted(set(previous['removed']) | set(probe['removed']))
    if activation or pending_removed:
        atomic(pending, json.dumps({'units': activation, 'removed': pending_removed}), 0o600)
    result = json.loads(run(execute + ['install'], json.dumps(plan | probe | {'activation_units': activation, 'removed_ports': removed, 'pending_removal': bool(pending_removed)}), public_guest=True))
    pending.unlink(missing_ok=True)
    return {'status': 'applied' if acl_changed or config_changed or result['changed'] else 'unchanged',
            'ports': sorted({t['port'] for t in targets})}


def main():
    try:
        result = guest(sys.argv[1], json.load(sys.stdin)) if sys.argv[1] in ('inspect', 'install') else apply(json.loads(sys.argv[1]), json.loads(sys.argv[2]))
        print(json.dumps(result))
    except Exception as error:  # noqa: BLE001 - public diagnostic boundary; never echo tool output.
        code = str(error)
        if isinstance(error, TargetFailure):
            print(json.dumps(error.evidence()))
        print(code if code in PUBLIC else 'instance.node_service_apply_failed', file=sys.stderr)
        raise SystemExit(1)


if __name__ == '__main__':
    main()
