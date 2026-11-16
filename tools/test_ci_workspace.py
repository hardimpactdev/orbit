"""Whole-file workspace headroom and registry-only Python fixture admission."""
import json
import math
import runpy
import unittest
from pathlib import Path
from unittest.mock import patch

from test_ci_units import ROOT, names, units

EVIDENCE = ROOT / 'tools/tests/ci-workspace-cost-evidence.json'


class WorkspaceFixturesTest(unittest.TestCase):
    def test_every_workspace_case_is_retained_in_a_bounded_whole_file(self):
        evidence = json.loads(EVIDENCE.read_text())['files']
        budget, fixtures, defaults = units.configuration()
        result = units.plan(['swamp/tests/_support/task_instance_workspace.py'], budget, fixtures, defaults)
        self.assertCountEqual(names(result), [*evidence, "swamp/tests/task_copy_publish_test.py"])
        self.assertFalse(result.get('capacity'))
        seen = []
        for file, measured in evidence.items():
            with self.subTest(file=file):
                current = units.collect(file)
                self.assertCountEqual(current['cases'], measured['case_seconds'])
                seen.extend(current['cases'])
                entry = fixtures[file]
                self.assertFalse(units.sliceable(entry))
                self.assertFalse(entry.get('self_hosted'))
                for case, seconds in measured['case_seconds'].items():
                    self.assertGreaterEqual(entry['case_costs'][case], math.ceil(seconds * 2.1) + 1)
                self.assertEqual(entry['cost'], sum(entry['case_costs'].values()))
                self.assertEqual(units.units_for(entry, budget), [(file, entry['cost'])])
                self.assertLessEqual(entry['cost'] + budget['slice_startup_seconds'], 90)
        self.assertEqual(len(seen), 58)
        self.assertEqual(len(set(seen)), 58)
        publish = fixtures['swamp/tests/task_copy_publish_test.py']
        current = units.collect(publish['file'])
        self.assertEqual(publish['cases'], current['cases'])
        self.assertEqual(len(current['cases']), 26)
        self.assertFalse(set(seen) & set(current['cases']))
        self.assertLessEqual(publish['cost'] + budget['slice_startup_seconds'], 90)
        harness = runpy.run_path(str(ROOT / 'swamp/tests/_support/task_instance_workspace.py'))
        for name in ('WorkspaceHarness', 'CheckHarness'):
            self.assertEqual(unittest.defaultTestLoader.getTestCaseNames(harness[name]), [])

    def test_registered_python_fixture_runs_without_a_gate_listing(self):
        file = 'swamp/tests/task_instance_workspace_export_test.py'
        # Model an author adding a fixture and its registry entry only.
        steps = units.fixture_steps()
        steps.pop(file)
        with patch.object(units, 'fixture_steps', return_value=steps):
            budget, fixtures, defaults = units.configuration()
            result = units.plan([file], budget, fixtures, defaults)
        self.assertEqual(names(result), [file])
        self.assertFalse(result.get('capacity'))
        step = units.unit_step(file, fixtures, steps, Path('/collections'))
        self.assertEqual(step.argv, ['python3', '-I', 'tests/task_instance_workspace_export_test.py'])
        self.assertEqual(step.env['ANSIBLE_COLLECTIONS_PATH'], '/collections')
