"""Disposable native current-index lock contention; no Gateway, GitHub or SSH calls."""
import json
import os
import select
import subprocess

import yaml
from native_harness import ROOT, NativeTestCase


class TaskCheckActiveNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/task-repository', 'operations/tasks')
        script = self.repo / 'extensions/behaviors/operations/task-repository/check.ts'
        source = script.read_text()
        # A fixture-only method holds the actual native model lock at an
        # acknowledgement barrier. No application lock or timing race is added.
        source = source.replace('    methods: {', '''    methods: {
        fixture_finished: {
            description: "Supply a completed local check at the native admission boundary",
            arguments: ObjectValue,
            execute: (args: Record<string, unknown>, ctx: Context) => output(ctx, args),
        },
        fixture_hold: {
            description: "Hold native lock at a fixture acknowledgement barrier",
            arguments: z.object({ready: z.string(), barrier: z.string()}),
            async execute(args: {ready: string; barrier: string}) {
                await Deno.writeTextFile(args.ready, "1");
                const pipe = await Deno.open(args.barrier, {read: true});
                try { await pipe.read(new Uint8Array(1)); } finally { pipe.close(); }
                return {dataHandles: []};
            },
        },''', 1)
        script.write_text(source)
        self.native_env['SWAMP_LOCK_TIMEOUT_MS'] = '100'
        self.name = 'task-check-active-current'

    def method(self, name, value, success=True):
        return self.cli('behavior', 'run', '@nckrtl/task-check-data', name,
                        self.name, '--input', json.dumps(value), success=success)

    def row(self):
        return {'pending': True, 'inputs': {'kind': 'tasks.check', 'operation_id': 7,
                'node_id': 3, 'intent': {}},
                'root_run_id': 'a4944b1e-a6d9-4a8b-8ec0-c065b4a50caa',
                'result': {'commit': 'a' * 40}}

    def snapshot(self):
        result = json.loads(self.method('snapshot', {}).stdout)
        return [row['attributes'] for row in result['dataArtifacts'][0]['attributes']['checks']]

    def test_native_lock_timeout_preserves_admission_and_settle_for_a_later_call(self):
        self.method('initialize', {'checks': []})
        self.method('pending', self.row())
        admitted = {**self.row(), 'inputs': {**self.row()['inputs'], 'operation_id': 8}}
        ready, barrier = self.repo / 'ready', self.repo / 'release'
        os.mkfifo(ready)
        os.mkfifo(barrier)
        descriptor = os.open(ready, os.O_RDONLY | os.O_NONBLOCK)
        self.addCleanup(os.close, descriptor)
        command = [os.environ.get('SWAMP_BIN', 'swamp'), 'behavior', 'run',
                   '@nckrtl/task-check-data', 'fixture_hold', self.name,
                   '--input', json.dumps({'ready': str(ready), 'barrier': str(barrier)}), '--json']
        # Use the same isolated CLI environment as the native harness.
        env = dict(os.environ, SWAMP_NO_UPDATE_CHECK='1', SWAMP_NO_TELEMETRY='1')
        for key in ('SWAMP_SERVER_URL', 'SWAMP_SERVE_URL', 'SWAMP_SERVER_TOKEN',
                    'SWAMP_SERVER_TOKEN_FILE', 'SWAMP_REPO_DIR', 'SWAMP_CA_CERT',
                    'SWAMP_EXTENSIONS_DIR'):
            env.pop(key, None)
        env.update(self.native_env)
        process = subprocess.Popen(command, cwd=self.repo, env=env, stdout=subprocess.PIPE,
                                   stderr=subprocess.PIPE, text=True)
        def cleanup():
            if process.poll() is None:
                process.kill()
            process.communicate(timeout=10)
        self.addCleanup(cleanup)
        self.assertTrue(select.select([descriptor], [], [], 20)[0], 'Native hold did not acknowledge')
        self.assertEqual(os.read(descriptor, 1), b'1')
        for name, value in [('pending', admitted), ('settle', {'values': [{**self.row(), 'pending': False, 'status': 'succeeded'}]})]:
            result = self.method(name, value, success=False)
            self.assertEqual(result.returncode, 75, result.stdout + result.stderr)
            self.assertEqual(json.loads(result.stdout or result.stderr)['code'], 'lock_timeout', result.stdout + result.stderr)
        steps = self.admission_steps()
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']), {'error_code': 'tasks.check_active_unavailable'})
        self.assertEqual(steps['outcome']['status'], 'failed')
        self.assertEqual(steps['revoke_unused_read']['outputs']['stdout'].strip(), 'revoked')
        with barrier.open('wb') as release:
            release.write(b'1')
        stdout, stderr = process.communicate(timeout=20)
        self.assertEqual(process.returncode, 0, stdout + stderr)
        # These are explicit later invocations, never a retry loop in the kind.
        self.assertEqual([row['inputs']['operation_id'] for row in self.snapshot()], [7])
        self.method('pending', admitted)
        self.assertEqual([row['inputs']['operation_id'] for row in self.snapshot()], [7, 8])
        self.method('settle', {'values': [{**self.row(), 'pending': False, 'status': 'succeeded'}]})
        self.assertEqual([row['inputs']['operation_id'] for row in self.snapshot()], [8])

    def test_capacity_refusal_reaches_the_existing_root_terminal_result(self):
        rows = [{**self.row(), 'inputs': {**self.row()['inputs'], 'operation_id': i + 1}} for i in range(128)]
        self.method('initialize', {'checks': rows})
        steps = self.admission_steps()
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']), {'error_code': 'tasks.check_active_capacity'})
        self.assertEqual(steps['outcome']['status'], 'failed')
        self.assertEqual(steps['revoke_unused_read']['outputs']['stdout'].strip(), 'revoked')
        self.assertEqual(len(self.snapshot()), 128)

    def admission_steps(self, success=False):
        original = yaml.safe_load((ROOT / 'workflows/workflow-operation-task-check.yaml').read_text())
        retained = [step for step in original['jobs'][0]['steps'] if step['name'] in ('pending', 'revoke_unused_read', 'result', 'outcome')]
        for step in retained:
            step.pop('target')
            if step['name'] == 'revoke_unused_read':
                step['task']['inputs'] = {'run': "printf '%s\\n' revoked"}
        finish = {'name': 'finish', 'task': {'type': 'behavior', 'behaviorType': '@nckrtl/task-check-data',
                  'behaviorName': 'fixture-finish', 'methodName': 'fixture_finished', 'inputs': {
                      'pending': True, 'minted': True, 'result': self.row()['result'], 'pushed_at': '2026-11-01T12:00:00Z',
                      'result_json': json.dumps({'pending': True})}}}
        graph = {'id': original['id'], 'name': 'fixture-admission', 'inputs': original['inputs'], 'queueTimeout': 60,
                 'jobs': [{'name': 'check', 'steps': [finish, *retained]}]}
        (self.repo / 'workflows/workflow-fixture-admission.yaml').write_text(yaml.safe_dump(graph, sort_keys=False))
        inputs = {'operation_id': 1000, 'node_id': 3, 'kind': 'tasks.check',
                  'intent': {'admitted_at': '2026-11-01T12:00:00Z'}, 'execution_secrets': {}}
        result = self.cli('workflow', 'run', 'fixture-admission', '--input', json.dumps(inputs), success=success)
        self.assertTrue(result.stdout, result.stderr)
        run = json.loads(result.stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        return steps

    def test_empty_index_tick_waits_for_settlement_and_succeeds(self):
        self.method('initialize', {'checks': []})
        flow = yaml.safe_load((ROOT / 'workflows/workflow-task-check-ci.yaml').read_text())
        for job in flow['jobs']:
            job.pop('target', None)
            for step in job['steps']:
                step.pop('target', None)
        (self.repo / 'workflows/workflow-task-check-ci.yaml').write_text(yaml.safe_dump(flow, sort_keys=False))
        run = json.loads(self.cli('workflow', 'run', 'task-check-ci').stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        checks = next(job for job in history['jobs'] if job['name'] == 'checks')
        steps = {step['name']: step for step in checks['steps']}
        self.assertEqual(steps['settle']['status'], 'succeeded')
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']), {'checks': []})

    def test_admitted_handoff_keeps_its_read_token_for_the_schedule(self):
        self.method('initialize', {'checks': []})
        steps = self.admission_steps(success=True)
        self.assertEqual(steps['revoke_unused_read']['status'], 'skipped')
        self.assertEqual(json.loads(steps['result']['outputs']['stdout']), {'pending': True})
        self.assertEqual([row['inputs']['operation_id'] for row in self.snapshot()], [1000])
