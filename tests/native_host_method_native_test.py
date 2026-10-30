"""Pinned serve + real worker + native child, with isolated datastore and no Node effects."""
import json
import os
from pathlib import Path
import shutil
import socket
import subprocess
import tempfile
import time
import unittest
import urllib.request

REPO = Path(__file__).resolve().parents[1]
CLI = os.environ.get('SWAMP_BIN', 'swamp')


def reply(raw):
    for i, c in enumerate(raw):
        if c in '{[':
            try:
                return json.loads(raw[i:])
            except ValueError:
                pass
    raise AssertionError('Native JSON missing')


class NativeHostMethodTest(unittest.TestCase):
    def test_remote_refusal_is_resolved_by_a_separate_native_process(self):
        with tempfile.TemporaryDirectory(prefix='native-host-integration-') as temp:
            root = Path(temp)
            shutil.copy(REPO / '.swamp.yaml', root)
            ext = root / 'extensions/behaviors'
            ext.mkdir(parents=True)
            (root / 'bin').mkdir()
            shutil.copy(REPO / 'bin/native-host-start.py', root / 'bin/native-host-start.py')
            shutil.copytree(REPO / 'extensions/behaviors/_lib', ext / '_lib')
            shutil.copytree(REPO / 'extensions/behaviors/composer-maintenance', ext / 'composer-maintenance')
            shutil.copytree(REPO / 'extensions/behaviors/js-maintenance', ext / 'js-maintenance')
            source = (REPO / 'extensions/behaviors/native_host_method.ts').read_text()
            source = source.replace('"/home/orbit/apps/orbit-swamp/default"', json.dumps(str(root)))
            source = source.replace('const HOME = "/home/orbit"', 'const HOME = ' + json.dumps(str(Path.home())))
            source = source.replace('executable: `${HOME}/.local/bin/swamp`', 'executable: ' + json.dumps(shutil.which(CLI) or CLI))
            (ext / 'native_host_method.ts').write_text(source)
            (ext / 'native_coordinator.ts').write_text('''
import { z } from "npm:zod@4";
import { callWorkerModel } from "./_lib/worker_model.ts";
import { queryWorkerData } from "./_lib/worker_query.ts";
export const behavior = {
  type: "@fixture/native-coordinator", version: "2026.10.30.1",
  globalArguments: z.object({}), methods: { probe: {
    description: "Nested method from a placed coordinator", arguments: z.object({}),
    execute: async (_: unknown, ctx: { signal?: AbortSignal; queryData: (p: string) => Promise<unknown[]> }) => {
      const result = await callWorkerModel(ctx, {
        modelType: "@nckrtl/dependency-scanner", name: "dependency-scanner-21",
        method: "scan", arguments: { projectId: 21, runId: "coordinator-proof" },
      });
      if (!result.ok) throw new Error("coordinator_native_call_failed");
      const predicate = 'modelName == "dependency-scanner-20" && name == "proof"';
      const attrs = await queryWorkerData(ctx, predicate, "attributes") as { projectId: number }[];
      if (attrs.length !== 1 || attrs[0].projectId !== 20) throw new Error("worker_query_not_hydrated");
      const content = await queryWorkerData(ctx, predicate, "content") as { projectId: number }[];
      if (content.length !== 1 || content[0].projectId !== 20) throw new Error("worker_content_not_hydrated");
      return { dataHandles: [] };
    },
  }},
};
''')
            shutil.copy(REPO / 'extensions/behaviors/orbit_group_runs.ts', ext / 'orbit_group_runs.ts')
            for name in ('scanner', 'leaf', 'refusal'):
                shutil.copy(REPO / f'tests/fixtures/native-host/{name}.ts', ext / f'{name}.ts')
            env = {**os.environ, 'SWAMP_NO_UPDATE_CHECK': '1', 'SWAMP_NO_TELEMETRY': '1',
                   'SWAMP_API_KEY_FILE': str(Path.home() / '.config/swamp/api-key')}
            for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN', 'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT'):
                env.pop(key, None)

            def cli(*args):
                result = subprocess.run([CLI, *args, '--json'], cwd=root, env=env, capture_output=True, text=True, timeout=90)
                if result.returncode and args[:2] == ('workflow', 'run'):
                    def errors(value):
                        if isinstance(value, dict):
                            return [str(v)[:1200] for k, v in value.items() if k in ('error', 'failureReason', 'message') and v] + [a for v in value.values() for a in errors(v)]
                        if isinstance(value, list):
                            return [a for v in value for a in errors(v)]
                        return []
                    self.fail('Native command failed: ' + ' '.join(args[:3]) + str(errors(reply(result.stdout))) + (root / 'serve.log').read_text()[-1600:])
                self.assertEqual(result.returncode, 0, 'Native command failed: ' + ' '.join(args[:3]) + result.stderr[-1000:])
                return reply(result.stdout)

            workflow = cli('workflow', 'create', 'native-host-proof')
            Path(workflow['path']).write_text(json.dumps({'id': workflow['id'], 'name': 'native-host-proof',
                'labels': {'pool': 'migration', 'node': '209'}, 'queueTimeout': 10, 'affinity': True,
                'jobs': [{'name': 'proof', 'steps': [
                    {'name': 'remote', 'task': {'type': 'model_method', 'modelType': '@fixture/remote-refusal', 'modelName': 'refusal', 'methodName': 'probe'}},
                    {'name': 'native', 'dependsOn': [{'step': 'remote', 'condition': {'type': 'succeeded'}}],
                     'task': {'type': 'model_method', 'modelType': '@nckrtl/native-host-method', 'modelName': 'native-scan', 'methodName': 'project_scan',
                              'inputs': {'projectId': 20, 'runId': 'native-host-proof'}}},
                    {'name': 'coordinator', 'dependsOn': [{'step': 'native', 'condition': {'type': 'succeeded'}}],
                     'task': {'type': 'model_method', 'modelType': '@fixture/native-coordinator', 'modelName': 'coordinator', 'methodName': 'probe'}}]}]}))
            for name in ('groups-driver', 'group-fanout', 'group-run'):
                shutil.copy(REPO / f'workflows/workflow-{name}.yaml', root / f'workflows/workflow-{name}.yaml')
            definition = cli('behavior', 'create', '@nckrtl/orbit-group-runs', 'orbit-group-runs')
            Path(definition['path']).write_text(json.dumps({'id': definition['id'], 'type': '@nckrtl/orbit-group-runs', 'typeVersion': definition['version'], 'name': 'orbit-group-runs', 'version': 1, 'globalArguments': {'factories': []}}))
            cli('workflow', 'validate', 'native-host-proof')
            cli('access', 'token', 'mint', 'fixture-admin', '--principal', 'user:fixture')
            admin = root / 'admin'
            admin.write_text(cli('access', 'token', 'reveal', 'fixture-admin', '--yes')['token'])
            admin.chmod(0o600)
            with socket.socket() as sock:
                sock.bind(('127.0.0.1', 0))
                port = sock.getsockname()[1]
            url = f'ws://127.0.0.1:{port}'
            serve = worker = None
            with (root / 'serve.log').open('w') as log, (root / 'worker.log').open('w') as worker_log:
                try:
                    serve = subprocess.Popen([CLI, 'serve', '--host', '127.0.0.1', '--port', str(port), '--auth-mode', 'token', '--admins', 'user:fixture', '--remote-only', '--max-concurrent-scheduled-runs', '1'], cwd=root, env=env, stdout=log, stderr=log)
                    for _ in range(100):
                        try:
                            urllib.request.urlopen(f'http://127.0.0.1:{port}/ready', timeout=1).close()
                            break
                        except OSError:
                            self.assertIsNone(serve.poll())
                            time.sleep(.1)
                    flags = ('--server', url, '--token-file', str(admin))
                    enrollment = root / 'enrollment'
                    enrollment.write_text(cli('worker', 'token', 'create', 'fixture209', '--duration', '10m', *flags)['token'])
                    enrollment.chmod(0o600)
                    cli('access', 'token', 'mint', 'fixture-worker', '--principal', 'worker:fixture209', *flags)
                    server_token = root / 'worker-server'
                    server_token.write_text(cli('access', 'token', 'reveal', 'fixture-worker', '--yes')['token'])
                    server_token.chmod(0o600)
                    worker = subprocess.Popen([CLI, 'worker', 'connect', url, '--token-file', str(enrollment), '--server-token-file', str(server_token),
                                               '--cache-dir', str(root / 'worker-cache'), '--label', 'pool=migration', '--label', 'node=209'],
                                              cwd=root, env=env, stdout=worker_log, stderr=worker_log)
                    result = cli('workflow', 'run', 'native-host-proof', *flags)
                    self.assertEqual(result['status'], 'succeeded', (root / 'serve.log').read_text()[-2000:])
                    data = cli('data', 'query', 'modelName == "dependency-scanner-20" && name == "proof"', '--select', 'attributes', *flags)['results']
                    self.assertEqual(len(data), 1)
                    self.assertTrue(data[0]['nested'])
                    self.assertNotIn(data[0]['pid'], (serve.pid, worker.pid))
                    self.assertEqual(data[0]['runId'], 'native-host-proof')
                    nested = cli('data', 'query', 'modelName == "dependency-scanner-21" && name == "proof"', '--select', 'attributes', *flags)['results']
                    self.assertEqual(len(nested), 1)
                    self.assertTrue(nested[0]['nested'])
                    self.assertNotIn(nested[0]['pid'], (serve.pid, worker.pid))
                    self.assertEqual(nested[0]['runId'], 'coordinator-proof')
                    # Real shipped coordinator: the former unplaced step is refused;
                    # the shipped placed step runs on the worker with its native scratch context.
                    driver = root / 'workflows/workflow-groups-driver.yaml'
                    placed = driver.read_text()
                    driver.write_text(placed.replace('        labels: { pool: migration, node: "209" }\n', ''))
                    refused = subprocess.run([CLI, 'workflow', 'run', 'groups-driver', *flags, '--json'], cwd=root, env=env, capture_output=True, text=True, timeout=45)
                    self.assertNotEqual(refused.returncode, 0)
                    self.assertIn('remote-only', refused.stdout + refused.stderr)
                    driver.write_text(placed)
                    self.assertEqual(cli('workflow', 'run', 'groups-driver', *flags)['status'], 'succeeded')


                finally:
                    for process in (worker, serve):
                        if process is not None:
                            process.terminate()
                            try:
                                process.wait(timeout=15)
                            except subprocess.TimeoutExpired:
                                process.kill()
                                process.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
