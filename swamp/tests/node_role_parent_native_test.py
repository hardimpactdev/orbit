"""Owning role parents on native Swamp with official SSH and disposable host seams."""
import copy
import json
import os
import shutil
from pathlib import Path

import node_database_role_native_test as database
import yaml
from native_harness import ROOT


class RoleParentNative(database.DatabaseRoleNative):
    native_timeout = 180

    def setUp(self):
        super().setUp()
        self.copy_behaviors('operations/node-role-parent', 'operations/node-removal-command')
        shutil.copytree(ROOT/'extensions/behaviors/operations/routes/mutation-command',
                        self.repo/'extensions/behaviors/operations/routes/mutation-command')
        policy = self.repo/'extensions/behaviors/operations/routes/publication-command'
        policy.mkdir()
        shutil.copy(ROOT/'extensions/behaviors/operations/routes/publication-command/sections.ts', policy)
        for name in ['node-role-converge-retained', 'operation-route-caddy-delta', 'operation-route-php-delta', 'route-dns-publish']:
            flow = yaml.safe_load((ROOT/('workflows/workflow-'+name+'.yaml')).read_text())
            flow.pop('target', None)
            flow['jobs'] = [{'name': 'boundary', 'steps': [{'name': 'result', 'task': {'type': 'assert', 'expr': 'true', 'message': 'Unselected or separately tested shared child'}}]}]
            (self.repo/('workflows/workflow-'+name+'.yaml')).write_text(yaml.safe_dump(flow, sort_keys=False))
        for name in ['operation-node-role-add-direct', 'operation-node-role-relocate-direct', 'node-role-remove', 'route-dns-grace']:
            flow = yaml.safe_load((ROOT/('workflows/workflow-'+name+'.yaml')).read_text())
            flow.pop('target', None)
            for job in flow['jobs']:
                job.pop('target', None)
                for step in job['steps']:
                    self.assertEqual(step.pop('target'), 'orbit-node-209')
                    self.assertNotIn('labels', step)
                    task = step['task']
                    if task.get('behaviorType') == '@swamp/ssh':
                        task['globalArgs']['sshBinary'] = str(self.repo/'fixture-ssh')
            (self.repo/('workflows/workflow-'+name+'.yaml')).write_text(yaml.safe_dump(flow, sort_keys=False))
        self.input['kind'] = 'node.role_add'
        self.input['intent'] = {'target': self.input['intent']}
        self.input['intent']['target']['created'] = True
        self.timings = []

    def run_parent(self, success=True):
        name = 'operation-node-role-relocate-direct' if self.input['kind'] == 'node.role_relocate' else 'operation-node-role-add-direct'
        self.cli('workflow', 'validate', name)
        run = json.loads(self.cli('workflow', 'run', name, '--input', json.dumps(self.input), success=success).stdout)
        history = json.loads(self.cli('workflow', 'history', 'get', run['id']).stdout)
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        result = json.loads(steps['result']['outputs']['stdout'])
        evidence = os.environ.get('ROLE_PARENT_NATIVE_EVIDENCE')
        if evidence:
            destination = Path(evidence)
            destination.mkdir(parents=True, exist_ok=True)
            (destination/(name+'-'+run['id']+'.json')).write_text(json.dumps(history, indent=2))
        return history, result

    def test_add_success_and_failure_preserve_new_assignment_facts(self):
        for _ in range(3):
            history, result = self.run_parent()
            self.assertEqual(history['status'], 'succeeded')
            self.assertTrue(result['ready'])
            self.assertTrue(result['target']['created'])
            self.assertEqual(result['target']['assignment_id'], 17)
        self.failure.write_text('packages')
        history, result = self.run_parent(False)
        self.assertEqual(history['status'], 'failed')
        self.assertFalse(result['ready'])
        self.assertFalse(result['target_completed'])
        self.assertTrue(result['target']['created'])

    def prepare_move(self):
        self.input['kind'] = 'node.role_relocate'
        target = self.input['intent']['target']
        target.update(role='websocket', created=False,
                      runtime={'node_id': 5, 'assignment_id': 17, 'user': 'orbit', 'install_path': '/srv/reverb',
                               'repository': 'https://example.invalid/reverb.git', 'ref': 'b'*40, 'port': 8088},
                      caddy_package={'release': '2.11.1', 'minimum_version': '2.10.2', 'amd64_sha512': 'a'*128},
                      certificates={'leaves': [{'node_id': 5, 'scope': 'role-websocket', 'domain': 'reverb.orbit',
                                                'version': 'a'*16, 'root_hash': 'b'*64}], 'withdrawals': []},
                      publications=[], firewall={'desired': [], 'remove': []})
        self.input['execution_secrets']['runtime_material'] = copy.deepcopy(self.input['execution_secrets']['observer'])
        source = {'schema_version': 1, 'node_id': 6, 'assignment_id': 17, 'role': 'websocket', 'ssh': target['ssh'],
                  **{name: [] for name in ['publications', 'stop_units', 'remove_footprints', 'reload_units', 'remove_units',
                                           'stop_containers', 'remove_containers', 'firewall', 'certificates', 'ci_host']}}
        self.input['intent'].update(source=source, withdrawal_grace_seconds=31)

    def test_relocate_observes_native_dns_grace_before_source_cleanup(self):
        self.prepare_move()
        history, result = self.run_parent()
        self.assertTrue(result['target_completed'], result)
        self.assertTrue(result['source_completed'], result)
        self.assertTrue(result['ready'], result)
        steps = {step['name']: step for job in history['jobs'] for step in job['steps']}
        self.assertEqual(steps['source_grace']['status'], 'succeeded')
        self.assertEqual(steps['source_cleanup']['status'], 'succeeded')

    def test_target_failure_skips_source_even_when_contribution_is_returned(self):
        self.prepare_move()
        self.failure.write_text('packages')
        history, result = self.run_parent(False)
        self.assertFalse(result['target_completed'])
        self.assertFalse(result['source_completed'])
        source = next(job for job in history['jobs'] if job['name'] == 'source')
        self.assertEqual(source['status'], 'skipped')


def load_tests(loader, _tests, _pattern):
    import unittest
    names = [name for name in RoleParentNative.__dict__ if name.startswith('test_')]
    return unittest.TestSuite(RoleParentNative(name) for name in names)


if __name__ == '__main__':
    import unittest
    unittest.main()
