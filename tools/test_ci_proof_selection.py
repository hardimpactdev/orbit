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
                chosen = selection.select(test['before'], test['after'], test.get('code_changed', False), test['before_batches'], test['after_batches'], test.get('changed_paths', []))
                self.assertEqual(chosen, {'only': list(test['expected']), 'reasons': test['expected']})
                file = 'swamp/proof/demo/run.cases.yaml'
                def read(ref, path):
                    return test[('before' if ref == 'base' else 'after') + ('_batches' if path.endswith('batches.yaml') else '')]
                paths = [file] + (['apps/demo/run.php'] if test.get('code_changed') else []) + test.get('changed_paths', [])
                result = plan.proof_cases(paths, f'** none: shared tooling\n{file} demo:run\napps/demo/** demo:run', read, [file])
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

    def test_tooling_changes_widen_selected_commands_but_select_nothing_alone(self):
        fixture = json.loads((ROOT / 'tools/fixtures/proof-case-selection.json').read_text())['tests'][0]
        file = 'swamp/proof/demo/run.cases.yaml'
        mapping = (ROOT / 'swamp/proof/selection.txt').read_text() + f'\n{file} demo:run\n'
        def read(ref, path):
            return fixture[('before' if ref == 'base' else 'after') + ('_batches' if path.endswith('batches.yaml') else '')]
        for path in (
            'swamp/proof/runner/spec.py', 'swamp/proof/runner/tests/test_spec.py',
            'swamp/proof/selection.txt', 'bin/ci-build-plan', 'bin/ci-proof-run', '.github/workflows/ci.yml',
            'apps/gateway/app/Domain/Proof/ProofSelection.php',
            'apps/gateway/app/Domain/Proof/ProofCaseSelection.php',
            'apps/gateway/app/Domain/Proof/ProofRunIntent.php',
            'apps/gateway/app/Infrastructure/Proof/GitHubProofCommitSource.php',
        ):
            with self.subTest(path=path):
                result = plan.proof_cases([file, path], mapping, read, [file])
                self.assertEqual(result[0]['only'], ['a', 'b', 'c', 'v[x]', 'v[y]'])
                self.assertEqual(set(result[0]['reasons'].values()), {'proof tooling changed'})
                self.assertEqual(plan.proof_cases([path], mapping, read, [file]), [])

    def test_unknown_fixture_keys_select_whole_files_even_when_unused(self):
        fixture = json.loads((ROOT / 'tools/fixtures/proof-case-selection.json').read_text())['tests'][0]
        before = fixture['before']
        for name in ('inner', 'unused'):
            malformed = before.replace(f'  {name}:\n    steps:', f'  {name}:\n    typo: true\n    steps:')
            with self.subTest(fixture=name):
                chosen = selection.select(before, malformed, False, fixture['before_batches'], fixture['after_batches'])
                self.assertIsNone(chosen['only'])
                chosen = selection.select(malformed, before, False, fixture['before_batches'], fixture['after_batches'])
                self.assertEqual(chosen['only'], ['a', 'b', 'c', 'v[x]', 'v[y]'])
                self.assertEqual(set(chosen['reasons'].values()), {'selection uncertain'})

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
