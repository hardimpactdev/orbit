"""The selected SDK job runs the whole Pest suite with dev dependencies and a bounded budget."""
import tomllib
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = yaml.safe_load((ROOT / '.github/workflows/ci.yml').read_text())


class SdkTestsPolicyTest(unittest.TestCase):
    def test_selected_sdk_suite_has_no_other_prerequisites_or_policy_switch(self):
        job = WORKFLOW['jobs']['sdk-tests']
        self.assertEqual(job['needs'], 'changes')
        self.assertEqual(job['if'], "${{ toJSON(fromJSON(needs.changes.outputs.jobs)['sdk-tests']) != 'false' }}")
        self.assertEqual(job['runs-on'], 'ubuntu-26.04')
        self.assertEqual(job['timeout-minutes'], 2)
        self.assertNotIn('strategy', job)
        self.assertNotIn('continue-on-error', job)
        self.assertNotIn('ORBIT_', str(job))
        for gate in ('required', 'delivery'):
            self.assertIn('sdk-tests', WORKFLOW['jobs'][gate]['needs'])
        with (ROOT / '.github/ci/checks.toml').open('rb') as source:
            check = tomllib.load(source)['check']['sdk-tests']
        self.assertEqual((check['cost'], check['ceiling']), (60, 120))

    def test_dev_dependencies_precede_the_complete_nonempty_stock_suite(self):
        steps = WORKFLOW['jobs']['sdk-tests']['steps']
        checkout = next(step for step in steps if step.get('uses', '').startswith('actions/checkout@'))
        self.assertEqual(checkout['with'], {'ref': '${{ github.sha }}', 'persist-credentials': False})
        php = next(step for step in steps if step.get('uses') == './.github/actions/php')
        self.assertEqual(php['with'], {'coverage': 'none'})
        shared = (ROOT / '.github/actions/php/action.yml').read_text()
        self.assertRegex(shared, r'uses: shivammathur/setup-php@[0-9a-f]{40}')
        install = next(step for step in steps if 'composer install ' in step.get('run', ''))
        self.assertEqual(install['run'], 'composer install --working-dir=packages/php-sdk --no-interaction --prefer-dist --no-scripts')
        pest = next(step for step in steps if 'vendor/bin/pest ' in step.get('run', ''))
        self.assertEqual(pest['working-directory'], 'packages/php-sdk')
        self.assertEqual(pest['run'], 'vendor/bin/pest --fail-on-empty-test-suite --compact --colors=never --log-junit="$RUNNER_TEMP/sdk-tests.xml"')
        self.assertLess(steps.index(install), steps.index(pest))
        self.assertNotIn('--no-dev', str(steps))
        self.assertNotIn('cache-hit', str(steps))
        for step in steps:
            self.assertNotIn('continue-on-error', step)

    def test_junit_is_required_even_after_a_failure_and_cache_is_sdk_dev_only(self):
        steps = WORKFLOW['jobs']['sdk-tests']['steps']
        artifact = next(step for step in steps if step.get('uses', '').startswith('actions/upload-artifact@'))
        self.assertEqual(artifact['if'], 'always()')
        self.assertEqual(artifact['with'], {'name': 'sdk-tests-junit', 'path': '${{ runner.temp }}/sdk-tests.xml',
                                            'if-no-files-found': 'error', 'retention-days': 7})
        cache = next(step for step in steps if step.get('uses', '').startswith('actions/cache@'))
        self.assertEqual(cache['with']['path'], 'packages/php-sdk/vendor')
        self.assertEqual(cache['with']['key'], "sdk-tests-dev-${{ runner.os }}-8.5-${{ hashFiles('packages/php-sdk/composer.json', 'packages/php-sdk/composer.lock') }}")
        for step in (artifact, cache):
            self.assertRegex(step['uses'], r'@[0-9a-f]{40}$')


if __name__ == '__main__':
    unittest.main()
