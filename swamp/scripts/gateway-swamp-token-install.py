"""Install a Gateway Swamp token on a release-container host, where the guest sees the shared env only as a read-only projection.

The release phase pipes the candidate's mint (`gateway:swamp-credentials --mint-for-host=<kind>`) into this script as orbit. Empty
input means nothing is due. It checks the reveal, writes a private backup and replaces only the token, its name and its expiry in one
atomic rename. `orbit-gateway-environment refresh` then publishes the file to the guest. Nothing it prints holds the secret.
"""
import datetime
import json
import os
import re
import secrets
import stat
import sys

KEYS = {'read': 'ORBIT_SWAMP_READ_TOKEN', 'run': 'ORBIT_SWAMP_RUN_TOKEN'}
TOKEN = re.compile(r'[A-Za-z0-9._~-]{16,4096}')


def fail(message):
    sys.exit('gateway.swamp_credentials_install_failed: ' + message)


def private(path, content):
    handle = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        os.write(handle, content)
        os.fsync(handle)
    finally:
        os.close(handle)


def install(kind, raw, path, now):
    if not raw.strip():
        return None
    try:
        reveal = json.loads(raw)
        name, token, expires = reveal['name'], reveal['token'], reveal['expiresAt']
        expiry = datetime.datetime.fromisoformat(expires.replace('Z', '+00:00'))
    except (ValueError, KeyError, TypeError, AttributeError):
        fail('the %s reveal is not the expected JSON' % kind)
    if (not re.fullmatch(r'orbit-gateway-%s-\d{8}(-\d+)?' % kind, name) or not TOKEN.fullmatch(token)
            or not token.startswith(name + '.') or expiry <= now + datetime.timedelta(days=10)):
        fail('the %s reveal is not a new generation that expires more than ten days from now' % kind)
    info = os.lstat(path)
    if not stat.S_ISREG(info.st_mode) or stat.S_IMODE(info.st_mode) != 0o600 or info.st_uid != os.geteuid():
        fail('the shared Gateway env must be a private, owned regular file')
    with open(path, 'rb') as handle:
        original = handle.read()
    key = KEYS[kind]
    settings = {key: token, key + '_NAME': name, key + '_EXPIRES_AT': expiry.strftime('%Y-%m-%dT%H:%M:%S.000000Z')}
    lines = original.decode().splitlines(keepends=True)
    for setting in settings:
        pattern = re.compile(r'\s*(export\s+)?%s\s*=' % re.escape(setting))
        if sum(1 for line in lines if pattern.match(line)) > 1:
            fail('the shared Gateway env has duplicate %s settings' % kind)
    for setting, value in settings.items():
        pattern = re.compile(r'\s*(export\s+)?%s\s*=' % re.escape(setting))
        line = '%s=%s\n' % (setting, value)
        index = next((i for i, current in enumerate(lines) if pattern.match(current)), None)
        if index is None:
            if lines and not lines[-1].endswith('\n'):
                lines[-1] += '\n'
            lines.append(line)
        else:
            lines[index] = line
    suffix = now.strftime('%Y%m%dT%H%M%SZ') + '-' + secrets.token_hex(8)
    folder = os.path.dirname(path)
    private(os.path.join(folder, 'gateway.env.before-swamp-' + suffix), original)
    temporary = os.path.join(folder, '.gateway.env.swamp-' + suffix)
    private(temporary, ''.join(lines).encode())
    os.replace(temporary, path)
    return name


def main():
    if len(sys.argv) not in (2, 3) or sys.argv[1] not in KEYS:
        sys.exit('usage: gateway-swamp-token-install.py read|run [env]')
    raw = sys.stdin.read(16_385)
    if len(raw) > 16_384:
        fail('the reveal is larger than 16 KiB')
    name = install(sys.argv[1], raw, sys.argv[2] if len(sys.argv) == 3 else '/home/orbit/shared/gateway.env',
                   datetime.datetime.now(datetime.UTC))
    print('installed ' + name if name else 'no %s token renewal is due' % sys.argv[1])


if __name__ == '__main__':
    main()
