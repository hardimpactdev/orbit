"""Plausible CE database initialization against disposable PostgreSQL and ClickHouse."""
import base64
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class InitializationReal(NativeTestCase):
    def test_actual_plausible_database_initialization_and_repeat(self):
        process_id = 910000000 + secrets.randbelow(10000000)
        server = f'orbit-process-{process_id}-plausible'
        containers = []

        def docker(*args, check=True):
            return subprocess.run(['docker', *args], check=check, capture_output=True, text=True, timeout=60)

        def cleanup():
            for name in [server + '-init-createdb', server + '-init-migrate', *reversed(containers)]:
                docker('rm', '--force', '--volumes', name, check=False)
        self.addCleanup(cleanup)
        for suffix, image, options in [
            ('pg', 'postgres:16-alpine', ['-e', 'POSTGRES_HOST_AUTH_METHOD=trust']),
            ('ch', 'clickhouse/clickhouse-server:24.12-alpine', ['-e', 'CLICKHOUSE_SKIP_USER_SETUP=1']),
        ]:
            name = f'{server}-{suffix}'
            docker('run', '-d', '--name', name, *options, image)
            containers.append(name)
        until = time.monotonic() + 40
        while docker('exec', containers[0], 'pg_isready', '-U', 'postgres', check=False).returncode:
            self.assertLess(time.monotonic(), until, 'Disposable PostgreSQL did not become ready')
            time.sleep(.2)
        while docker('exec', containers[1], 'clickhouse-client', '--query', 'SELECT 1', check=False).returncode:
            self.assertLess(time.monotonic(), until, 'Disposable ClickHouse did not become ready')
            time.sleep(.2)
        addresses = [docker('inspect', name, '--format', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}').stdout.strip() for name in containers]
        secret = secrets.token_hex(64)
        private = {'SECRET_KEY_BASE': secret, 'DATABASE_URL': f'postgres://postgres@{addresses[0]}:5432/plausible_db',
                   'CLICKHOUSE_DATABASE_URL': f'http://{addresses[1]}:8123/plausible_events_db',
                   'BASE_URL': 'http://localhost:8000', 'DISABLE_REGISTRATION': 'true'}
        module = (ROOT / 'extensions/behaviors/operations/analytics/initialization.ts').as_uri()
        script = f'''import {{initializePlausible}} from {json.dumps(module)};
const environment = await new Response(Deno.stdin.readable).json();
const {{processSystem}} = await import({json.dumps((ROOT / 'extensions/behaviors/operations/processes-schedules/process-system.ts').as_uri())});
const native = processSystem();
const volumes = [];
const action = async (name, input, signal) => {{
  const outcome = await native(name, input, signal);
  if (name === "process.converge-container" && !outcome.error_code) {{
    const result = await new Deno.Command("/usr/bin/docker",{{args:["container","inspect","--format","{{{{json .Mounts}}}}", input.container],stdout:"piped",stderr:"null"}}).output();
    if (!result.success) throw new Error("Fixture cannot inspect temporary mounts");
    volumes.push(...JSON.parse(new TextDecoder().decode(result.stdout)).filter(v => v.Type === "volume").map(v => v.Name));
  }}
  return outcome;
}};
const result = await initializePlausible({{
process_id:{process_id}, container:{json.dumps(server)}, spec:{json.dumps('a'*64)},
image:"ghcr.io/plausible/community-edition:v3.2.1", desired:"running", command:["/entrypoint.sh","run"],
restart:"no", working_directory:null, ports:[], mounts:[]}}, environment, action);
let volumes_removed = volumes.length > 0;
for (const name of volumes) {{
  const inspected = await new Deno.Command("/usr/bin/docker",{{args:["volume","inspect",name],stdout:"null",stderr:"null"}}).output();
  if (inspected.success) volumes_removed = false;
}}
console.log(JSON.stringify({{...result, volumes_removed}}));'''
        deno = os.environ.get('DENO_BIN', '/home/nckrtl/.cache/bunx/bunx-1000-deno@latest/node_modules/deno/deno')
        self.secrets=(secret,base64.b64encode(secret.encode()).decode())
        for _ in range(2):
            result = subprocess.run([deno, 'eval', script], input=json.dumps(private), capture_output=True, text=True, timeout=120)
            self.assertEqual(result.returncode, 0, 'Native initialization failed')
            self.assertEqual(json.loads(result.stdout), {'ready': True, 'volumes_removed': True})
            self.assert_private_absent(result.stdout, result.stderr)
            for phase in ('createdb', 'migrate'):
                self.assertNotEqual(docker('inspect', server + '-init-' + phase, check=False).returncode, 0)
        rows = docker('exec', containers[0], 'psql', '-U', 'postgres', '-d', 'plausible_db', '-Atc', 'SELECT count(*) FROM schema_migrations').stdout.strip()
        self.assertGreater(int(rows), 0)


if __name__ == '__main__':
    unittest.main()
