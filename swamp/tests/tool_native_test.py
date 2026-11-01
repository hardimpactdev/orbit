"""Tool workflow graphs on the pinned runtime; official SSH is a fixture that answers each phase from canned JSON."""
import json
import sys
import unittest

import yaml
from native_harness import ROOT, NativeTestCase

FACTS = {'manager_version': 'apt 3.0', 'versions': [], 'complete': True}
SSH = {'control_node_id': 209, 'identity_file': '/unused/fixture', 'target': {'name': 'target', 'host': 'node.invalid', 'port': 22, 'user': 'orbit',
       'host_keys': ['ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG']}}
FIXTURE = r'''
import json, shlex, sys
from pathlib import Path
command = shlex.split(sys.argv[-1])
phase = command[command.index('-c') + 2]
parts = sys.stdin.read().split('\x1e')
json.loads(parts[0])
with Path(AUDIT).open('a') as out:
    out.write(json.dumps({'phase': phase, 'argv': command[command.index('-c') + 3:], 'installers': len(parts) - 1}) + '\n')
reply = json.loads(Path(REPLIES).read_text())[phase]
print(json.dumps(reply))
# Like phase.py: a phase that did not do its job exits 1.
sys.exit(1 if reply is None or reply.get('ready') is False or reply.get('written') is False else 0)
'''


class ToolNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/tools', 'operations/node-bootstrap')
        self.cli('extension', 'pull', '@swamp/ssh@2026.10.23.1')
        self.audit, self.replies = self.repo / 'audit.jsonl', self.repo / 'replies.json'
        fixture = self.repo / 'fixture-ssh'
        fixture.write_text('#!' + sys.executable + '\n' + FIXTURE.replace('AUDIT', repr(str(self.audit))).replace('REPLIES', repr(str(self.replies))))
        fixture.chmod(0o700)
        for name in ('write', 'remove'):
            path = f'workflows/workflow-operation-tool-{name}-209.yaml'
            workflow = yaml.safe_load((ROOT / path).read_text())
            for step in workflow['jobs'][0]['steps']:
                self.assertEqual(step.pop('target'), 'orbit-node-209')
                if step['task'].get('behaviorType') == '@swamp/ssh':
                    step['task']['globalArgs']['sshBinary'] = str(fixture)
            (self.repo / path).write_text(yaml.safe_dump(workflow, sort_keys=False))

    def run_kind(self, workflow, kind, replies, success=True, **intent):
        self.replies.write_text(json.dumps(replies))
        self.audit.unlink(missing_ok=True)
        inputs = {'operation_id': 1, 'node_id': 9, 'kind': kind, 'execution_secrets': {}, 'ssh': SSH,
                  'intent': {'kind': kind, 'node_id': 9, 'manager': 'apt', 'package': 'hello', 'user': 'orbit', 'platform': 'linux',
                             'architecture': 'x86_64', 'tool_id': None, 'setup': None, 'repository': None, 'remove_repository': False,
                             'repository_given': False, 'manager_ready': False, **intent}}
        run = json.loads(self.cli('workflow', 'run', f'operation-tool-{workflow}-209', '--stdin', input=json.dumps(inputs), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step['name']: step for step in history['jobs'][0]['steps']}
        self.calls = [json.loads(line) for line in self.audit.read_text().splitlines()] if self.audit.exists() else []
        self.ran = [name for name, step in steps.items() if step['status'] != 'skipped' and name[0].isupper()]
        return history['status'], json.loads(steps['result']['outputs']['stdout']), [call['phase'] for call in self.calls]

    def test_install_runs_the_chart_steps_and_records_the_installed_version(self):
        installed = {**FACTS, 'versions': ['2.0-1']}
        status, result, phases = self.run_kind('write', 'tool.install', {'check': {'facts': FACTS, 'candidate': {'version': '2.0-1'}},
                                                                          'write': {'written': True, 'facts': installed}})
        self.assertEqual((status, phases, self.ran), ('succeeded', ['check', 'write'], ['Check the candidate', 'Install the package']))
        self.assertEqual(result, {'manager_version': 'apt 3.0', 'installed_version': '2.0-1', 'outcome': 'applied'})

    def test_constraint_and_failed_setup_stop_before_the_write(self):
        status, result, phases = self.run_kind('write', 'tool.install', {'check': {'facts': FACTS, 'candidate': {'version': '2.0-1'}}},
                                               success=False, version_constraint='^1.0')
        self.assertEqual((status, phases, result['error_code']), ('failed', ['check'], 'tool.version_constraint_blocked'))
        setup = {'manager': 'composer', 'input': {'user': 'orbit', 'version': '2.9.5-1'}, 'packages': [], 'apt': {'snapshot': '20260101T000000Z', 'packages': []}}
        status, result, phases = self.run_kind('write', 'tool.install', {'setup': None}, success=False, manager='composer', package='vendor/package', setup=setup)
        self.assertEqual((phases, result['error_code'], result['manager_version']), (['setup'], 'tool.manager_provision_failed', None))

    def test_first_use_installers_reach_set_up_on_stdin_only_when_the_manager_is_not_ready(self):
        setup = {'manager': 'composer', 'input': {'user': 'orbit', 'version': '2.9.5-1'}, 'packages': [], 'apt': {'snapshot': '20260101T000000Z', 'packages': []}}
        replies = {'setup': {'facts': FACTS}, 'check': {'facts': FACTS, 'candidate': {'version': '2.0'}}, 'write': {'written': True, 'facts': {**FACTS, 'versions': ['2.0']}}}
        status, _, phases = self.run_kind('write', 'tool.install', replies, manager='composer', package='vendor/package', setup=setup)
        self.assertEqual((status, phases, self.calls[0]['installers']), ('succeeded', ['setup', 'check', 'write'], 2))
        status, _, phases = self.run_kind('write', 'tool.install', replies, manager='composer', package='vendor/package', setup=setup, manager_ready=True)
        self.assertEqual((status, phases), ('succeeded', ['check', 'write']))
        # The development deploy's dev_manager child: kind tool.manager_converge, no package.
        status, result, phases = self.run_kind('write', 'tool.manager_converge', replies, package='', manager='composer', setup=setup)
        self.assertEqual((status, phases, result['manager_version']), ('succeeded', ['setup'], 'apt 3.0'))

    def test_update_updates_the_given_source_and_upgrades_only_a_newer_candidate(self):
        present = {**FACTS, 'versions': ['1.0']}
        replies = {'source': {'ready': True}, 'check': {'facts': present, 'candidate': {'version': '1.1'}}, 'write': {'written': True, 'facts': {**FACTS, 'versions': ['1.1']}}}
        status, result, phases = self.run_kind('write', 'tool.update', replies, tool_id=1, installed_version='1.0', repository={'name': 'vendor', 'uris': ['https://packages.example.test/apt'], 'suites': ['preview'], 'components': ['main'], 'signing_key': {'url': 'https://packages.example.test/key.gpg', 'fingerprint': 'A' * 40}}, repository_given=True)
        self.assertEqual((status, self.ran, result['outcome']), ('succeeded', ['Update the apt source', 'Check the candidate', 'Upgrade the package'], 'applied'))
        status, result, phases = self.run_kind('write', 'tool.update', {**replies, 'check': {'facts': present, 'candidate': {'version': '1.0'}}}, tool_id=1, installed_version='1.0')
        self.assertEqual((status, phases, result['outcome']), ('succeeded', ['check'], 'unchanged'))
        status, result, phases = self.run_kind('write', 'tool.update', {**replies, 'source': {'ready': False, 'error_code': 'tool.repository_key_mismatch'}},
                                               success=False, tool_id=1, installed_version='1.0', repository={'name': 'vendor', 'uris': ['https://packages.example.test/apt'], 'suites': ['preview'], 'components': ['main'], 'signing_key': {'url': 'https://packages.example.test/key.gpg', 'fingerprint': 'A' * 40}}, repository_given=True)
        self.assertEqual((phases, result['error_code']), (['source'], 'tool.repository_key_mismatch'))

    def test_remove_proves_absence_and_keeps_a_source_it_could_not_remove(self):
        present = {**FACTS, 'versions': ['1.0']}
        replies = {'plan': {'facts': present, 'plan': {'packages': ['hello']}}, 'remove': {}, 'gone': {'facts': FACTS}, 'remove_source': None}
        status, result, phases = self.run_kind('remove', 'tool.remove', replies, installed_version='1.0')
        self.assertEqual((status, phases, result['outcome']), ('succeeded', ['plan', 'remove', 'gone'], 'applied'))
        # Dropping the last Tool's apt source is best-effort: its failure keeps the run successful.
        repository = {'name': 'vendor', 'uris': ['https://packages.example.test/apt'], 'suites': ['preview'], 'components': ['main'],
                      'signing_key': {'url': 'https://packages.example.test/key.gpg', 'fingerprint': 'A' * 40}}
        status, result, phases = self.run_kind('remove', 'tool.remove', replies, installed_version='1.0', repository=repository, remove_repository=True)
        self.assertEqual((status, phases, result['repository_removed']), ('succeeded', ['plan', 'remove', 'gone', 'remove_source'], False))
        status, result, _ = self.run_kind('remove', 'tool.remove', {**replies, 'gone': None}, success=False, installed_version='1.0')
        self.assertEqual((status, result['error_code']), ('failed', 'tool.remove_failed'))


if __name__ == '__main__':
    unittest.main()
