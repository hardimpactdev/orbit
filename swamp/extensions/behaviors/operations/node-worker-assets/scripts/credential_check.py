"""Read Swamp-side credential expiry from native metadata on Node 209. Never reads a value."""

import datetime
import json
import os
import subprocess
import sys

SWAMP = '/home/orbit/.local/bin/swamp'
REPO = '/home/orbit/apps/orbit-monorepo-staging/checkout/swamp'
SERVE = ['--server', 'ws://127.0.0.1:9418', '--token-file', '/etc/orbit/swamp-issuer/operator.token', '--json']
ISSUER_ENV = {'SWAMP_API_KEY_FILE': '/etc/orbit/swamp-issuer/api-key', 'SWAMP_NO_UPDATE_CHECK': '1',
              'SWAMP_NO_TELEMETRY': '1', 'SWAMP_SERVE_TIMEOUT_MS': '60000'}
AS_ORBIT = ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit', 'SWAMP_HOME=/home/orbit/.swamp',
            'SWAMP_CONFIG_DIR=/home/orbit/.config/swamp', 'SWAMP_API_KEY_FILE=/home/orbit/.config/swamp/api-key',
            'SWAMP_NO_UPDATE_CHECK=1', 'SWAMP_NO_TELEMETRY=1', SWAMP]
CERTIFICATE = '/home/orbit/.config/swamp/serve-tls/cert.pem'
ALERT_HOURS = 72
RENEW_HOURS = 6


class CheckFailed(Exception):
    """A public reason; never carries command output."""


def run(label: str, argv: list, env: dict | None = None) -> str:
    try:
        completed = subprocess.run(argv, cwd=REPO, env=env, capture_output=True, text=True, timeout=60, check=False)
    except subprocess.TimeoutExpired as error:
        raise CheckFailed(f'{label} did not finish in 60 s') from error
    if completed.returncode != 0:
        raise CheckFailed(f'{label} exited {completed.returncode}')
    return completed.stdout


def rows(stdout: str) -> list:
    data = json.loads(stdout)
    listed = data.get('tokens', []) if isinstance(data, dict) else data
    if not isinstance(listed, list):
        raise CheckFailed('native list has no token rows')
    return listed


def instant(value: str | None) -> datetime.datetime | None:
    return None if value is None else datetime.datetime.fromisoformat(value)


def classify(expires_at: datetime.datetime | None, now: datetime.datetime) -> tuple:
    if expires_at is None:
        return 'no_expiry', None
    hours = (expires_at - now).total_seconds() / 3600
    if hours <= 0:
        return 'expired', round(hours, 1)
    if hours <= RENEW_HOURS:
        return 'within_6h', round(hours, 1)
    return ('within_72h' if hours <= ALERT_HOURS else 'ok'), round(hours, 1)


def record(kind: str, row: dict, now: datetime.datetime) -> dict:
    expires_at = instant(row.get('expiresAt'))
    category, hours = classify(expires_at, now)
    return {'kind': kind, 'name': row['name'], 'principal': row.get('principalId'),
            'state': row.get('effectiveState', row.get('state')),
            'expires_at': None if expires_at is None else expires_at.isoformat(), 'hours_left': hours,
            'class': category}


def certificate_end(stdout: str) -> datetime.datetime:
    text = stdout.strip()
    if not text.startswith('notAfter='):
        raise CheckFailed('openssl returned no notAfter')
    return datetime.datetime.strptime(text[len('notAfter='):], '%b %d %H:%M:%S %Y %Z').replace(tzinfo=datetime.UTC)


def inspect(now: datetime.datetime) -> list:
    env = {**os.environ, **ISSUER_ENV}
    found = []
    for kind, command in (('access', ['access', 'token', 'list']), ('enrollment', ['worker', 'token', 'list'])):
        for row in rows(run('swamp ' + ' '.join(command), [SWAMP, *command, *SERVE], env)):
            if row.get('state') != 'revoked':
                found.append(record(kind, row, now))
    for row in rows(run('swamp auth token list', [*AS_ORBIT, 'auth', 'token', 'list', '--json'])):
        if row.get('enabled', True):
            found.append(record('club', row, now))
    end = certificate_end(run('openssl x509 -enddate', ['openssl', 'x509', '-enddate', '-noout', '-in', CERTIFICATE]))
    found.append(record('tls', {'name': 'swamp.orbit', 'expiresAt': end.isoformat()}, now))
    return found


if __name__ == '__main__':
    moment = datetime.datetime.now(datetime.UTC)
    try:
        credentials = inspect(moment)
    except (CheckFailed, ValueError, KeyError) as error:
        print(f'credential check failed: {type(error).__name__}: {error}'[:300], file=sys.stderr)
        sys.exit(1)
    print(json.dumps({'checked_at': moment.isoformat(), 'credentials': credentials}))
