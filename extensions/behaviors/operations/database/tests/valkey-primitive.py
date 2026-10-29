"""Run the actual Gateway primitive against an in-memory protocol peer, never a live server."""
import json
import os
from pathlib import Path
import runpy
import sys


class Outcome(Exception):
    def __init__(self, outcome, data):
        self.data = data


request = json.load(sys.stdin)
program = runpy.run_path(str(Path(os.environ['ORBIT_GATEWAY_CHECKOUT']) / 'apps/gateway/resources/remote/database/valkey.py'),
                        init_globals={'run_program': lambda main: None, 'Outcome': Outcome})
calls = []


class Client:
    def command(self, *args):
        calls.append(args)
        failure = request.get('fail')
        if (failure == 'save' and args == ('ACL', 'SAVE')
                or failure == 'first-save' and args == ('ACL', 'SAVE') and calls.count(args) == 1
                or failure == 'set' and args[:2] == ('ACL', 'SETUSER')):
            raise program['RedisError']()
        if args == ('ACL', 'LIST'):
            return request.get('users', [])
        if args == ('CLIENT', 'ID'):
            return 7
        if args == ('CLIENT', 'LIST', 'USER', 'default'):
            return 'id=7 addr=127.0.0.1\nid=8 addr=127.0.0.2'
        return 'OK'


try:
    backend = program['apply'](Client(), request['action'], request['payload'], 'fixture-password')
    result = {'backend': backend}
except Outcome as refused:
    result = refused.data
except program['RedisError']:
    result = {'error_code': 'database.server_command_failed'}
json.dump({'result': result, 'calls': calls}, sys.stdout)
