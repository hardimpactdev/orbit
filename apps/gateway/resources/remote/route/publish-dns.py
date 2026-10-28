# Program: route.publish-dns
# Uses: lib/program.py
# Outcomes: published refused
# Timeout: 60
# Input: {"records": Gateway-rendered dnsmasq records, "catalog": Gateway-rendered requester catalog}
# Result: published: {"ready": true, "digest": catalog SHA-256}; refused: {"error_code": fixed code}.
import fcntl
import hashlib
import json
import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import time

RECORDS_DIRECTORY = Path('/etc/dnsmasq.d')
RECORDS = RECORDS_DIRECTORY / 'orbit-records.conf'
CONFIGURATION = Path('/etc/dnsmasq.conf')
CATALOG = Path('/var/lib/orbit/private-dns/catalog.json')
LOCK = Path('/run/lock/orbit-dnsmasq.lock')
LISTENER = 'orbit-private-dns.service'
SOCKET = 'orbit-private-dns.socket'


def command(*args):
    return subprocess.run(args, stdin=subprocess.DEVNULL, stdout=subprocess.DEVNULL,
                          stderr=subprocess.DEVNULL, check=False).returncode == 0


def active(unit):
    return command('systemctl', 'is-active', '--quiet', unit)


def replace(path, contents, mode=0o644):
    fd, temporary = tempfile.mkstemp(prefix='.orbit-dns-', dir=path.parent)
    try:
        with os.fdopen(fd, 'wb') as output:
            output.write(contents)
            output.flush()
            os.fsync(output.fileno())
            os.fchmod(output.fileno(), mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def snapshot(path):
    if path.is_symlink():
        raise ValueError('Managed DNS data must be a regular file')
    if not path.exists():
        return None
    metadata = path.stat()
    return path.read_bytes(), metadata.st_mode & 0o7777, metadata.st_uid, metadata.st_gid


def restore(path, previous):
    if previous is None:
        path.unlink(missing_ok=True)
    else:
        content, mode, uid, gid = previous
        replace(path, content, mode)
        os.chown(path, uid, gid)


def publish(payload):
    records, catalog = payload['records'].encode(), payload['catalog'].encode()
    json.loads(catalog)
    digest = hashlib.sha256(catalog).hexdigest()
    old_records, old_catalog = snapshot(RECORDS), snapshot(CATALOG)
    was_backend, was_listener = active('dnsmasq'), active(LISTENER)
    # Listener release installation belongs to the Node footprint. Publishing data never deploys code.
    if not active(SOCKET):
        return 'refused', {'error_code': 'private_dns.listener_not_ready'}
    with tempfile.TemporaryDirectory(prefix='orbit-dns-validate-', dir='/var/tmp') as temporary:
        temporary = Path(temporary)
        fragments = temporary / 'fragments'
        shutil.copytree(RECORDS_DIRECTORY, fragments)
        (fragments / RECORDS.name).write_bytes(records)
        configuration = temporary / 'dnsmasq.conf'
        configuration.write_text(CONFIGURATION.read_text().replace(str(RECORDS_DIRECTORY), str(fragments)))
        if not command('dnsmasq', '--test', '--conf-file=' + str(configuration)):
            return 'refused', {'error_code': 'private_dns.validation_failed'}
        changed_records = old_records is None or old_records[0] != records
        changed_catalog = old_catalog is None or old_catalog[0] != catalog
        changed = False
        try:
            if changed_records:
                replace(RECORDS, records)
                changed = True
            if changed_catalog:
                replace(CATALOG, catalog)
                changed = True
            if changed_records or not was_backend:
                if not command('systemctl', 'restart', 'dnsmasq'):
                    raise RuntimeError('backend activation failed')
            confirmation = Path(str(CATALOG) + '.loaded')
            loaded = confirmation.read_text().strip() if confirmation.exists() else ''
            if loaded != digest or not was_listener:
                # systemd retains both sockets while the listener restarts.
                if not command('systemctl', 'restart', LISTENER):
                    raise RuntimeError('listener activation failed')
                deadline = time.monotonic() + 5
                while time.monotonic() < deadline:
                    if confirmation.exists() and confirmation.read_text().strip() == digest and active(LISTENER):
                        break
                    time.sleep(0.1)
                else:
                    raise RuntimeError('listener did not confirm its catalog')
            if not active('dnsmasq') or not active(SOCKET) or not active(LISTENER):
                raise RuntimeError('DNS services are not active')
            return 'published', {'ready': True, 'digest': digest}
        except (OSError, RuntimeError):
            try:
                if changed:
                    restore(RECORDS, old_records)
                    restore(CATALOG, old_catalog)
                backend = command('systemctl', 'restart' if was_backend else 'stop', 'dnsmasq')
                listener = command('systemctl', 'restart' if was_listener else 'stop', LISTENER)
                if not backend or not listener:
                    return 'refused', {'error_code': 'private_dns.recovery_failed'}
            except OSError:
                return 'refused', {'error_code': 'private_dns.recovery_failed'}
            return 'refused', {'error_code': 'private_dns.activation_failed'}


def main(payload):
    if os.geteuid() != 0:
        return 'refused', {'error_code': 'private_dns.root_required'}
    try:
        with LOCK.open('a') as lock:
            deadline = time.monotonic() + 30
            while True:
                try:
                    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                    break
                except BlockingIOError:
                    if time.monotonic() >= deadline:
                        return 'refused', {'error_code': 'private_dns.lock_failed'}
                    time.sleep(0.05)
            return publish(payload)
    except (OSError, ValueError, KeyError, TypeError):
        return 'refused', {'error_code': 'private_dns.publication_failed'}


if __name__ == '__main__':
    run_program(main)
