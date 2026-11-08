"""The case comparison uses the same fixtures as Gateway Pest tests."""
import copy
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parent.parent
loader = importlib.machinery.SourceFileLoader('case_plan', str(ROOT / 'bin/ci-build-plan'))
spec = importlib.util.spec_from_loader(loader.name, loader)
plan = importlib.util.module_from_spec(spec)
loader.exec_module(plan)


class ProofCaseSelectionTest(unittest.TestCase):
    def test_shared_fixtures(self):
        fixture = json.loads((ROOT / 'tools/fixtures/proof-case-selection.json').read_text())
        for test in fixture['tests']:
            with self.subTest(test['name']):
                before = None if test.get('new_file') else {**copy.deepcopy(fixture['base']), **test.get('before', {})}
                after = {**copy.deepcopy(fixture['base']), **test['after']}
                self.assertEqual(plan.case_ids(before, after, test.get('code_changed', False), test.get('before_batches'), test.get('after_batches')), test['expected'])

    def test_full_planner_keeps_case_ids_and_ignores_documentation(self):
        file = 'swamp/proof/demo/run.cases.yaml'
        before = 'command: demo:run\ncases:\n  - {id: a, batch: fresh, summary: old}\n  - {id: b, batch: fresh}\n'
        after = before.replace('summary: old', 'summary: new')
        selection = f'docs/** skip\napps/demo/** demo:run\n{file} demo:run\nswamp/proof/batches.yaml none: batch dependencies\n'
        def read(ref, path):
            return 'batches: {fresh: {}}' if path.endswith('batches.yaml') else before if ref == 'base' else after
        result = plan.proof_cases([file], selection, read, [file])
        self.assertEqual(result, [{'command': 'demo:run', 'file': file, 'only': ['a'], 'reasons': {'a': 'changed'}}])
        self.assertEqual(plan.proof_cases(['docs/a.md'], selection, read, [file]), [])
        self.assertEqual(plan.proof_cases(['apps/demo/run.php', file], selection, read, [file])[0]['only'], ['a', 'b'])

    def test_malformed_or_empty_selected_cases_fail(self):
        for after in ({}, {'cases': []}, {'cases': [{'id': 'duplicate'}, {'id': 'duplicate'}]}):
            with self.subTest(after=after), self.assertRaises(ValueError):
                plan.case_ids(None, after)


if __name__ == '__main__':
    unittest.main()
