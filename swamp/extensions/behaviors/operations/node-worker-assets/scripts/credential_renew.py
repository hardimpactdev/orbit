"""Renew the Swamp host's own worker credentials natively, then report alerts. Values never leave memory."""

import datetime
import json
import os
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

SWAMP = '/home/orbit/.local/bin/swamp'
REPO = '/home/orbit/apps/orbit-monorepo-staging/checkout/swamp'
SERVE = ['--server', 'ws://127.0.0.1:9418', '--token-file', '/etc/orbit/swamp-issuer/operator.token', '--json']
ISSUER_ENV = {'SWAMP_API_KEY_FILE': '/etc/orbit/swamp-issuer/api-key', 'SWAMP_NO_UPDATE_CHECK': '1',
              'SWAMP_NO_TELEMETRY': '1', 'SWAMP_SERVE_TIMEOUT_MS': '50000'}
AS_ORBIT = ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit', 'SWAMP_HOME=/home/orbit/.swamp',
            'SWAMP_CONFIG_DIR=/home/orbit/.config/swamp', 'SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key',
            'SWAMP_NO_UPDATE_CHECK=1', 'SWAMP_NO_TELEMETRY=1', SWAMP]
BACKUPS = Path('/var/lib/orbit-swamp-worker/credential-backups')
OWNER = 0
DURATION = '24h'
# Only credentials whose custody lives on this host, as repaired by hand on 2026-11-01.
RENEWABLE = {
    ('access', 'orbit-node-209-access'): '/etc/orbit/swamp-worker/server.token',
    ('enrollment', 'orbit-node-209'): '/etc/orbit/swamp-worker/enrollment.token',
}
DUE = ('within_6h', 'expired')


class Refused(Exception):
    """A public reason; never carries a value or command output."""


def native(label: str, argv: list, env: dict) -> dict:
    try:
        completed = subprocess.run(argv, cwd=REPO, env=env, capture_output=True, text=True, timeout=60, check=False)
    except subprocess.TimeoutExpired as error:
        raise Refused(f'{label}_timed_out') from error
    if completed.returncode != 0:
        raise Refused(f'{label}_exit_{completed.returncode}')
    body = json.loads(completed.stdout)
    if not isinstance(body, dict):
        raise Refused(f'{label}_response_invalid')
    return body


def served(label: str, command: list) -> dict:
    return native(label, [SWAMP, *command, *SERVE], {**os.environ, **ISSUER_ENV})


def local(label: str, command: list) -> dict:
    return native(label, [*AS_ORBIT, *command, '--yes', '--repo-dir', REPO, '--json'],
                  {**os.environ, 'SWAMP_SERVE_URL': '', 'SWAMP_SERVER_URL': ''})


def checked(value: object, name: str) -> str:
    if not isinstance(value, str) or not value.startswith(name + '.') or '\n' in value or len(value) >= 4096:
        raise Refused('issued_value_identity_invalid')
    return value


def issue(kind: str, name: str, cli=None) -> tuple:
    """Return (value, expires_at) through the native issuer; argv carries names only."""
    cli = cli or {'served': served, 'local': local}
    if kind == 'access':
        rotated = cli['served']('rotate', ['access', 'token', 'rotate', name, '--duration', DURATION])
        if rotated.get('name') != name:
            raise Refused('rotation_identity_mismatch')
        revealed = cli['local']('reveal', ['access', 'token', 'reveal', name])
        return checked(revealed.get('token'), name), rotated.get('expiresAt')
    cli['served']('revoke', ['worker', 'token', 'revoke', name])
    created = cli['served']('create', ['worker', 'token', 'create', name, '--duration', DURATION])
    return checked(created.get('token'), name), created.get('expiresAt')


def backup(target: Path, before: bytes, root: Path, now: datetime.datetime) -> Path:
    directory = root / now.strftime('%Y%m%d')
    directory.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(directory, 0o700)
    path = directory / f'{target.name}.{now.strftime("%H%M%S")}.before'
    with open(path, 'xb', opener=lambda file, flags: os.open(file, flags, 0o600)) as stream:
        stream.write(before)
        stream.flush()
        os.fsync(stream.fileno())
    return path


def write(target: Path, data: bytes, owner: int) -> None:
    descriptor, staged = tempfile.mkstemp(prefix='.' + target.name + '-', dir=target.parent)
    try:
        with os.fdopen(descriptor, 'wb') as stream:
            os.fchmod(stream.fileno(), 0o600)
            os.fchown(stream.fileno(), owner, target.stat().st_gid)
            stream.write(data)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(staged, target)
        directory = os.open(target.parent, os.O_RDONLY | os.O_DIRECTORY)
        try:
            os.fsync(directory)
        finally:
            os.close(directory)
    finally:
        Path(staged).unlink(missing_ok=True)


def readback(target: Path, data: bytes, owner: int) -> None:
    status = target.stat()
    if target.read_bytes() != data or status.st_uid != owner or stat.S_IMODE(status.st_mode) != 0o600:
        raise Refused('custody_readback_failed')


def renew(kind: str, name: str, path: str, now: datetime.datetime, cli=None, root: Path = BACKUPS,
          owner: int = OWNER) -> dict:
    target = Path(path)
    before = target.read_bytes()
    status = target.stat()
    if status.st_uid != owner or stat.S_IMODE(status.st_mode) != 0o600 or not before.startswith((name + '.').encode()):
        raise Refused('custody_changed')
    backup(target, before, root, now)
    value, expires_at = issue(kind, name, cli)
    if target.read_bytes() != before:
        raise Refused('custody_changed_since_backup')
    data = (value + '\n').encode()
    write(target, data, owner)
    readback(target, data, owner)
    return {'kind': kind, 'name': name, 'outcome': 'renewed', 'expires_at': expires_at}


def alerts(credentials: list, renewals: list) -> list:
    """Renewable credentials alert when missing, on failure or after an expiry; all others alert within 72 h."""
    outcomes = {(item['kind'], item['name']): item for item in renewals}
    listed = {(item['kind'], item['name']) for item in credentials}
    raised = [{'kind': kind, 'name': name, 'reason': 'missing', 'expires_at': None, 'hours_left': None}
              for kind, name in RENEWABLE if (kind, name) not in listed]
    for credential in credentials:
        key = (credential['kind'], credential['name'])
        if credential['class'] in ('ok', 'no_expiry'):
            continue
        if key in RENEWABLE:
            outcome = outcomes.get(key, {}).get('outcome')
            if credential['class'] == 'expired':
                reason = 'expired_renewed' if outcome == 'renewed' else 'expired'
            elif outcome == 'failed':
                reason = 'renewal_failed'
            else:
                continue
        else:
            reason = credential['class']
        raised.append({'kind': credential['kind'], 'name': credential['name'], 'reason': reason,
                       'expires_at': credential['expires_at'], 'hours_left': credential['hours_left']})
    return raised


def maintain(report: dict, now: datetime.datetime, cli=None, root: Path = BACKUPS, owner: int = OWNER) -> dict:
    credentials = report['credentials']
    renewals = []
    for credential in credentials:
        key = (credential['kind'], credential['name'])
        if key not in RENEWABLE or credential['class'] not in DUE:
            continue
        try:
            renewals.append(renew(*key, RENEWABLE[key], now, cli, root, owner))
        except Exception as error:  # noqa: BLE001 - one public reason; never a value or command output
            reason = str(error) if isinstance(error, Refused) else type(error).__name__
            print(f'{key[1]} renewal failed: {reason}', file=sys.stderr)
            renewals.append({'kind': key[0], 'name': key[1], 'outcome': 'failed', 'error': reason})
    return {'checked_at': report['checked_at'], 'credentials': credentials, 'renewals': renewals,
            'alerts': alerts(credentials, renewals)}


if __name__ == '__main__':
    raw = os.environ.get('ORBIT_EXPIRY_REPORT', '')
    if not 0 < len(raw) <= 1048576:
        print('credential report is missing or larger than 1 MiB', file=sys.stderr)
        sys.exit(1)
    print(json.dumps(maintain(json.loads(raw), datetime.datetime.now(datetime.UTC))))
