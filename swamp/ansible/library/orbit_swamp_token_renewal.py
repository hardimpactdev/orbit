#!/usr/bin/python
"""Renew the Swamp Node's own Swamp tokens before they expire, without cutting a live session."""
import datetime
import json
import os
import re
import tempfile
import time

from ansible.module_utils.basic import AnsibleModule

DOCUMENTATION = r'''
module: orbit_swamp_token_renewal
short_description: Mint, switch and later revoke the Swamp Node's serve-driver and worker tokens
version_added: "1.0.0"
description:
  - Renews serve-driver, each worker's server access token and each worker enrollment token when it expires within renew_days.
  - An access token is never rotated. The new generation is <base>-<YYYYMMDD> of the UTC day; a run that finds it active reveals
    it again instead of minting another. It is verified before its file is replaced atomically.
  - Predecessors come from live facts on every run, never from state. The current generation is the name its consumer file holds;
    a predecessor is another active token of the same family and principal that expires earlier. A predecessor is revoked only
    when its consumer no longer uses it, which is when the serve-driver file is at least grace_hours old, or when the worker unit's
    main process started after its server.token changed.
  - Enrollment tokens are rotated once through serve; the old secret stays valid to its own expiry. A root-owned marker per slot
    keeps a rerun after a crash from rotating twice. No secret is returned or logged.
options:
  swamp: {type: path, default: /usr/local/bin/swamp, description: The Swamp CLI.}
  swamp_env: {type: path, default: /usr/local/lib/orbit-swamp/swamp-env, description: Sources the account's Swamp Club credential env.}
  server: {type: str, default: ws://127.0.0.1:9418, description: The local serve.}
  user: {type: str, required: true, description: The account that owns serve and runs the workers.}
  home: {type: path, required: true, description: That account's home.}
  slots: {type: list, elements: str, default: [a, b, c], description: The permanent worker slots.}
  credentials: {type: path, default: /etc/orbit/swamp-role-worker, description: The worker token folders.}
  state_file: {type: path, default: /var/lib/orbit-swamp-token-renewal/state.json, description: Root-owned enrollment rotate markers.}
  renew_days: {type: int, default: 10, description: Renew a token that expires within this many days.}
  grace_hours: {type: int, default: 24, description: Hours a replaced serve-driver generation stays valid.}
  duration: {type: str, default: 30d, description: Lifetime of a new generation.}
  now: {type: str, description: An ISO time that replaces the clock, for tests.}
author: Orbit
'''
EXAMPLES = ''
RETURN = r'''
actions:
  description: What this run did, by token name only.
  type: list
  returned: always
'''
SUFFIX = re.compile(r'-\d{8}(T\d{6}Z)?(-\d+)?$')
SECRET = re.compile(r'[A-Za-z0-9_-]+\.[A-Za-z0-9._~-]{12,}')
TOKEN_NAME = re.compile(r'^[A-Za-z0-9][A-Za-z0-9_-]*$')
ENROLLMENT_VALID_STATES = frozenset({'enrolled', 'active'})
VERIFY_DAYS = 20


def family(name):
    """A token family is <base> or <base>-<YYYYMMDD>[-<n>]; legacy <base>-<YYYYMMDDTHHMMSSZ> names belong too."""
    return SUFFIX.sub('', name)


def redact(text):
    return SECRET.sub('[redacted]', text)


class Renewal:
    def __init__(self, module, now):
        self.module = module
        self.p = module.params
        self.now = now
        self.driver = os.path.join(self.p['home'], '.config/swamp/serve-driver-token')
        self.actions = []

    # --- boundary -------------------------------------------------------------------------------------------------
    def swamp(self, *args, token_file=None):
        """Runs one Swamp call as the serve account through serve. A failure quotes only redacted stderr, never stdout. swamp_env
        sources the account's Swamp Club credential env first; without it a non-interactive call fails with auth_gate_blocked."""
        config = os.path.join(self.p['home'], '.config/swamp')
        argv = ['runuser', '-u', self.p['user'], '--', 'env', 'HOME=' + self.p['home'],
                'SWAMP_HOME=' + os.path.join(self.p['home'], '.swamp'), 'SWAMP_CONFIG_DIR=' + config,
                'SWAMP_API_KEY_FILE=' + os.path.join(config, 'api-key'), 'SWAMP_NO_UPDATE_CHECK=1', 'SWAMP_NO_TELEMETRY=1',
                self.p['swamp_env'], self.p['swamp'], *args, '--server', self.p['server'], '--token-file', token_file or self.driver, '--json']
        code, out, err = self.module.run_command(argv)
        if code != 0:
            self.fail('swamp %s %s %s failed with exit %d: %s' % (args[0], args[1], args[2], code, redact(err.strip()[-2000:])))
        try:
            return json.loads(out)
        except ValueError:
            self.fail('swamp %s %s %s returned no JSON' % (args[0], args[1], args[2]))

    def fail(self, message):
        self.module.fail_json(msg=message, actions=self.actions)

    def markers(self):
        try:
            with open(self.p['state_file'], encoding='utf-8') as handle:
                return json.load(handle)
        except FileNotFoundError:
            return {}

    def mark(self, slot, rotating):
        markers = self.markers()
        markers.pop(slot, None)
        if rotating:
            markers[slot] = 'rotating'
        os.makedirs(os.path.dirname(self.p['state_file']), mode=0o700, exist_ok=True)
        os.replace(self.write(self.p['state_file'], json.dumps(markers, sort_keys=True) + '\n', 0, 0, 0o600), self.p['state_file'])

    def write(self, path, content, uid, gid, mode):
        """Replaces a file atomically: a reader sees the old or the new content, never a partial one."""
        handle, temporary = tempfile.mkstemp(dir=os.path.dirname(path), prefix='.' + os.path.basename(path) + '.')
        try:
            os.fchmod(handle, mode)
            os.fchown(handle, uid, gid)
            os.write(handle, content.encode())
            os.fsync(handle)
        finally:
            os.close(handle)
        return temporary

    # --- reads ----------------------------------------------------------------------------------------------------
    def file_name(self, path):
        """The token name is the part of `<name>.<secret>` before the first dot; malformed values fail without quoting raw content."""
        try:
            with open(path, encoding='utf-8') as handle:
                raw = handle.read().strip()
        except OSError:
            self.fail('%s cannot be read' % path)
        if '.' not in raw:
            self.fail('%s does not contain a delimited <name>.<secret> token' % path)
        name, secret = raw.split('.', 1)
        if not secret or not TOKEN_NAME.fullmatch(name):
            self.fail('%s does not contain a delimited <name>.<secret> token' % path)
        return name

    @staticmethod
    def rows(listed):
        return listed['tokens'] if isinstance(listed, dict) else listed

    def access_tokens(self):
        """Every listed access token by name, revoked ones included, so a revoked dated name is never reused."""
        return {row['name']: row for row in self.rows(self.swamp('access', 'token', 'list'))}

    @staticmethod
    def active(row):
        return row is not None and (row.get('effectiveState') or row.get('state')) == 'active'

    def expires(self, row):
        return datetime.datetime.fromisoformat(row['expiresAt'].replace('Z', '+00:00'))

    def due(self, row):
        return row.get('expiresAt') is not None and self.expires(row) - self.now < datetime.timedelta(days=self.p['renew_days'])

    def successor(self, base, tokens):
        """Today's name, or the next -<n> when today's name is revoked or otherwise not usable."""
        name = base + '-' + self.now.strftime('%Y%m%d')
        attempt = 1
        while name in tokens and not self.usable(tokens[name]):
            attempt += 1
            name = '%s-%s-%d' % (base, self.now.strftime('%Y%m%d'), attempt)
        return name

    def usable(self, row):
        return self.active(row) and self.expires(row) - self.now > datetime.timedelta(days=VERIFY_DAYS)

    def reveal(self, kind, name):
        token = self.swamp(kind, 'token', 'reveal', name, '--yes').get('token')
        if not isinstance(token, str) or not token.startswith(name + '.'):
            self.fail('swamp %s token reveal returned no credential for %s' % (kind, name))
        return token + '\n'

    def owner(self):
        import grp
        import pwd
        return pwd.getpwnam(self.p['user']).pw_uid, grp.getgrnam(self.p['user']).gr_gid

    def age(self, path):
        return self.now.timestamp() - os.stat(path).st_mtime

    # --- access tokens: mint new, verify, switch, revoke predecessors once unused -----------------------------------
    def access(self, path, uid, gid, mode, unused, verify):
        """One live-consumer access token. `unused()` says the consumer no longer holds a predecessor."""
        tokens = self.access_tokens()
        current = self.file_name(path)
        row = tokens.get(current)
        if not self.active(row):
            self.fail('%s names a token that serve does not list as active; renew it by hand' % os.path.basename(path))
        base, principal = family(current), row['principalId']
        predecessors = sorted(name for name, other in tokens.items()
                              if name != current and family(name) == base and self.active(other)
                              and other.get('principalId') == principal and self.expires(other) < self.expires(row))
        if predecessors and unused():
            for name in predecessors:
                self.swamp('access', 'token', 'revoke', name)
                self.actions.append('revoked ' + name)
        if not self.due(row):
            return
        name = self.successor(base, tokens)
        if name not in tokens:
            self.swamp('access', 'token', 'mint', name, '--principal', principal, '--duration', self.p['duration'])
            self.actions.append('minted ' + name)
        else:
            self.actions.append('resumed ' + name)
        temporary = self.write(path, self.reveal('access', name), uid, gid, mode)
        try:
            verify(temporary, name, principal)
        except BaseException:
            os.unlink(temporary)
            raise
        os.replace(temporary, path)
        # Record publication time after verification and atomic rename so a worker started during verification is never treated as newer.
        os.utime(path, None)
        self.actions.append('switched %s to %s' % (os.path.basename(path), name))

    def verify_driver(self, temporary, name, principal):
        """serve-driver can read: a list with the candidate file must succeed."""
        self.swamp('access', 'token', 'list', token_file=temporary)

    def verify_listed(self, temporary, name, principal):
        """A worker principal may not read, so serve's list is the proof: active, same principal, far from expiry."""
        row = self.access_tokens().get(name)
        if not (self.usable(row) and row.get('principalId') == principal):
            self.fail('%s is not an active %s token that expires after %d days; server.token was not changed'
                      % (name, principal, VERIFY_DAYS))

    def driver_unused(self):
        return self.age(self.driver) >= self.p['grace_hours'] * 3600

    def worker_unused(self, slot, path):
        """The pinned worker reads server.token only when `worker connect` starts, so its main process must be newer than the file."""
        def unused():
            code, out, _ = self.module.run_command(['systemctl', 'show', 'orbit-swamp-worker@%s.service' % slot,
                                                    '--property=ExecMainStartTimestampMonotonic', '--value'])
            started = int(out.strip() or 0) if code == 0 and out.strip().isdigit() else 0
            changed = time.monotonic() - (time.time() - os.stat(path).st_mtime)
            return started > 0 and started / 1_000_000 > changed
        return unused

    # --- enrollment tokens: rotate once, write the new secret as root -----------------------------------------------
    def enrollment(self, slot, gid):
        path = os.path.join(self.p['credentials'], slot, 'enrollment.token')
        name = self.file_name(path)
        row = next((row for row in self.rows(self.swamp('worker', 'token', 'list'))
                    if row['name'] == name and (row.get('effectiveState') or row.get('state')) in ENROLLMENT_VALID_STATES), None)
        if row is None:
            self.fail('enrollment.token of slot %s names %s, which serve does not list in a valid state' % (slot, name))
        due = self.due(row)
        if not due and self.markers().get(slot) != 'rotating':
            return
        if due:
            # A rotate moves the expiry, so a token still due was not rotated, also after a crash. The marker comes first: a
            # rerun after a crash then writes the new secret and never rotates twice, which would retire the old secret at once.
            self.mark(slot, True)
            self.swamp('worker', 'token', 'rotate', name)
            self.actions.append('rotated ' + name)
        os.replace(self.write(path, self.reveal('worker', name), 0, gid, 0o640), path)
        self.mark(slot, False)
        self.actions.append('wrote %s enrollment.token' % slot)

    def run(self):
        uid, gid = self.owner()
        self.access(self.driver, uid, gid, 0o600, self.driver_unused, self.verify_driver)
        for slot in self.p['slots']:
            path = os.path.join(self.p['credentials'], slot, 'server.token')
            self.access(path, 0, gid, 0o640, self.worker_unused(slot, path), self.verify_listed)
            self.enrollment(slot, gid)
        return self.actions


def main():
    module = AnsibleModule(argument_spec={
        'swamp': {'type': 'path', 'default': '/usr/local/bin/swamp'},
        'swamp_env': {'type': 'path', 'default': '/usr/local/lib/orbit-swamp/swamp-env'},
        'server': {'type': 'str', 'default': 'ws://127.0.0.1:9418'},
        'user': {'type': 'str', 'required': True},
        'home': {'type': 'path', 'required': True},
        'slots': {'type': 'list', 'elements': 'str', 'default': ['a', 'b', 'c']},
        'credentials': {'type': 'path', 'default': '/etc/orbit/swamp-role-worker'},
        'state_file': {'type': 'path', 'default': '/var/lib/orbit-swamp-token-renewal/state.json'},
        'renew_days': {'type': 'int', 'default': 10},
        'grace_hours': {'type': 'int', 'default': 24},
        'duration': {'type': 'str', 'default': '30d'},
        'now': {'type': 'str'},
    }, supports_check_mode=False)
    now = (datetime.datetime.fromisoformat(module.params['now']) if module.params['now']
           else datetime.datetime.now(datetime.UTC))
    actions = Renewal(module, now).run()
    module.exit_json(changed=bool(actions), actions=actions)


if __name__ == '__main__':
    main()
