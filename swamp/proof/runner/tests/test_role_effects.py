"""Role proof must reject a missing effect or incomplete/changed Ansible recap."""
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import Mock

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
import runner
import spec
import steps
from fakes import FakeFleet


class RoleEffectsTest(unittest.TestCase):
    def test_role_cases_read_the_named_assignment_and_refuse_missing_or_duplicate_roles(self):
        root = Path(__file__).resolve().parents[4]
        loaded = spec.load(root / 'swamp/proof/node/role-add.cases.yaml', runner.BATCHES)
        assignments = [{'id': 7, 'role': 'app-dev', 'status': 'active'}, {'id': 9, 'role': 'database', 'status': 'active'}]
        for case_id in ['required-only', 'application-repeat', 'failure-after-partial-change']:
            for stage in loaded.case(case_id).stages:
                for step in stage.act:
                    if step.name != 'cli' or step.args['run'][0] != 'node:role:list':
                        continue
                    wanted = step.args['expect']['json']
                    self.assertEqual(steps.check_json({'assignments': list(reversed(assignments))}, wanted), [])
                    self.assertTrue(steps.check_json({'assignments': []}, wanted))
                    self.assertTrue(steps.check_json({'assignments': assignments * 2}, wanted))

    def test_zero_change_check_rejects_changed_failed_missing_and_empty_phase(self):
        args = {'phases': ['tools'], 'changed': 0}
        good = {'status': 'succeeded', 'hosts': [{'host': 'n7', 'changed': 0, 'failed': 0, 'unreachable': 0}]}
        self.assertEqual(steps.check_ansible_changes({'tools': good}, args), [])
        for bad in ({}, {'tools': {'status': 'skipped', 'hosts': []}}, {'tools': {'status': 'succeeded', 'hosts': []}}):
            self.assertTrue(steps.check_ansible_changes(bad, args))
        for key in ['changed', 'failed', 'unreachable']:
            host = {**good['hosts'][0], key: 1}
            self.assertTrue(steps.check_ansible_changes({'tools': {**good, 'hosts': [host]}}, args))

    def test_a_missing_service_or_package_fails_even_when_the_operation_succeeded(self):
        with tempfile.TemporaryDirectory() as directory:
            run = runner.Run(FakeFleet(), spec.Spec('node:role:add', 'node/role-add', 'app-dev', [], [], []), directory)
            run.fleet.sh = Mock(return_value=subprocess.CompletedProcess([], 3, 'inactive\n', ''))
            self.assertTrue(steps.service(run, {'on': 'app-dev', 'name': 'docker.service', 'state': 'active'}))
            self.assertTrue(steps.package(run, {'on': 'app-dev', 'any': ['docker.io', 'docker-ce']}))
            run.fleet.sh.return_value = subprocess.CompletedProcess([], 0, 'active\n', '')
            self.assertEqual(steps.service(run, {'on': 'app-dev', 'name': 'docker.service', 'state': 'active'}), [])
            run.fleet.sh.return_value = subprocess.CompletedProcess([], 0, 'installed', '')
            self.assertEqual(steps.package(run, {'on': 'app-dev', 'any': ['docker.io', 'docker-ce']}), [])

    def test_role_success_cases_assert_effects_and_only_new_refusals_replay_on_base(self):
        root = Path(__file__).resolve().parents[4]
        loaded = spec.load(root / 'swamp/proof/node/role-add.cases.yaml', root / 'swamp/proof/batches.yaml')
        self.assertEqual(len(loaded.cases), 18)
        for case_id in ['required-only', 'application-repeat', 'failure-after-partial-change']:
            case = loaded.case(case_id)
            checks = [step.name for stage in case.stages for step in stage.expect]
            self.assertIn('service', checks)
        for case_id in ['required-only', 'application-repeat']:
            self.assertIn('ansible_changes', [step.name for stage in loaded.case(case_id).stages for step in stage.expect])
        self.assertEqual([case.id for case in loaded.cases if case.fails_on_base], [
            'analytics-option-on-other-role[postgres]',
            'analytics-option-on-other-role[clickhouse]', 'shared-node-authority'])


if __name__ == '__main__':
    unittest.main()
