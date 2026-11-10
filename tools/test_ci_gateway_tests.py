"""Gateway Pest setup must execute the real cross-project and native prerequisites."""
import hashlib
import json
import os
from pathlib import Path
import tempfile
import textwrap
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SOURCE = (ROOT / '.github/workflows/ci.yml').read_text()
JOB = SOURCE.split('  gateway-tests:\n', 1)[1].split('\n  cli:\n', 1)[0]


class GatewayTestsPolicyTest(unittest.TestCase):
    def test_one_hosted_job_runs_the_whole_suite_after_selection(self):
        self.assertIn('    needs: changes\n', JOB)
        self.assertIn("toJSON(fromJSON(needs.changes.outputs.jobs)['gateway-tests']) != 'false'", JOB)
        self.assertIn('    runs-on: ubuntu-26.04\n', JOB)
        self.assertNotIn('strategy:', JOB)
        self.assertNotIn('continue-on-error', JOB)
        self.assertNotIn('ORBIT_SLOW_CHECKS_BLOCKING', JOB)
        command = next(line.strip() for line in JOB.splitlines() if 'vendor/bin/pest ' in line)
        self.assertEqual(command, 'vendor/bin/pest --parallel --processes=4 --default-time-limit=5 --fail-on-skipped --compact --colors=never --log-junit="$RUNNER_TEMP/gateway-tests.xml"')
        self.assertIn('if: always()\n        uses: actions/upload-artifact@', JOB)
        self.assertIn('name: gateway-tests-junit', JOB)
        self.assertIn('if-no-files-found: error', JOB)

    def test_dev_dependencies_have_separate_input_keyed_caches_and_always_install(self):
        for project in ('gateway', 'cli'):
            with self.subTest(project=project):
                key = next(line for line in JOB.splitlines() if f'key: {project}-tests-dev-' in line)
                for value in ('runner.os', '8.5', f'apps/{project}/composer.json',
                              f'apps/{project}/composer.lock', 'packages/php-sdk/composer.json'):
                    self.assertIn(value, key)
                self.assertIn(f'path: apps/{project}/vendor', JOB)
                self.assertIn(f'cp apps/{project}/.env.example apps/{project}/.env', JOB)
                self.assertIn(f'composer install --working-dir=apps/{project} --no-interaction --prefer-dist --no-scripts', JOB)
        self.assertNotIn('--no-dev', JOB)
        self.assertNotIn('php-runtime-', JOB)
        # A cache hit must still regenerate both autoloaders in this checkout.
        self.assertNotIn('cache-hit', JOB)

    def test_worker_memory_is_inherited_and_firewall_imports_are_warm_before_pest(self):
        self.assertIn('echo \'memory_limit=1G\' > "$RUNNER_TEMP/gateway-tests-php/memory.ini"', JOB)
        self.assertIn('PHP_INI_SCAN_DIR=${PHP_INI_SCAN_DIR:-}:$RUNNER_TEMP/gateway-tests-php', JOB)
        self.assertIn('>> "$GITHUB_ENV"', JOB)
        self.assertIn('ini_get("memory_limit") !== "1G"', JOB)
        self.assertIn('deno-version: v2.9.8', JOB)
        self.assertIn('path: ${{ env.DENO_DIR }}', JOB)
        warm = 'deno cache --no-lock extensions/behaviors/operations/firewall/firewall_test.ts'
        self.assertLess(JOB.index(warm), JOB.index('vendor/bin/pest '))
        # Keep the CLI fixture and default suite present; the job must not replace them with mocks.
        self.assertTrue((ROOT / 'apps/cli/tests/Fixtures/Console/command-consent.php').is_file())

    def caddy_setup(self, *, cached=False, corrupt=False, version='v2.11.7', missing=False):
        code = textwrap.dedent(JOB.split("          python3 - <<'PYTHON'\n", 1)[1].split('          PYTHON', 1)[0])
        content = b'pinned-caddy-archive'
        recipe = {'tools': {'caddy': {'version': '2.11.7', 'artifacts': {'amd64': {
            'url': 'https://example.test/caddy.deb', 'sha512': hashlib.sha512(content).hexdigest()}}}}}
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            archive = root / '.cache/orbit-gateway-tests/caddy/caddy_2.11.7_linux_amd64.deb'
            if cached:
                archive.parent.mkdir(parents=True)
                archive.write_bytes(b'corrupt' if corrupt else content)
            output = root / 'github-path'
            def download(url, path):
                self.assertEqual(url, recipe['tools']['caddy']['artifacts']['amd64']['url'])
                Path(path).write_bytes(content)
            with patch.object(Path, 'home', return_value=root), \
                    patch.object(Path, 'read_text', return_value=json.dumps(recipe)), \
                    patch.dict(os.environ, RUNNER_TEMP=str(root / 'tmp'), GITHUB_PATH=str(output)), \
                    patch('urllib.request.urlretrieve', side_effect=download) as fetch, \
                    patch('subprocess.run') as extract, \
                    patch('subprocess.check_output', return_value=version + ' build\n',
                          side_effect=FileNotFoundError('missing Caddy') if missing else None):
                exec(code, {})
                self.assertEqual(fetch.call_count, 0 if cached else 1)
                extract.assert_called_once_with(['dpkg-deb', '-x', str(archive), str(root / 'tmp/gateway-tests-caddy')], check=True)
            self.assertEqual(output.read_text(), str(root / 'tmp/gateway-tests-caddy/usr/bin') + '\n')

    def test_caddy_is_verified_on_a_cold_download_and_a_cache_hit(self):
        self.caddy_setup()
        self.caddy_setup(cached=True)

    def test_corrupt_wrong_version_and_missing_caddy_fail_setup(self):
        for options, error, message in (({'cached': True, 'corrupt': True}, SystemExit, 'checksum'),
                                        ({'version': 'v2.10.0'}, SystemExit, 'version'),
                                        ({'missing': True}, FileNotFoundError, 'missing Caddy')):
            with self.subTest(options=options), self.assertRaisesRegex(error, message):
                self.caddy_setup(**options)

    def test_caddy_pin_matches_the_recipe(self):
        recipe = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())
        caddy = recipe['tools']['caddy']
        self.assertEqual(caddy['version'], '2.11.7')
        self.assertRegex(caddy['artifacts']['amd64']['sha512'], r'^[0-9a-f]{128}$')
        self.assertIn('hashlib.sha512(archive.read_bytes()).hexdigest()', JOB)
        self.assertIn("artifact['sha512']", JOB)


if __name__ == '__main__':
    unittest.main()
