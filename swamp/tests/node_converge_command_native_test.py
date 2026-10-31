"""Real native command steps with isolated public input and a scratch tmpfiles target."""
import json
import re
from pathlib import Path
import shutil
import sys
import unittest
import uuid

import yaml

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'tests'))
from native_harness import NativeTestCase


class NodeConvergeCommands(NativeTestCase):
    native_timeout = 60

    def setUp(self):
        super().setUp()
        node = self.repo / 'extensions/behaviors/operations/node'
        node.mkdir(parents=True)
        for name in ['converge_native.ts', 'converge_direct_contract.ts', 'network_contract.ts', 'footprint_proof.ts']:
            shutil.copy(ROOT / 'extensions/behaviors/operations/node' / name, node / name)
        self.workflow = yaml.safe_load((ROOT / 'workflows/workflow-operation-node-converge-direct.yaml').read_text())
        self.inputs = {'operation_id': 777, 'node_id': 12, 'kind': 'node.converge', 'execution_secrets': {},
            'intent': {'schema_version': 1, 'node_id': 12, 'agent_sha256': 'a' * 64,
                'force_artifacts': False, 'first_visit': False, 'artifacts': [],
                'payloads': {}, 'network': None, 'refusal': None}}
        self.scratch_target = self.repo / 'tmpfiles.conf'
        for job in self.workflow['jobs']:
            for step in job['steps']:
                # Local native fixture only: selectors are checked separately in source.
                step.pop('target', None)
                step.pop('labels', None)
                if step['name'] == 'tmpfiles':
                    code = step['task']['inputs']['run']
                    code = code.replace('exec /usr/bin/sudo -n -- /usr/bin/python3', 'exec /usr/bin/python3')
                    code, count = re.subn(r'TMPFILES = Path\([\'\"]/etc/tmpfiles.d/orbit.conf[\'\"]\)', f'TMPFILES = Path({str(self.scratch_target)!r})', code)
                    self.assertEqual(count, 1, 'Fixture must redirect the exact tmpfiles target')
                    code = code.replace('UID, GID = (0, 0)', 'UID, GID = (os.geteuid(), os.getegid())')
                    step['task']['inputs']['run'] = code
                if step['name'].startswith('php_runtime-${'):
                    code = step['task']['inputs']['run']
                    code = code.replace('exec /usr/bin/sudo -n -- /usr/bin/python3', 'exec /usr/bin/python3')
                    code, count = re.subn(r'ROOT = Path\([\'\"]/[\'\"]\)', f'ROOT = Path({str(self.repo / "php-root")!r})', code)
                    self.assertEqual(count, 1, 'Fixture must redirect the PHP filesystem root')
                    code, count = re.subn(r'[\'\"]/run/lock/orbit-php-runtime.lock[\'\"]', repr(str(self.repo / 'php-runtime.lock')), code)
                    self.assertEqual(count, 1, 'Fixture must redirect the PHP host lock')
                    step['task']['inputs']['run'] = code
        self.public_dirs = set()
        self.addCleanup(self.cleanup_public_input)

    def cleanup_public_input(self):
        for run in self.public_dirs:
            shutil.rmtree('/tmp/orbit-node-converge-' + run, ignore_errors=True)

    def run_workflow(self, success=True):
        (self.repo / 'workflows/workflow-operation-node-converge-direct.yaml').write_text(json.dumps(self.workflow))
        self.cli('workflow', 'validate', self.workflow['name'])
        command = ['workflow', 'run', self.workflow['name']]
        for key, value in self.inputs.items():
            command.extend(['--input', f'{key}:json=' + json.dumps(value)])
        result = self.cli(*command, success=success)
        data = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        self.run_id = data['id']
        uuid.UUID(self.run_id)
        self.public_dirs.add(self.run_id)
        if success:
            self.assertFalse(Path('/tmp/orbit-node-converge-' + self.run_id).exists(), 'Successful native root must remove public staging')
        return json.loads(self.cli('workflow', 'history', 'get', self.run_id).stdout)

    def terminal_result(self, data):
        projection = next(j for j in data['jobs'] if j['name'] == 'projection')
        result = next(s for s in projection['steps'] if s['name'] == 'result')
        return json.loads(result['outputs']['stdout'])

    def test_public_only_noop_needs_no_gateway_or_secret(self):
        data = self.run_workflow()
        self.assertEqual(data['status'], 'succeeded')
        self.assertEqual(self.terminal_result(data), {'footprint_proven': True, 'artifacts': []})
        self.assertFalse(self.scratch_target.exists())

    def test_real_inline_tmpfiles_action_and_terminal_public_result(self):
        self.inputs['intent']['artifacts'] = [{'name': 'tmpfiles', 'digest': 'b' * 64, 'required': True, 'repair': False}]
        self.inputs['intent']['payloads'] = {'tmpfiles': {'rule': 'e /tmp/orbit-native - - - 1d\n'}}
        data = self.run_workflow()
        self.assertEqual(self.scratch_target.read_text(), 'e /tmp/orbit-native - - - 1d\n')
        self.assertEqual(self.terminal_result(data), {'footprint_proven': True, 'artifacts': [{'name': 'tmpfiles', 'status': 'applied'}]})

    def test_command_failure_keeps_artifact_unproven(self):
        self.inputs['intent']['artifacts'] = [{'name': 'tmpfiles', 'digest': 'b' * 64, 'required': True, 'repair': False}]
        self.inputs['intent']['payloads'] = {'tmpfiles': {'rule': 'd /etc/foreign - - - 1d\n'}}
        data = self.run_workflow(success=False)
        self.assertFalse(self.scratch_target.exists())
        result = self.terminal_result(data)
        self.assertFalse(result['footprint_proven'])
        self.assertEqual(result['failed_artifact'], 'tmpfiles')
        self.assertEqual(result['artifacts'], [])

    def test_nonempty_php_fanout_finishes_before_verification(self):
        release = self.repo / 'php-root/etc/os-release'
        release.parent.mkdir(parents=True)
        release.write_text('ID=ubuntu\nVERSION_CODENAME=resolute\n')
        self.inputs['intent']['artifacts'] = [{'name': 'php-runtime', 'digest': 'b' * 64, 'required': True, 'repair': False}]
        ini = ('post_max_size = 64M\nupload_max_filesize = 64M\nopcache.enable = On\n'
               'opcache.jit = disable\nopcache.jit_buffer_size = 0\nopcache.memory_consumption = 256\n'
               'opcache.interned_strings_buffer = 32\nopcache.max_accelerated_files = 20000\n')
        self.inputs['intent']['payloads'] = {'php-runtime': {'configurations': [
            {'version': version, 'runtime_ini': ini, 'release': {'id': 'ubuntu', 'codenames': ['resolute']}}
            for version in ['8.4', '8.5']]}}
        data = self.run_workflow()
        self.assertEqual(self.terminal_result(data), {'footprint_proven': True, 'artifacts': [{'name': 'php-runtime', 'status': 'unchanged'}]})
        jobs = {job['name']: job for job in data['jobs']}
        verify = jobs['php_runtime_verify_job']['steps'][0]
        for step in jobs['php_runtime_job']['steps']:
            self.assertEqual(step['status'], 'succeeded')
            self.assertLessEqual(step['completedAt'], verify['startedAt'])


if __name__ == '__main__':
    unittest.main()
