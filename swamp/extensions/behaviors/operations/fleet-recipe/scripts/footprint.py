import hashlib
import ipaddress
import json
import os
import shutil
import socket
import stat
import struct
import subprocess
import sys
import tempfile
from pathlib import Path

ROOT = Path('/var/lib/orbit/private-dns/releases')
SERVICE = Path('/etc/systemd/system/orbit-private-dns.service')
SOCKET = Path('/etc/systemd/system/orbit-private-dns.socket')
TMPFILES = Path('/etc/tmpfiles.d/orbit.conf')
UID, GID = (0, 0)

class Invalid(Exception):
    pass

def current(path, data):
    try:
        m = path.lstat()
        return stat.S_ISREG(m.st_mode) and (m.st_uid, m.st_gid, m.st_mode & 4095) == (UID, GID, 420) and (path.read_bytes() == data)
    except FileNotFoundError:
        return False

def replace(path, data, mode=420):
    fd, candidate = tempfile.mkstemp(prefix='.orbit-footprint-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as stream:
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
            os.fchmod(stream.fileno(), mode)
            os.fchown(stream.fileno(), UID, GID)
        os.replace(candidate, path)
    finally:
        if os.path.exists(candidate):
            os.unlink(candidate)

def directory(path):
    if path == path.parent:
        return
    directory(path.parent)
    try:
        m = path.lstat()
    except FileNotFoundError:
        path.mkdir(mode=493)
        os.chown(path, UID, GID)
        return
    if not stat.S_ISDIR(m.st_mode) or (m.st_uid, m.st_gid) != (UID, GID):
        raise Invalid()

def command(*args):
    return subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, check=False).returncode == 0

def tmpfiles(payload):
    if not isinstance(payload, dict) or set(payload) != {'rule'}:
        raise Invalid()
    rule = payload['rule']
    if not isinstance(rule, str) or not rule or '\x00' in rule or any((line and (not line.startswith(('#', 'e /tmp/orbit-', 'e /dev/shm/orbit-'))) for line in rule.splitlines())):
        raise Invalid()
    data = rule.encode()
    if current(TMPFILES, data):
        return {'changed': False}
    replace(TMPFILES, data)
    return {'changed': True}

def units(release, address):
    service = '[Unit]\nDescription=Orbit private DNS\nAfter=network-online.target wg-quick@orbit.service dnsmasq.service orbit-private-dns.socket\nWants=network-online.target\nRequires=orbit-private-dns.socket\n\n[Service]\nType=simple\nUser=root\nWorkingDirectory=' + release + '\nExecStart="/usr/bin/php8.5" "' + release + '/serve.php" "--listen=' + address + '" "--port=53" "--catalog=/var/lib/orbit/private-dns/catalog.json" "--upstream=127.0.0.55:53"\nSockets=orbit-private-dns.socket\nKillSignal=SIGTERM\nTimeoutStopSec=5\nRestart=on-failure\nRestartSec=2\n\n[Install]\nWantedBy=multi-user.target\n'
    socket = '[Unit]\nDescription=Orbit private DNS sockets\n\n[Socket]\nListenDatagram=' + address + ':53\nListenStream=' + address + ':53\nFreeBind=yes\nService=orbit-private-dns.service\n\n[Install]\nWantedBy=sockets.target\n'
    return (service, socket)

def validate(payload):
    if not isinstance(payload, dict) or set(payload) != {'release_id', 'files', 'service', 'socket'}:
        raise Invalid()
    rid, files = (payload['release_id'], payload['files'])
    if not isinstance(rid, str) or len(rid) != 16 or any((c not in '0123456789abcdef' for c in rid)) or (not isinstance(files, list)) or (not 1 <= len(files) <= 128):
        raise Invalid()
    identity, manifest, previous, total = ('', '', '', 0)
    entry = False
    for f in files:
        if not isinstance(f, dict) or set(f) != {'path', 'contents', 'sha256'}:
            raise Invalid()
        path, data, digest = (f['path'], f['contents'], f['sha256'])
        if not all((isinstance(x, str) for x in (path, data, digest))):
            raise Invalid()
        total += len(data.encode())
        if total > 3 * 1024 * 1024 or path <= previous or '\x00' in path or (not (path == 'serve.php' or path.startswith('app/'))) or (not path.endswith('.php')) or any((p in ('', '..', '.') for p in path.split('/'))) or (hashlib.sha256(data.encode()).hexdigest() != digest):
            raise Invalid()
        previous = path
        entry |= path == 'serve.php'
        identity += path + '\x00' + digest + '\n'
        manifest += digest + '  ' + path + '\n'
    if not entry or hashlib.sha256(identity.encode()).hexdigest()[:16] != rid:
        raise Invalid()
    try:
        address = next((line[len('ListenDatagram='):-3] for line in payload['socket'].splitlines() if line.startswith('ListenDatagram=') and line.endswith(':53')))
        address = str(ipaddress.IPv4Address(address))
    except (ValueError, StopIteration, AttributeError):
        raise Invalid()
    if units('/var/lib/orbit/private-dns/releases/' + rid, address) != (payload['service'], payload['socket']):
        raise Invalid()
    return manifest.encode()

def previous(path, marker):
    try:
        m = path.lstat()
    except FileNotFoundError:
        return None
    if not stat.S_ISREG(m.st_mode) or (m.st_uid, m.st_gid) != (UID, GID):
        raise Invalid()
    data = path.read_bytes()
    if not data.startswith(marker):
        raise Invalid()
    return (data, m.st_mode & 4095)

def restore(path, before):
    try:
        if before is None:
            path.unlink(missing_ok=True)
        else:
            replace(path, *before)
        return True
    except OSError:
        return False

def listener(payload):
    manifest = validate(payload)
    old_service = previous(SERVICE, b'[Unit]\nDescription=Orbit private DNS\n')
    old_socket = previous(SOCKET, b'[Unit]\nDescription=Orbit private DNS sockets\n')
    directory(ROOT)
    directory(SERVICE.parent)
    release = ROOT / payload['release_id']
    try:
        m = release.lstat()
        if not stat.S_ISDIR(m.st_mode) or m.st_uid != UID:
            raise Invalid()
    except FileNotFoundError:
        pass
    release_changed = not current(release / '.manifest', manifest) or any((not current(release / f['path'], f['contents'].encode()) for f in payload['files']))
    changed = release_changed or not current(SERVICE, payload['service'].encode()) or (not current(SOCKET, payload['socket'].encode()))
    staged = Path(tempfile.mkdtemp(prefix='.' + payload['release_id'] + '-', dir=ROOT))
    os.chown(staged, UID, GID)
    backup = ROOT / (staged.name + '-previous')
    try:
        for f in payload['files']:
            path = staged / f['path']
            directory(path.parent)
            replace(path, f['contents'].encode())
        replace(staged / '.manifest', manifest)
        staged_service, staged_socket = (staged / SERVICE.name, staged / SOCKET.name)
        replace(staged_service, payload['service'].encode())
        replace(staged_socket, payload['socket'].encode())
        if not command('/usr/bin/php8.5', str(staged / 'serve.php'), '--self-test') or not command('systemd-analyze', 'verify', str(staged_socket), str(staged_service)):
            raise RuntimeError()
        if not changed:
            return {'installed': True, 'changed': False}
        active = command('systemctl', 'is-active', '--quiet', SERVICE.name)
        staged_service.unlink()
        staged_socket.unlink()
        if release_changed:
            if release.exists():
                release.rename(backup)
            try:
                staged.rename(release)
            except OSError:
                if backup.exists():
                    backup.rename(release)
                raise
        try:
            replace(SERVICE, payload['service'].encode())
            replace(SOCKET, payload['socket'].encode())
            if not command('systemctl', 'daemon-reload') or (active and (not command('systemctl', 'restart', SERVICE.name))):
                raise RuntimeError()
        except Exception:
            restored = restore(SERVICE, old_service) & restore(SOCKET, old_socket)
            if backup.exists():
                shutil.rmtree(release)
                backup.rename(release)
            restored = command('systemctl', 'daemon-reload') and restored
            restored = (not active or command('systemctl', 'restart', SERVICE.name)) and restored
            raise RuntimeError()
        if backup.exists():
            shutil.rmtree(backup)
        return {'installed': True, 'changed': True}
    finally:
        if staged.exists():
            shutil.rmtree(staged)

def dns_validate(payload):
    records, catalog = Path('/etc/dnsmasq.d/orbit-records.conf'), Path('/var/lib/orbit/private-dns/catalog.json')
    json.loads(payload['catalog'])
    before = {}
    for name, path in (('records', records), ('catalog', catalog)):
        if path.is_symlink():
            raise Invalid()
        before[name] = None if not path.exists() else {'content': path.read_text(), 'mode': path.stat().st_mode & 4095}
    with tempfile.TemporaryDirectory(prefix='orbit-dns-validate-') as temporary:
        temporary = Path(temporary)
        fragments = temporary / 'fragments'
        shutil.copytree(records.parent, fragments)
        (fragments / records.name).write_text(payload['records'])
        configuration = temporary / 'dnsmasq.conf'
        configuration.write_text(Path('/etc/dnsmasq.conf').read_text().replace(str(records.parent), str(fragments)))
        if not command('/usr/sbin/dnsmasq', '--test', '--conf-file=' + str(configuration)):
            raise Invalid()
    units = ('dnsmasq.service', 'orbit-private-dns.socket', 'orbit-private-dns.service')
    before['commands'] = [['/usr/bin/systemctl', 'restart' if command('/usr/bin/systemctl', 'is-active', '--quiet', unit) else 'stop', unit] for unit in units]
    before['commands'].append(['/usr/bin/systemctl', 'enable' if command('/usr/bin/systemctl', 'is-enabled', '--quiet', units[1]) else 'disable', units[1]])
    return {'ready': True, 'before': before}

def dns_restore(payload):
    for name, path in (('records', Path('/etc/dnsmasq.d/orbit-records.conf')), ('catalog', Path('/var/lib/orbit/private-dns/catalog.json'))):
        previous = payload[name]
        if previous is None:
            path.unlink(missing_ok=True)
        else:
            replace(path, previous['content'].encode(), previous['mode'])
    return {'ready': True}

def dns_health(payload):
    catalog = Path('/var/lib/orbit/private-dns/catalog.json')
    digest = hashlib.sha256(payload['catalog'].encode()).hexdigest()
    endpoint = next(line.split('=', 1)[1] for line in SOCKET.read_text().splitlines() if line.startswith('ListenDatagram='))
    address, port = endpoint.rsplit(':', 1)
    transaction = os.urandom(2)
    query = transaction + struct.pack('!HHHHH', 0x0100, 1, 0, 0, 0)
    query += b'\x0borbit-probe\x07invalid\x00' + struct.pack('!HH', 1, 1)
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as client:
        client.settimeout(1)
        client.connect((address, int(port)))
        client.send(query)
        response = client.recv(4096)
    if len(response) < 12 or response[:2] != transaction or not response[2] & 0x80:
        raise Invalid()
    if Path(str(catalog) + '.loaded').read_text().strip() != digest:
        raise Invalid()
    return {'ready': True, 'digest': digest}

def main():
    name = sys.argv[1]
    prefix = 'node.tmpfiles' if name == 'node.publish-tmpfiles' else 'node.private_dns_listener' if name == 'node.install-private-dns-listener' else 'node.private_dns'
    try:
        payload = json.load(sys.stdin)
        result = tmpfiles(payload) if name == 'node.publish-tmpfiles' else dns_health(payload) if name == 'node.private-dns-health' else dns_validate(payload) if name == 'node.private-dns-validate' else dns_restore(payload) if name == 'node.private-dns-restore' else listener(payload)
    except Invalid:
        result = {'changed': False, 'error_code': prefix + '_invalid'}
    except Exception:
        result = {'changed': False, 'error_code': prefix + '_failed'}
    print(json.dumps(result))
if __name__ == '__main__':
    main()
