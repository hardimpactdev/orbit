# Program: database.valkey
# Uses: lib/program.py
# Outcomes: applied refused
# Timeout: 150
# Input: recorded host, port, password, fixed action and protected ACL payload.
# Result: backend and value-free refusal codes; never replies, passwords or hashes.
import socket
import time


class RedisError(Exception):
    pass


class Client:
    def __init__(self, payload):
        self.socket = socket.create_connection((payload['host'], payload['port']), timeout=5)
        self.socket.settimeout(10)
        self.file = self.socket.makefile('rb')
        if not payload['default_open']:
            self.command('AUTH', 'default', payload['password'])

    def close(self):
        self.file.close()
        self.socket.close()

    def reply(self):
        line = self.file.readline(1024 * 1024)
        if not line.endswith(b'\r\n'):
            raise RedisError()
        kind, value = line[:1], line[1:-2]
        if kind == b'-':
            raise RedisError()
        if kind == b'+':
            return value.decode()
        if kind == b':':
            return int(value)
        if kind == b'$':
            length = int(value)
            if length < 0:
                return None
            if length > 1024 * 1024:
                raise RedisError()
            data = self.file.read(length + 2)
            if len(data) != length + 2:
                raise RedisError()
            return data[:-2].decode()
        if kind == b'*':
            length = int(value)
            if length < 0:
                return None
            if length > 10000:
                raise RedisError()
            return [self.reply() for _ in range(length)]
        raise RedisError()

    def command(self, *arguments):
        parts = [str(argument).encode() for argument in arguments]
        wire = ('*%d\r\n' % len(parts)).encode() + b''.join(
            ('$%d\r\n' % len(part)).encode() + part + b'\r\n' for part in parts)
        self.socket.sendall(wire)
        return self.reply()


def acl_state(rules, hashes):
    groups = {'flags': [], 'keys': [], 'channels': [], 'commands': []}
    for token in rules.split():
        if token == 'sanitize-payload':
            continue
        if token.startswith(('~', '%')) or token in ('allkeys', 'resetkeys'):
            kind = 'keys'
        elif token.startswith('&') or token in ('allchannels', 'resetchannels'):
            kind = 'channels'
        elif token.startswith(('+', '-')) or token in ('allcommands', 'nocommands'):
            kind = 'commands'
        else:
            kind = 'flags'
        groups[kind].append(token)
    for kind in ('flags', 'keys', 'channels'):
        groups[kind].sort()
    return groups, sorted(hashes)


def apply(client, action, payload, password):
    if action == 'ready':
        client.command('PING')
        return 'active'
    if action == 'remove':
        lines = client.command('ACL', 'LIST')
        saved = {line.split(' ', 2)[1]: line.split(' ', 2)[2].split() for line in lines}
        names = [name for name in payload['usernames'] if name != 'default']
        try:
            if names:
                client.command('ACL', 'DELUSER', *names)
            client.command('ACL', 'SAVE')
        except Exception:
            for name in names:
                if name in saved:
                    client.command('ACL', 'SETUSER', name, 'reset', *saved[name])
            client.command('ACL', 'SAVE')
            raise
        return 'absent'
    elif action == 'converge':
        expected = {user['name']: user for user in payload['users']}
        live = {}
        for line in client.command('ACL', 'LIST'):
            _, name, values = line.split(' ', 2)
            tokens = values.split()
            hashes = [value[1:] for value in tokens if value.startswith('#')]
            live[name] = acl_state(' '.join(value for value in tokens if not value.startswith('#')), hashes)
        for name, user in expected.items():
            if live.get(name) == acl_state(user['rules'], user['hashes']):
                continue
            client.command('ACL', 'SETUSER', name, 'reset', *user['rules'].split(), *['#' + value for value in user['hashes']])
        stale = set(live) - set(expected) - ({'default'} if payload['default_open'] else set())
        if stale:
            client.command('ACL', 'DELUSER', *sorted(stale))
        client.command('ACL', 'SAVE')
    else:
        raise Outcome('refused', {'error_code': 'database.driver_unsupported'})
    return 'active'


def main(payload):
    deadline = time.monotonic() + 120
    while True:
        client = None
        try:
            client = Client(payload)
            return 'applied', {'backend': apply(client, payload['action'], payload['payload'], payload['password'])}
        except Outcome:
            raise
        except (OSError, RedisError, ValueError):
            if payload['action'] != 'ready' or time.monotonic() >= deadline:
                return 'refused', {'error_code': 'database.server_command_failed'}
            time.sleep(2)
        finally:
            if client is not None:
                client.close()


run_program(main)
