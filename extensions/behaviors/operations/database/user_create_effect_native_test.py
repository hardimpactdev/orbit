"""Manual disposable PostgreSQL/sudo action proof; HTTP is still a fixture."""
import hashlib
import json
import os
import pwd
import signal
from pathlib import Path
import subprocess
import unittest
import uuid

from user_create_native_test import UserCreateNative
from user_create_gateway_canary import gateway_user_proof


@unittest.skipUnless(os.environ.get('DATABASE_USER_EFFECT_CANARY') == 'beast-9',
                     'Manual Beast Node9 disposable Docker fixture only')
class UserCreateEffect(unittest.TestCase):
    def test_native_step_runs_pinned_sudo_agent_and_creates_only_the_fixture_login(self):
        self.assertEqual(pwd.getpwuid(os.getuid()).pw_name, 'nckrtl')
        marker = uuid.uuid4().hex
        name = 'orbit-process-2000000023-lane10-' + marker
        label = 'orbit.database-user-proof'

        def docker(*args, success=True):
            reply = subprocess.run(['sudo', '-n', '--', '/usr/bin/docker', *args],
                                   text=True, capture_output=True, timeout=40)
            self.assertEqual(reply.returncode == 0, success, 'Disposable Docker action failed')
            return reply.stdout.strip()

        docker('container', 'inspect', name, success=False)
        docker('run', '-d', '--name', name, '--label', label + '=' + marker,
               '--network', 'none', '--read-only',
               '--tmpfs', '/var/lib/postgresql:rw,nosuid,nodev,size=256m',
               '--tmpfs', '/var/run/postgresql:rw,nosuid,nodev,size=16m',
               '--tmpfs', '/tmp:rw,nosuid,nodev,size=16m',
               '--env', 'POSTGRES_HOST_AUTH_METHOD=trust', 'postgres:18-alpine',
               '-c', 'listen_addresses=127.0.0.1', '-c', 'fsync=off')
        try:
            pin = hashlib.sha256(Path('/usr/local/bin/orbit-agent').read_bytes()).hexdigest()
            self.assertEqual(pin, os.environ['DATABASE_USER_EXPECTED_AGENT_SHA256'])
            client = ['psql', '--no-psqlrc', '--host=127.0.0.1', '--username=postgres',
                      '--dbname=postgres', '--no-align', '--tuples-only', '--quiet', '--set=ON_ERROR_STOP=1']
            ready = subprocess.run(['sudo', '-n', '--', '/usr/local/bin/orbit-agent',
                                    'command', 'database.server-admin'],
                input=json.dumps({'agent_sha256': pin, 'deadline_ms': 30000,
                    'input': {'operation': 'ready', 'container': name, 'password': '',
                              'password_variable': 'PGPASSWORD', 'client': client, 'timeout_seconds': 20}}),
                text=True, capture_output=True, timeout=35)
            self.assertEqual(ready.returncode, 0, 'Disposable PostgreSQL readiness failed')
            self.assertTrue(json.loads(ready.stdout)['ok'])
            whole = gateway_user_proof(marker, pin, lose_outcome=True)
            print(json.dumps(whole))
            proof = UserCreateNative('test_private_sql_and_native_output_never_enter_native_history')
            proof.setUp()
            self.addCleanup(proof.doCleanups)
            proof.node_id = 9
            proof.password = 'scratch-login-' + marker
            proof.sql = f"ALTER ROLE lane10_reader PASSWORD '{proof.password}';"
            proof.fixture['operation']['node_id'] = 9
            proof.fixture['operation']['input'].update({'driver': 'pgsql', 'container': name,
                'agent_sha256': pin, 'username': 'lane10_reader', 'replication': False, 'known_user': True})
            proof.fixture['material'] = {'password': '', 'sql': proof.sql}
            module = proof.repo / 'extensions/behaviors/operations/database/user_create.ts'
            module.write_text(module.read_text().replace('userCreate(() => fixtureHttp, fixtureInvoke)', 'userCreate(() => fixtureHttp)'))
            final = proof.exercise(True)
            self.assertEqual(final['operation']['result'], {'backend': 'active'})
            rows = docker('exec', name, 'psql', '-U', 'postgres', '-d', 'postgres', '-At', '-c',
                          "SELECT rolname || ':' || rolcanlogin || ':' || rolsuper FROM pg_roles WHERE rolname = 'lane10_reader'")
            self.assertEqual(rows, 'lane10_reader:true:false')
        finally:
            identity = docker('inspect', '--format', '{{index .Config.Labels "' + label + '"}}', name)
            self.assertEqual(identity, marker, 'Refuse removal without fixture ownership')
            docker('rm', '-f', name)


if __name__ == '__main__':
    def terminate(_signal, _frame):
        raise TimeoutError('Manual canary terminated; clean up owned resources')
    signal.signal(signal.SIGTERM, terminate)
    unittest.main()
