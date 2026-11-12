"""Scoped Node-service network and socket proxies; no workspace or application work.

The phase admits network before unit writes. Pending activation survives a partial
attempt. This behavior accepts public endpoints and can serve other callers later.
"""
import json
import os
import socket
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

MARKER = '# Managed by Orbit: attached Node service.\n'
PUBLIC = {'process.target_inactive', 'instance.host_unavailable',
          'instance.node_service_port_in_use', 'instance.node_service_unit_conflict',
          'instance.node_service_unreachable', 'instance.bridge_busy'}


def command(argv, content=None, public_guest=False):
    result = subprocess.run(argv, input=content, text=True, capture_output=True, check=False, timeout=30)
    if result.returncode and argv[:2] != ['systemctl', 'show']:
        code = result.stderr.removesuffix('\n')
        raise RuntimeError(code if public_guest and code in PUBLIC else 'instance.node_service_apply_failed')
    return result.stdout


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
'''
    return files


def socket_rows(run, ports):
    if not ports:
        return {}
    output = run(['systemctl', 'show', '--property=Id', '--property=ActiveState',
                  '--property=Listen', '--property=UnitFileState', '--'] + [unit_name(p) + '.socket' for p in ports])
    return {row.get('Id'): row for block in output.strip().split('\n\n')
            if (row := dict(line.split('=', 1) for line in block.splitlines() if '=' in line))}


def owns(row, port):
    return row.get('ActiveState') == 'active' and row.get('Listen') == f'127.0.0.1:{port} (Stream)'


def ready(row, port):
    return owns(row, port) and row.get('UnitFileState') == 'enabled'


def guest(action, plan, root=Path('/'), run=command, connect=socket.create_connection):
    directory = root / 'etc/systemd/system'
    desired = units(plan['targets'])
    removed = set(plan.get('removed_ports', [])) - {t['port'] for t in plan['targets']}
    ports = sorted({t['port'] for t in plan['targets']})
    if ports and not os.access(root / 'usr/lib/systemd/systemd-socket-proxyd', os.X_OK):
        raise RuntimeError('instance.host_unavailable')
    changed = []
    # Inspect every owned path before changing anything. Never follow a foreign link.
    for name in set(desired) | {unit_name(p) + ext for p in removed for ext in ('.socket', '.service')}:
        path = directory / name
        if path.is_symlink() or (path.exists() and not path.read_text().startswith(MARKER)):
            raise RuntimeError('instance.node_service_unit_conflict')
        if name in desired:
            info = path.stat() if path.exists() else None
            if info is None or path.read_text() != desired[name] or info.st_uid != 0 or info.st_gid != 0 or stat.S_IMODE(info.st_mode) != 0o644:
                changed.append(name)
    rows = socket_rows(run, ports)
    if action == 'inspect':
        if ports:
            expression = '( ' + ' or '.join('sport = :' + str(p) for p in ports) + ' )'
            for line in run(['ss', '-H', '-ltn', expression]).splitlines():
                fields = line.split()
                local = fields[3] if len(fields) > 3 else ''
                for port in ports:
                    if local.rsplit(':', 1)[-1] == str(port) and not owns(rows.get(unit_name(port) + '.socket', {}), port):
                        raise RuntimeError('instance.node_service_port_in_use')
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
    if pending or mutation or plan.get('pending_removal', False):
        run(['systemctl', 'daemon-reload'])
        services = [name for name in pending if name.endswith('.service')]
        if services:
            run(['systemctl', 'try-restart', '--'] + services)
    drift = [unit_name(p) + '.socket' for p in ports if not ready(rows.get(unit_name(p) + '.socket', {}), p)]
    if drift:
        run(['systemctl', 'enable', '--now', '--'] + drift)
    final = socket_rows(run, ports)
    if not all(ready(final.get(unit_name(p) + '.socket', {}), p) for p in ports):
        raise RuntimeError('process.target_inactive')
    if plan.get('probe', True):
        for target in plan['targets']:
            try:
                # A local accept alone cannot prove a socket proxy's upstream; check both exact hops.
                for address in [(target['host'], target['port']), ('127.0.0.1', target['port'])]:
                    with connect(address, timeout=2):
                        pass
            except OSError as error:
                raise RuntimeError('instance.node_service_unreachable') from error
    return {'changed': bool(changed or pending or drift or mutation or plan.get('pending_removal', False))}


def apply(container, bundle, run=command, state=Path('/var/lib/orbit/instance-node-services')):
    targets = container.get('node_services', [])
    removed = container.get('node_service_removed_ports', [])
    if not targets and not removed:
        return {'status': 'unchanged', 'ports': []}
    current = json.loads(run(['incus', 'query', '--', '/1.0/instances/' + container['name'] + '?project=' + container['project']]))
    if current['status'] != 'Running':
        raise RuntimeError('process.target_inactive')
    withdrawing = container.get('node_service_withdraw', False)
    plan = {'targets': targets, 'removed_ports': removed, 'probe': not withdrawing}
    execute = ['incus', 'exec', '--project', container['project'], container['name'], '--', 'python3', '-c', bundle['node_services']]
    probe = json.loads(run(execute + ['inspect'], json.dumps(plan), public_guest=True))
    if targets and not withdrawing:
        incoming = {'network': container['network'], 'cidr': container['bridge_cidr'],
                    'endpoints': [{'host': t['host'], 'port': t['port'], 'docker': True} for t in targets],
                    'admitted_at': container['bridge_admitted_at'], 'baseline': {}}
        run(['python3', '-c', bundle['bridge'], '--state-dir', '/var/lib/orbit/instance-bridge', '--intent'], json.dumps(incoming), public_guest=True)
    route = '/1.0/network-acls/' + container['acl'] + '?project=default'
    acl = json.loads(run(['incus', 'query', '--', route]))
    desired = [{'action': 'allow', 'destination': t['host'] + '/32', 'protocol': 'tcp',
                'destination_port': str(t['port']), 'state': 'enabled', 'description': 'Orbit Node service:' + str(t['port'])}
               for t in {t['port']: t for t in targets}.values()]
    prior = [r for r in acl['egress'] if r.get('description', '').startswith('Orbit Node service:')]
    def normalize(rules):
        return sorted(json.dumps({k: v for k, v in r.items() if v != ''}, sort_keys=True) for r in rules)

    acl_changed = normalize(prior) != normalize(desired)
    if acl_changed:
        acl = {k: acl[k] for k in ('description', 'ingress', 'egress')}
        acl['egress'] = [r for r in acl['egress'] if not r.get('description', '').startswith('Orbit Node service:')] + desired
        run(['incus', 'network', 'acl', 'edit', container['acl'], '--project', 'default'], json.dumps(acl))
    pending = state / container['name'] / 'activation.json'
    previous = json.loads(pending.read_text()) if pending.exists() else {'units': [], 'removed': []}
    activation = sorted((set(previous['units']) | set(probe['changed_units'])) & set(units(targets)))
    pending_removed = sorted(set(previous['removed']) | set(probe['removed']))
    if activation or pending_removed:
        atomic(pending, json.dumps({'units': activation, 'removed': pending_removed}), 0o600)
    result = json.loads(run(execute + ['install'], json.dumps(plan | probe | {'activation_units': activation, 'removed_ports': removed, 'pending_removal': bool(pending_removed)}), public_guest=True))
    pending.unlink(missing_ok=True)
    return {'status': 'applied' if acl_changed or result['changed'] else 'unchanged',
            'ports': sorted({t['port'] for t in targets})}


def main():
    try:
        result = guest(sys.argv[1], json.load(sys.stdin)) if sys.argv[1] in ('inspect', 'install') else apply(json.loads(sys.argv[1]), json.loads(sys.argv[2]))
        print(json.dumps(result))
    except Exception as error:  # noqa: BLE001 - public diagnostic boundary; never echo tool output.
        code = str(error)
        print(code if code in PUBLIC else 'instance.node_service_apply_failed', file=sys.stderr)
        raise SystemExit(1)


if __name__ == '__main__':
    main()
