"""The required CLI Pest job runs the complete suite with its dev dependencies."""
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/ci.yml').read_text()
JOB = re.search(r'^  cli-tests:\n(.*?)(?=^  [\w-]+:|\Z)', SOURCE, re.M | re.S).group(1)


class CliTestsPolicyTest(unittest.TestCase):
    def test_complete_suite_runs_only_after_explicit_input_selection(self):
        self.assertIn('    needs: changes\n', JOB)
        self.assertIn("toJSON(fromJSON(needs.changes.outputs.jobs)['cli-tests']) != 'false'", JOB)
        self.assertNotIn('strategy:', JOB)
        self.assertNotIn('continue-on-error', JOB)
        self.assertNotIn('ORBIT_SLOW_CHECKS_BLOCKING', JOB)
        self.assertIn('ref: ${{ github.sha }}', JOB)
        self.assertIn('persist-credentials: false', JOB)
        self.assertIn('uses: ./.github/actions/php\n        with:\n          coverage: none', JOB)
        command = next(line.strip() for line in JOB.splitlines() if 'vendor/bin/pest ' in line)
        self.assertEqual(command, 'vendor/bin/pest --compact --colors=never --log-junit="$RUNNER_TEMP/cli-tests.xml"')
        self.assertIn('working-directory: apps/cli', JOB)

    def test_dev_cache_always_regenerates_the_autoloader_before_pest(self):
        key = next(line for line in JOB.splitlines() if 'key: cli-tests-dev-' in line)
        for value in ('runner.os', '8.5', 'apps/cli/composer.json', 'apps/cli/composer.lock',
                      'packages/php-sdk/composer.json', 'packages/php-sdk/composer.lock'):
            self.assertIn(value, key)
        self.assertIn('path: apps/cli/vendor', JOB)
        install = 'composer install --working-dir=apps/cli --no-interaction --prefer-dist --no-scripts'
        self.assertLess(JOB.index(install), JOB.index('vendor/bin/pest '))
        self.assertNotIn('--no-dev', JOB)
        self.assertNotIn('cache-hit', JOB)

    def test_php_children_inherit_memory_and_junit_upload_runs_on_failure(self):
        self.assertIn('echo \'memory_limit=1G\' > "$RUNNER_TEMP/cli-tests-php/memory.ini"', JOB)
        self.assertIn('PHP_INI_SCAN_DIR=${PHP_INI_SCAN_DIR:-}:$RUNNER_TEMP/cli-tests-php', JOB)
        self.assertIn('>> "$GITHUB_ENV"', JOB)
        self.assertIn('ini_get("memory_limit") !== "1G"', JOB)
        self.assertIn('if: always()\n        uses: actions/upload-artifact@', JOB)
        self.assertIn('name: cli-tests-junit', JOB)
        self.assertIn('path: ${{ runner.temp }}/cli-tests.xml', JOB)
        self.assertIn('if-no-files-found: error', JOB)


if __name__ == '__main__':
    unittest.main()
