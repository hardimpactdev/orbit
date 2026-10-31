"""Isolated real Gateway -> native serve -> sudo agent -> Gateway proof.

Uses a loopback auth-none scratch serve, synthetic private material and a
disposable container. It grants no live authority and touches no fleet database.
"""
import base64
import http.client
import json
import os
from pathlib import Path
import shutil
import socket
import ssl
import subprocess
import tempfile
import time

ROOT = Path(__file__).resolve().parents[4]


def gateway_user_proof(marker, pin, lose_outcome=False):
    gateway = Path(os.environ['DATABASE_USER_GATEWAY_ROOT'])
    with tempfile.TemporaryDirectory(prefix='database-user-gateway-', dir=gateway.parents[1]) as directory:
        scratch = Path(directory)
        repo = scratch / 'swamp'
        repo.mkdir()
        shutil.copy(ROOT / '.swamp.yaml', repo)
        for family in ['automatic-deploy', 'operation-runner', 'operations/_lib', 'operations/database']:
            shutil.copytree(ROOT / 'extensions/behaviors' / family, repo / 'extensions/behaviors' / family)
        (repo / 'workflows').mkdir()
        source = ROOT / 'workflows/workflow-operation-database-user-create.yaml'
        (repo / 'workflows' / source.name).write_text('\n'.join(
            line for line in source.read_text().splitlines()
            if not line.startswith(('target:', 'labels:'))) + '\n')
        if lose_outcome:
            behavior = repo / 'extensions/behaviors/operations/database/user_create.ts'
            behavior.write_text(behavior.read_text().replace(
                'return {\n              dataHandles:',
                '''if (outcome.status === "succeeded") {
              try { await Deno.stat("lost-outcome-once"); }
              catch {
                await Deno.writeTextFile("lost-outcome-once", "SQL succeeded; native output not saved");
                throw new Error("Injected loss after SQL before native output");
              }
            }
            return {\n              dataHandles:'''))

        def port():
            with socket.socket() as listener:
                listener.bind(('127.0.0.1', 0))
                return listener.getsockname()[1]

        serve_port, gateway_port = port(), port()
        globals_file = repo / 'extensions/behaviors/operation-runner/mod.ts'
        globals_file.write_text(globals_file.read_text().replace('https://gateway.orbit', f'http://127.0.0.1:{gateway_port}')
            .replace('/usr/local/share/ca-certificates/orbit-managed-root-ca.crt', str(scratch / 'cert.pem')))
        (scratch / 'gateway.sqlite').touch()
        for name in ['logs', 'framework/cache/data', 'framework/sessions', 'framework/views']:
            (scratch / 'storage' / name).mkdir(parents=True, exist_ok=True)
        (scratch / 'manifest.json').write_text(json.dumps({'commit': 'a' * 40, 'version': '0.5.0',
            'artifacts': {'linux-x86_64': {'sha256': pin, 'path': '/usr/local/bin/orbit-agent'}}}))
        subprocess.run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', str(scratch / 'key.pem'), '-out', str(scratch / 'cert.pem'), '-days', '1',
            '-subj', '/CN=swamp.orbit', '-addext', 'subjectAltName=DNS:swamp.orbit'],
            check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, timeout=15)
        env = dict(os.environ, APP_ENV='testing', APP_DEBUG='false',
            APP_KEY='base64:' + base64.b64encode(os.urandom(32)).decode(),
            DB_CONNECTION='sqlite', DB_DATABASE=str(scratch / 'gateway.sqlite'), DATABASE_URL='', DB_URL='',
            CACHE_STORE='array', SESSION_DRIVER='array', QUEUE_CONNECTION='sync',
            BROADCAST_CONNECTION='log', MAIL_MAILER='array',
            APP_CONFIG_CACHE=str(scratch / 'config.php'), APP_ROUTES_CACHE=str(scratch / 'routes.php'),
            APP_SERVICES_CACHE=str(scratch / 'services.php'), APP_PACKAGES_CACHE=str(scratch / 'packages.php'),
            ORBIT_HOME=str(scratch / 'orbit-home'), DATABASE_USER_SCRATCH=str(scratch),
            DATABASE_USER_PROCESS_NAME='lane10-' + marker, DATABASE_USER_GATEWAY_ROOT=str(gateway),
            DATABASE_USER_SWAMP_PORT=str(serve_port), DATABASE_USER_GATEWAY_PORT=str(gateway_port), SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ['SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT']:
            env.pop(key, None)
        helper = ROOT / 'extensions/behaviors/operations/database/tests/user_gateway.php'

        def php(mode, body=None):
            reply = subprocess.run(['php', str(helper), mode], cwd=gateway, env=env,
                input=json.dumps(body) if body else '', text=True, capture_output=True, timeout=50)
            if reply.returncode:
                raise AssertionError('Isolated Gateway failed: ' + reply.stdout[-1000:])
            return json.loads(reply.stdout)

        php('migrate')
        php('init')
        logs = [open(scratch / 'serve.log', 'w'), open(scratch / 'http.log', 'w')]
        processes = []
        try:
            processes.append(subprocess.Popen([os.environ.get('SWAMP_BIN', 'swamp'), 'serve',
                '--host', '127.0.0.1', '--port', str(serve_port), '--cert-file', str(scratch / 'cert.pem'),
                '--key-file', str(scratch / 'key.pem'), '--auth-mode', 'none', '--no-schedule'],
                cwd=repo, env=env, stdout=logs[0], stderr=subprocess.STDOUT))
            processes.append(subprocess.Popen(['php', '-S', f'127.0.0.1:{gateway_port}', str(helper)],
                cwd=gateway, env=env, stdout=logs[1], stderr=subprocess.STDOUT))
            context = ssl.create_default_context(cafile=str(scratch / 'cert.pem'))
            deadline = time.monotonic() + 20
            while True:
                try:
                    with socket.create_connection(('127.0.0.1', serve_port), timeout=1) as tcp:
                        with context.wrap_socket(tcp, server_hostname='swamp.orbit') as tls:
                            tls.sendall(b'GET /health HTTP/1.1\r\nHost: swamp.orbit\r\nConnection: close\r\n\r\n')
                            assert b'200' in tls.recv(4096)
                    with socket.create_connection(('127.0.0.1', gateway_port), timeout=1):
                        pass
                    break
                except (OSError, AssertionError):
                    if time.monotonic() >= deadline or any(p.poll() is not None for p in processes):
                        raise AssertionError('Isolated serve/Gateway readiness failed')
                    time.sleep(0.05)
            password = 'isolated-login-' + marker
            started = time.monotonic()
            admitted = php('admit', {'password': password})
            assert admitted['writer'] == 'direct' and admitted['run_id']
            admission_ms = round((time.monotonic() - started) * 1000)
            deadline = time.monotonic() + 25
            resumed = False
            while True:
                outcome = php('inspect')
                if outcome['operation']['status'] in ['succeeded', 'failed', 'cancelled']:
                    break
                if lose_outcome and not resumed and (repo / 'lost-outcome-once').exists():
                    history = subprocess.run([os.environ.get('SWAMP_BIN', 'swamp'), 'workflow', 'history', 'get',
                        admitted['run_id'], '--json'], cwd=repo, env=env, text=True, capture_output=True, timeout=15)
                    if history.returncode == 0 and json.loads(history.stdout)['status'] == 'failed':
                        assert outcome['users'] == 0 and outcome['operation']['status'] == 'running'
                        replay = subprocess.run([os.environ.get('SWAMP_BIN', 'swamp'), 'workflow', 'resume',
                            'operation-database-user-create', '--run', admitted['run_id'], '--json'],
                            cwd=repo, env=env, text=True, capture_output=True, timeout=20)
                        assert replay.returncode == 0, 'Native SQL replay did not recover'
                        resumed = True
                if time.monotonic() >= deadline:
                    raise AssertionError('Isolated native result did not finish')
                time.sleep(0.1)
            assert outcome['operation']['status'] == 'succeeded', outcome
            assert outcome['operation']['result'] == {'backend': 'active'}
            assert outcome['users'] == 1 and outcome['run_id'] == admitted['run_id']
            assert resumed == lose_outcome
            foreign_sql = php('render-foreign', {'password': password})['sql']
            refused = subprocess.run(['sudo', '-n', '--', '/usr/local/bin/orbit-agent',
                'command', 'database.server-admin'], input=json.dumps({'agent_sha256': pin, 'deadline_ms': 30000,
                    'input': {'operation': 'execute', 'container': 'orbit-process-2000000023-lane10-' + marker,
                        'password': '', 'password_variable': 'PGPASSWORD', 'sql': foreign_sql,
                        'client': ['psql', '--no-psqlrc', '--host=127.0.0.1', '--username=postgres', '--dbname=postgres',
                                   '--no-align', '--tuples-only', '--quiet', '--set=ON_ERROR_STOP=1']}}),
                text=True, capture_output=True, timeout=35)
            refusal = json.loads(refused.stdout)
            assert refusal['ok'] is False and refusal['error']['code'] == 'database.server_admin_failed', 'Foreign verifier must refuse'
            assert outcome['operation']['attempts'] == 0 and outcome['operation']['worker'] is None
            ordering = json.loads((scratch / 'ordering.json').read_text())
            assert ordering == {'before_start': 409, 'error_code': 'operation.run_unbound', 'still_unbound': True}
            requests = [json.loads(line) for line in (scratch / 'http.jsonl').read_text().splitlines()]
            successful = [row for row in requests if row['status'] == 200]
            assert successful and all(row['bound_before_request'] for row in successful)
            assert any('/private-material' in row['path'] for row in successful)

            history = subprocess.run([os.environ.get('SWAMP_BIN', 'swamp'), 'workflow', 'history', 'get',
                admitted['run_id'], '--json'], cwd=repo, env=env, text=True, capture_output=True, timeout=15)
            assert history.returncode == 0
            assert 'succeeded' in history.stdout
            connection = http.client.HTTPConnection('127.0.0.1', gateway_port, timeout=3)
            connection.request('GET', f"/api/v1/direct-operations/{admitted['id']}/private-material?run_id={admitted['run_id']}")
            response = connection.getresponse()
            assert response.status == 409 and 'no-store' in response.getheader('Cache-Control')
            response.read()
            connection.close()
            for name in ['serve.log', 'http.log', 'gateway.log']:
                path = scratch / name
                if path.exists():
                    assert password not in path.read_text()
            for path in (repo / '.swamp').rglob('*'):
                if path.is_file():
                    assert password.encode() not in path.read_bytes(), str(path)
            for path in scratch.rglob('*'):
                if path.is_file():
                    assert password.encode() not in path.read_bytes(), str(path)
            return {'admission_ms': admission_ms, 'total_ms': round((time.monotonic() - started) * 1000),
                    'node_id': 9, 'operation_id': admitted['id'], 'run_id': admitted['run_id'],
                    'status': 'succeeded', 'backend': 'active', 'users': 1,
                    'before_start': ordering['before_start'], 'first_reads_after_binding': True,
                    'private_after_finish': 409, 'whole_scratch_private_free': True,
                    'resumed_after_sql_before_native_output': resumed,
                    'foreign_verifier_refused': True}
        finally:
            for process in processes:
                process.terminate()
            for process in processes:
                try:
                    process.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    process.kill()
                    process.wait(timeout=5)
            for log in logs:
                log.close()
