"""Enrollment composes the public worker assets through unmodified official SSH."""
import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class EnrollmentWorkerPrerequisites(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-worker-assets')
        policy = self.repo / 'extensions/behaviors/node-worker'
        policy.mkdir(parents=True)
        shutil.copy(ROOT / 'extensions/behaviors/node-worker/config.ts', policy)
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit = self.repo / 'calls.jsonl'
        self.checker = self.repo / 'checker'
        self.failure = self.repo / 'fail-preflight'
        ssh = self.repo / 'fixture-ssh'
        ssh.write_text('#!' + sys.executable + '\n' + '''
import json,shlex,sys
from pathlib import Path
body=sys.stdin.read()
argv=shlex.split(sys.argv[-1])
action='preflight' if 'WORKER_USER = ' in body else 'checker' if '/dev/stdin' in argv else 'directory'
with Path(AUDIT).open('a') as stream: stream.write(json.dumps({'action':action,'argv':argv})+'\\n')
if action=='preflight' and Path(FAILURE).exists(): raise SystemExit(1)
if action=='checker': Path(CHECKER).write_text(body)
'''.replace('AUDIT', repr(str(self.audit))).replace('CHECKER', repr(str(self.checker)))
            .replace('FAILURE', repr(str(self.failure))))
        ssh.chmod(0o700)
        key = self.repo / 'identity'
        key.write_text('synthetic unused identity')
        self.execution = {'placement': {'target': 'orbit-node-14', 'labels': {'node': '14'}},
            'globalArgs': {'name': 'enrollment-worker', 'sshBinary': str(ssh),
                'transport': {'kind': 'ssh', 'user': 'orbit', 'identityFile': str(key),
                    'hostKeys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']},
                'hosts': [{'name': 'peer', 'address': 'dev.invalid'}]}}

    def run_parent(self, failure=False, selected=True):
        if failure:
            self.failure.touch()
        module = (ROOT / 'extensions/behaviors/operations/node-bootstrap/worker_prerequisite_steps.ts').as_uri()
        code = ('import {workerPrerequisiteSteps} from ' + json.dumps(module) + ';console.log(JSON.stringify(' +
                'workerPrerequisiteSteps("worker","inputs.node_id","inputs.user",' +
                json.dumps(self.execution) + ',"begin",' + json.dumps('true' if selected else 'false') + ')));')
        deno = os.environ.get('DENO_BIN', shutil.which('deno') or '/tmp/lane4-deno-2.9.8/deno')
        steps = json.loads(subprocess.run([deno, 'eval', code], capture_output=True, text=True, check=True).stdout)
        for step in steps:
            self.assertEqual(step.pop('target'), 'orbit-node-14')
            self.assertEqual(step.pop('labels'), {'node': '14'})
        begin = {'name': 'begin', 'task': {'type': 'behavior', 'behaviorType': 'command/shell',
                 'behaviorName': 'prerequisites-begin', 'methodName': 'execute', 'inputs': {'run': '/usr/bin/true'}}}
        workflow = {'id': 'fd1f69a5-e80a-4d38-b0ef-8b6fc1387bb1', 'name': 'enrollment-worker-prerequisites',
            'inputs': {'type': 'object', 'properties': {'node_id': {'type': 'integer'}, 'user': {'type': 'string'}}},
            'jobs': [{'name': 'bootstrap', 'steps': [begin, *steps]}]}
        (self.repo / 'workflows/workflow-enrollment-worker-prerequisites.yaml').write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli('workflow', 'validate', 'enrollment-worker-prerequisites')
        run = json.loads(self.cli('workflow', 'run', 'enrollment-worker-prerequisites',
                                 '--input', 'node_id=212', '--input', 'user=orbit', success=not failure).stdout)
        return json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)

    def test_public_checker_is_published_after_preflight_without_starting_a_unit(self):
        history = self.run_parent()
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        assets = steps['worker_assets-0']['outputs']
        self.assertEqual(self.checker.read_text(), assets['checker'])
        self.assertIn('ExecStartPre=+/usr/local/libexec/orbit-node-worker-check', assets['unit'])
        calls = [json.loads(row) for row in self.audit.read_text().splitlines()]
        self.assertEqual([row['action'] for row in calls], ['preflight', 'directory', 'checker'])
        self.assertIn('0755', calls[-1]['argv'])
        self.assertTrue(all('/usr/bin/systemctl' not in row['argv'] for row in calls))

    def test_failed_preflight_prevents_checker_publication(self):
        history = self.run_parent(failure=True)
        self.assertEqual(history['status'], 'failed')
        self.assertFalse(self.checker.exists())
        calls = [json.loads(row) for row in self.audit.read_text().splitlines()]
        self.assertEqual([row['action'] for row in calls], ['preflight'])

    def test_unselected_worker_has_no_prerequisite_effects(self):
        history = self.run_parent(selected=False)
        self.assertEqual(history['status'], 'succeeded')
        self.assertFalse(self.audit.exists())
        self.assertFalse(self.checker.exists())
