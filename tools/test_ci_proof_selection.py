"""The case comparison uses the same fixtures as Gateway Pest tests."""
import importlib.machinery
import importlib.util
import json
from pathlib import Path
import unittest
import sys

ROOT = Path(__file__).resolve().parent.parent
loader = importlib.machinery.SourceFileLoader('case_plan', str(ROOT / 'bin/ci-build-plan'))
spec = importlib.util.spec_from_loader(loader.name, loader)
plan = importlib.util.module_from_spec(spec)
loader.exec_module(plan)
sys.path.insert(0, str(ROOT / 'swamp/proof/runner'))
import selection


class ProofCaseSelectionTest(unittest.TestCase):
    def test_shared_fixtures(self):
        fixture = json.loads((ROOT / 'tools/fixtures/proof-case-selection.json').read_text())
        for test in fixture['tests']:
            with self.subTest(test['name']):
                chosen = selection.select(test['before'], test['after'], test.get('code_changed', False), test['before_batches'], test['after_batches'])
                self.assertEqual(chosen, {'only': list(test['expected']), 'reasons': test['expected']})
                file = 'swamp/proof/demo/run.cases.yaml'
                def read(ref, path):
                    return test[('before' if ref == 'base' else 'after') + ('_batches' if path.endswith('batches.yaml') else '')]
                paths = [file] + (['apps/demo/run.php'] if test.get('code_changed') else [])
                result = plan.proof_cases(paths, f'{file} demo:run\napps/demo/** demo:run', read, [file])
                self.assertEqual(result, [{'command': 'demo:run', 'file': file, **chosen}])

    def test_full_planner_keeps_case_ids_and_ignores_documentation(self):
        file = 'swamp/proof/demo/run.cases.yaml'
        fixture = json.loads((ROOT / 'tools/fixtures/proof-case-selection.json').read_text())['tests'][0]
        before, after = fixture['before'], fixture['after']
        selection = f'docs/** skip\napps/demo/** demo:run\n{file} demo:run\nswamp/proof/batches.yaml none: batch dependencies\n'
        def read(ref, path):
            return 'batches: {fresh: {}, other: {}}' if path.endswith('batches.yaml') else before if ref == 'base' else after
        result = plan.proof_cases([file], selection, read, [file])
        self.assertEqual(result, [{'command': 'demo:run', 'file': file, 'only': ['a'], 'reasons': {'a': 'changed'}}])
        self.assertEqual(plan.proof_cases(['docs/a.md'], selection, read, [file]), [])
        self.assertEqual(plan.proof_cases(['apps/demo/run.php', file], selection, read, [file])[0]['only'], ['a', 'b', 'c', 'v[x]', 'v[y]'])

    def test_malformed_or_unknown_definitions_select_the_whole_file(self):
        fixture = json.loads((ROOT / 'tools/fixtures/proof-case-selection.json').read_text())['tests'][0]
        for text in ('[', '{}', 'cases: []', fixture['after'] + '\nunknown: true\n'):
            with self.subTest(text=text):
                chosen = selection.select(fixture['before'], text, False, fixture['before_batches'], fixture['after_batches'])
                self.assertIsNone(chosen['only'])
        chosen = selection.select('[', fixture['after'], False, fixture['before_batches'], fixture['after_batches'])
        self.assertEqual(chosen['only'], ['a', 'b', 'c', 'v[x]', 'v[y]'])
        self.assertEqual(set(chosen['reasons'].values()), {'selection uncertain'})


if __name__ == '__main__':
    unittest.main()
