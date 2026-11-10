"""Gateway Pest setup must execute the real cross-project and native prerequisites."""
import hashlib
import io
import json
import os
from pathlib import Path
import tempfile
import textwrap
import unittest
from unittest.mock import patch
import zipfile

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
                self.assertIn(f'composer install --working-dir=apps/{project} --no-interaction --prefer-dist --no-scripts', JOB)
        self.assertIn('cp apps/gateway/.env.example apps/gateway/.env', JOB)
        self.assertTrue((ROOT / 'apps/gateway/.env.example').is_file())
        self.assertNotIn('cp apps/cli/.env.example', JOB)
        self.assertNotIn('--no-dev', JOB)
        self.assertNotIn('php-runtime-', JOB)
        # A cache hit must still regenerate both autoloaders in this checkout.
        self.assertNotIn('cache-hit', JOB)

    def test_api_contracts_are_built_from_current_sources_before_pest(self):
        build = JOB.index('run: bin/build-api')
        for project in ('gateway', 'cli'):
            self.assertLess(JOB.index(f'composer install --working-dir=apps/{project} '), build)
        self.assertLess(build, JOB.index('vendor/bin/pest '))

    def test_worker_memory_is_inherited_and_firewall_imports_are_warm_before_pest(self):
        self.assertIn('echo \'memory_limit=1G\' > "$RUNNER_TEMP/gateway-tests-php/memory.ini"', JOB)
        self.assertIn('PHP_INI_SCAN_DIR=${PHP_INI_SCAN_DIR:-}:$RUNNER_TEMP/gateway-tests-php', JOB)
        self.assertIn('>> "$GITHUB_ENV"', JOB)
        self.assertIn('ini_get("memory_limit") !== "1G"', JOB)
        self.assertNotIn('denoland/setup-deno@', JOB)
        self.assertIn('echo "DENO_DIR=$RUNNER_TEMP/gateway-tests-deno" >> "$GITHUB_ENV"', JOB)
        self.assertIn('path: ${{ env.DENO_DIR }}', JOB)
        warm = 'deno cache --no-lock extensions/behaviors/operations/firewall/firewall_test.ts'
        self.assertLess(JOB.index(warm), JOB.index('vendor/bin/pest '))
        # Keep the CLI fixture and default suite present; the job must not replace them with mocks.
        self.assertTrue((ROOT / 'apps/cli/tests/Fixtures/Console/command-consent.php').is_file())

    def recipe_setup_code(self, tool):
        step = JOB.split(f'      - name: Verify and extract the recipe {tool}\n', 1)[1]
        return textwrap.dedent(step.split("          python3 - <<'PYTHON'\n", 1)[1].split('          PYTHON', 1)[0])

    def deno_setup(self, *, cached=False, corrupt=False, version='deno 2.9.8 (stable, release, x86_64-unknown-linux-gnu)'):
        fixture = io.BytesIO()
        with zipfile.ZipFile(fixture, 'w') as release:
            release.writestr('deno', b'fixture-deno-binary')
        content = fixture.getvalue()
        recipe = {'tools': {'deno': {'version': '2.9.8', 'artifacts': {'amd64': {
            'url': 'https://example.test/deno.zip', 'sha256': hashlib.sha256(content).hexdigest()}}}}}
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            archive = root / '.cache/orbit-gateway-tests/deno/deno_2.9.8_linux_amd64.zip'
            downloaded = b'corrupt' if corrupt else content
            if cached:
                archive.parent.mkdir(parents=True)
                archive.write_bytes(downloaded)
            output = root / 'github-path'
            destination = root / 'tmp/gateway-tests-deno-bin'
            def download(url, path):
                self.assertEqual(url, recipe['tools']['deno']['artifacts']['amd64']['url'])
                Path(path).write_bytes(downloaded)
            with patch.object(Path, 'home', return_value=root), \
                    patch.object(Path, 'read_text', return_value=json.dumps(recipe)), \
                    patch.dict(os.environ, RUNNER_TEMP=str(root / 'tmp'), GITHUB_PATH=str(output)), \
                    patch('urllib.request.urlretrieve', side_effect=download) as fetch, \
                    patch('zipfile.ZipFile', wraps=zipfile.ZipFile) as extract, \
                    patch('subprocess.check_output', return_value=version + '\nv8 fixture\n') as check:
                try:
                    exec(self.recipe_setup_code('Deno'), {})
                finally:
                    if corrupt:
                        extract.assert_not_called()
                        check.assert_not_called()
                        self.assertFalse(destination.exists())
                        self.assertFalse(output.exists())
                self.assertEqual(fetch.call_count, 0 if cached else 1)
                check.assert_called_once_with([str(destination / 'deno'), '--version'], text=True)
            binary = destination / 'deno'
            self.assertEqual(binary.read_bytes(), b'fixture-deno-binary')
            self.assertEqual(binary.stat().st_mode & 0o777, 0o755)
            self.assertEqual(output.read_text(), str(destination) + '\n')

    def test_deno_is_verified_on_a_cold_download_and_a_cache_hit(self):
        self.deno_setup()
        self.deno_setup(cached=True)

    def test_corrupt_deno_download_or_cache_fails_before_extraction(self):
        for cached in (False, True):
            with self.subTest(cached=cached), self.assertRaisesRegex(SystemExit, 'Deno checksum mismatch'):
                self.deno_setup(cached=cached, corrupt=True)

    def test_wrong_deno_version_fails_setup(self):
        with self.assertRaisesRegex(SystemExit, 'Deno version mismatch'):
            self.deno_setup(version='deno 2.9.7')

    def test_deno_pin_uses_the_recipe_digest_and_separate_archive_cache(self):
        recipe = json.loads((ROOT / 'apps/gateway/resources/machine-software/recipe.json').read_text())
        deno = recipe['tools']['deno']
        self.assertEqual(deno['version'], '2.9.8')
        self.assertRegex(deno['artifacts']['amd64']['sha256'], r'^[0-9a-f]{64}$')
        code = self.recipe_setup_code('Deno')
        self.assertIn("urlretrieve(artifact['url'], archive)", code)
        self.assertIn("hashlib.sha256(archive.read_bytes()).hexdigest() != artifact['sha256']", code)
        self.assertNotIn(deno['artifacts']['amd64']['sha256'], JOB)
        key = next(line for line in JOB.splitlines() if 'key: gateway-tests-deno-download-' in line)
        self.assertIn("hashFiles('apps/gateway/resources/machine-software/recipe.json')", key)
        self.assertIn('path: ~/.cache/orbit-gateway-tests/deno', JOB)

    def caddy_setup(self, *, cached=False, corrupt=False, version='v2.11.7', missing=False):
        code = self.recipe_setup_code('Caddy')
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
