"""Read Swamp-side credential expiry from native metadata on Node 209 and report alerts. Read-only; never reads a value."""

import datetime
import json
import subprocess
import sys

SWAMP = '/home/orbit/.local/bin/swamp'
REPO = '/home/orbit/apps/orbit-monorepo-staging/checkout/swamp'
# Read-only metadata as the orbit account that owns the datastore; no serve token, no sudo.
ENV = {'HOME': '/home/orbit', 'SWAMP_HOME': '/home/orbit/.swamp', 'SWAMP_CONFIG_DIR': '/home/orbit/.config/swamp',
       'SWAMP_SERVE_URL': '', 'SWAMP_SERVER_URL': '', 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1',
       'PATH': '/usr/bin:/bin'}
# Local datastore reads pass the CLI account gate with the host's existing club key, as the 2026-11-01 repair did.
DATASTORE_ENV = {**ENV, 'SWAMP_API_KEY_FILE': '/home/orbit/.config/swamp/api-key'}
LOCAL = ['--repo-dir', REPO, '--json']
COLLECTIVE = 'nckrtl'
CERTIFICATE = '/home/orbit/.config/swamp/serve-tls/cert.pem'
CALL_SECONDS = 30
ALERT_HOURS = 72
SOON_HOURS = 6
# Keeps one bounded report in the step output.
MAXIMUM_ROWS = 200
STALE_HOURS = -168
# The Swamp host's own worker pair must always be listed.
EXPECTED = (('access', 'orbit-node-209-access'), ('enrollment', 'orbit-node-209'))
# Each native list prints one JSON array; these keys must be present on every row.
REQUIRED = {'access': ('name', 'state', 'effectiveState', 'principalId', 'expiresAt'),
            'enrollment': ('name', 'state', 'effectiveState', 'expiresAt'),
            'club': ('name', 'enabled', 'expiresAt')}


class CheckFailed(Exception):
    """A public reason; never carries command output."""


def run(label: str, argv: list, env: dict = ENV) -> str:
    try:
        completed = subprocess.run(argv, cwd=REPO, env=env, capture_output=True, text=True, timeout=CALL_SECONDS,
                                   check=False)
    except subprocess.TimeoutExpired as error:
        raise CheckFailed(f'{label} did not finish in {CALL_SECONDS} s') from error
    if completed.returncode != 0:
        raise CheckFailed(f'{label} exited {completed.returncode}')
    return completed.stdout


def rows(kind: str, stdout: str) -> list:
    listed = json.loads(stdout)
    if not isinstance(listed, list) or not all(isinstance(row, dict) for row in listed):
        raise CheckFailed(f'{kind} list is not a JSON array of objects')
    for row in listed:
        absent = [key for key in REQUIRED[kind] if key not in row]
        if absent:
            raise CheckFailed(f'{kind} row lacks {", ".join(absent)}')
    return listed


def classify(expires_at: datetime.datetime | None, now: datetime.datetime) -> tuple:
    if expires_at is None:
        return 'no_expiry', None
    hours = (expires_at - now).total_seconds() / 3600
    if hours <= 0:
        return 'expired', round(hours, 1)
    if hours <= SOON_HOURS:
        return 'within_6h', round(hours, 1)
    return ('within_72h' if hours <= ALERT_HOURS else 'ok'), round(hours, 1)


def record(kind: str, name: str, expires: str | None, now: datetime.datetime, principal: str | None = None) -> dict:
    expires_at = None if expires is None else datetime.datetime.fromisoformat(expires)
    if expires_at is not None and expires_at.tzinfo is None:
        raise CheckFailed(f'{kind} {name} expiry has no time zone')
    category, hours = classify(expires_at, now)
    return {'kind': kind, 'name': name, 'principal': principal,
            'expires_at': None if expires_at is None else expires_at.isoformat(), 'hours_left': hours,
            'class': category}


def certificate_end(stdout: str) -> str:
    text = stdout.strip()
    if not text.startswith('notAfter='):
        raise CheckFailed('openssl returned no notAfter')
    end = datetime.datetime.strptime(text[len('notAfter='):], '%b %d %H:%M:%S %Y %Z')
    return end.replace(tzinfo=datetime.UTC).isoformat()


def inspect(now: datetime.datetime) -> list:
    found = []
    for kind, command in (('access', ['access', 'token', 'list']), ('enrollment', ['worker', 'token', 'list'])):
        for row in rows(kind, run('swamp ' + ' '.join(command), [SWAMP, *command, *LOCAL], DATASTORE_ENV)):
            if row['state'] != 'revoked':
                found.append(record(kind, row['name'], row['expiresAt'], now, row.get('principalId')))
    club = run('swamp auth token list', [SWAMP, 'auth', 'token', 'list', '--collective', COLLECTIVE, '--json'])
    found += [record('club', row['name'], row['expiresAt'], now) for row in rows('club', club) if row['enabled']]
    certificate = run('openssl x509 -enddate', ['openssl', 'x509', '-enddate', '-noout', '-in', CERTIFICATE])
    found.append(record('tls', 'swamp.orbit', certificate_end(certificate), now))
    # A credential expired for over a week has alerted already; drop it before the bound.
    found = [item for item in found if item['hours_left'] is None or item['hours_left'] > STALE_HOURS]
    found.sort(key=lambda item: (item['hours_left'] is None, item['hours_left'] or 0))
    return found[:MAXIMUM_ROWS]


def alerts(credentials: list) -> list:
    listed = {(item['kind'], item['name']) for item in credentials}
    raised = [{'kind': kind, 'name': name, 'reason': 'missing', 'expires_at': None, 'hours_left': None}
              for kind, name in EXPECTED if (kind, name) not in listed]
    raised += [{'kind': item['kind'], 'name': item['name'], 'reason': item['class'],
                'expires_at': item['expires_at'], 'hours_left': item['hours_left']}
               for item in credentials if item['class'] in ('within_72h', 'within_6h', 'expired')]
    return raised


if __name__ == '__main__':
    moment = datetime.datetime.now(datetime.UTC)
    try:
        credentials = inspect(moment)
    except Exception as error:  # noqa: BLE001 - one public line; never a value or command output
        detail = f': {error}' if isinstance(error, CheckFailed) else ''
        print(f'credential check failed: {type(error).__name__}{detail}'[:300], file=sys.stderr)
        sys.exit(1)
    print(json.dumps({'checked_at': moment.isoformat(), 'credentials': credentials, 'alerts': alerts(credentials)}))
