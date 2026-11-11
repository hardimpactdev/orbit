"""Docs contracts run in the selected build, with real generated inputs and dev tools."""
import json
from pathlib import Path
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[1]
JOB = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())['jobs']['docs']


class DocsTestsPolicyTest(unittest.TestCase):
    def test_docs_job_invokes_the_complete_stock_pest_suite_after_building_inputs(self):
        self.assertEqual('changes', JOB['needs'])
        self.assertIn("toJSON(fromJSON(needs.changes.outputs.jobs)['docs']) != 'false'", JOB['if'])
        self.assertEqual('ubuntu-26.04', JOB['runs-on'])
        self.assertEqual(2, JOB['timeout-minutes'])
        self.assertNotIn('continue-on-error', JOB)
        steps = JOB['steps']
        install = next(i for i, step in enumerate(steps) if step.get('name') == 'Install Docs dependencies')
        self.assertEqual('composer install --working-dir=apps/docs --no-interaction --prefer-dist', steps[install]['run'])
        prepare = next(i for i, step in enumerate(steps) if step.get('name') == 'Prepare generated contracts for Docs tests')
        for command in ('composer install --working-dir=apps/gateway --no-interaction --prefer-dist --no-dev --no-scripts',
                        'bun install --cwd apps/web --frozen-lockfile --filter @orbit/api-types',
                        'bin/build-api', 'bun run --cwd apps/web types'):
            self.assertIn(command, steps[prepare]['run'].splitlines())
        build = next(i for i, step in enumerate(steps) if step.get('name') == 'Build and check the documentation index')
        test = next(i for i, step in enumerate(steps) if step.get('working-directory') == 'apps/docs')
        self.assertLess(install, prepare)
        self.assertLess(prepare, build)
        self.assertLess(build, test)
        self.assertEqual('composer test -- --fail-on-empty-test-suite', steps[test]['run'])
        self.assertEqual({'ORBIT_CHECK_PROCESSES': '2'}, steps[test]['env'])
        self.assertNotIn('if', steps[test])
        self.assertNotIn('continue-on-error', steps[test])
        script = json.loads((ROOT / 'apps/docs/composer.json').read_text())['scripts']['test']
        self.assertIn('vendor/bin/pest --parallel', script)
        for selection in ('--filter', '--group', '--exclude-group', '--testsuite'):
            self.assertNotIn(selection, script)
        self.assertIn('bin/check-flows', steps[-1]['run'])

    def test_python_contract_wrapper_discovers_all_files_and_rejects_zero_tests(self):
        source = (ROOT / 'apps/docs/tests/Feature/OpenApiExtensionsTest.php').read_text()
        self.assertIn("'python3', '-m', 'unittest', 'discover'", source)
        self.assertIn("'-p', '*_test.py'", source)
        self.assertIn('->toBe(0,', source)
        self.assertIn("->toMatch('/Ran [1-9][0-9]* tests?/')", source)


if __name__ == '__main__':
    unittest.main()
