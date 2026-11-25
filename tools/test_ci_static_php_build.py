"""The static PHP build id names its inputs, a stale pin fails, the craft file gets every value it needs, and main's proof
accepts only glibc, shows the CLI-only extensions in the CLI only, and measures coverage."""
import copy
import importlib.machinery
import importlib.util
import json
import re
from pathlib import Path
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
loader = importlib.machinery.SourceFileLoader('static_php_build', str(ROOT / 'bin/static-php-build'))
spec = importlib.util.spec_from_loader(loader.name, loader)
build = importlib.util.module_from_spec(spec)
loader.exec_module(build)

prove_loader = importlib.machinery.SourceFileLoader('static_php_prove', str(ROOT / 'tools/static-php/prove.py'))
prove = importlib.util.module_from_spec(importlib.util.spec_from_loader(prove_loader.name, prove_loader))
prove_loader.exec_module(prove)

WORKFLOW = (ROOT / '.github/workflows/static-php.yml').read_text()
RECIPE = json.loads((ROOT / 'apps/gateway/resources/machine-software/core.yaml').read_text())


class StaticPhpBuildTest(unittest.TestCase):
    def changed(self, change):
        recipe = copy.deepcopy(RECIPE)
        change(recipe)
        return build.definitions(recipe)['8.5']

    def test_slow_compilation_stays_off_pull_requests_but_pin_validation_stays_automatic(self):
        definition, compilation = WORKFLOW.split('  build:', 1)
        self.assertIn('run: python3 bin/static-php-build verify-pins', definition)
        self.assertIn("if: github.event_name != 'pull_request' && needs.definition.outputs.missing != '[]'", compilation)

    def test_the_shipped_pin_names_its_definition(self):
        item = build.definitions(RECIPE)['8.5']
        self.assertEqual(item['recorded'], {'build': item['build'], 'url': item['url']})
        self.assertEqual(item['tag'], f'static-php-{item["php_version"]}-{item["build"][:12]}')
        self.assertEqual(item['url'], f'https://github.com/hardimpactdev/orbit/releases/download/{item["tag"]}/{item["tag"]}-linux-x86_64.tar.gz')
        self.assertNotIn('ffi', item['extensions'])
        self.assertIn('pdo_pgsql', item['extensions'])
        # PCOV is a CLI-only extension: the build makes it shared, so it is no static extension of either binary.
        self.assertEqual(item['cli_extensions'], ['pcov'])
        self.assertNotIn('pcov', item['extensions'])

    def test_every_input_changes_the_build_id(self):
        shipped = build.definitions(RECIPE)['8.5']['build']
        changes = [
            lambda r: r['instances']['php']['versions']['8.5'].update(php_version='8.5.14'),
            lambda r: r['instances']['php'].update(frankenphp='1.13.6'),
            lambda r: r['instances']['php'].update(static_php_cli='0' * 40),
            lambda r: r['instances']['php'].update(without=['ffi', 'xsl']),
            lambda r: r['instances']['php'].update(cli_extensions=['pcov', 'xsl']),
            lambda r: r['php']['versions']['8.5']['extensions'].remove('zip'),
        ]
        ids = {self.changed(change)['build'] for change in changes}
        self.assertEqual(len(ids), len(changes))
        self.assertNotIn(shipped, ids)
        # The pin itself is not an input: recording the published SHA-256 keeps the build.
        self.assertEqual(self.changed(lambda r: r['instances']['php']['versions']['8.5'].update(sha256='a' * 64))['build'], shipped)

    def test_a_stale_pin_fails(self):
        stale = copy.deepcopy(RECIPE)
        stale['instances']['php']['frankenphp'] = '1.13.6'
        original = build.definitions
        build.definitions = lambda recipe=None: original(stale)
        try:
            with self.assertRaisesRegex(build.BuildError, r'(?s)instances\.php\.versions\.8\.5\.build is .*instances\.php\.versions\.8\.5\.url is'):
                build.verify_pins()
        finally:
            build.definitions = original

    def test_extension_names_follow_static_php_cli(self):
        self.assertEqual(build.spc_names(['core', 'date', 'pdo_pgsql', 'standard', 'uri', 'zend opcache']), ['pdo_pgsql', 'opcache'])

    def test_the_craft_file_gets_every_value(self):
        item = build.definitions(RECIPE)['8.5']
        craft = build.craft(item, Path('/tmp/frankenphp'))
        self.assertIn(f'php-version: "{item["php_version"]}"', craft)
        self.assertIn(f'php-src:https://www.php.net/distributions/php-{item["php_version"]}.tar.xz', craft)
        self.assertIn('frankenphp:/tmp/frankenphp', craft)
        self.assertIn('sapi: "cli,frankenphp"', craft)
        self.assertNotRegex(craft, r'@[A-Z_]+@')

    def test_the_craft_file_builds_the_cli_extensions_shared_and_never_static(self):
        item = build.definitions(RECIPE)['8.5']
        # The top-level `key: "value"` lines of the craft file. The test needs only the standard library, so the check's Python runs it.
        craft = dict(re.findall(r'^([a-z-]+): "([^"]*)"$', build.craft(item, Path('/tmp/frankenphp')), re.MULTILINE))
        self.assertEqual(craft['shared-extensions'], 'pcov')
        self.assertNotIn('pcov', craft['extensions'].split(','))
        self.assertNotIn('ffi', craft['extensions'].split(','))

    def test_the_build_links_glibc_dynamically_for_the_baseline_cpu(self):
        env = build.build_env({'PATH': '/usr/bin', 'SPC_TARGET': 'x86_64-linux-musl', 'SPC_TOOLCHAIN': 'GccNativeToolchain'})
        # static-php-cli's Zig toolchain, with a glibc no newer than Ubuntu 26.04's and a named CPU, not the runner's.
        self.assertEqual(env, {'PATH': '/usr/bin', 'SPC_TARGET': 'x86_64-linux-gnu.2.17', 'SPC_TOOLCHAIN': ''})

    def test_the_release_checks_its_sums_beside_the_files(self):
        # SHA256SUMS names each file without a folder, so each check of it runs in the folder that holds the files.
        publish = WORKFLOW[WORKFLOW.index('- name: Publish the release'):]
        self.assertIn('working-directory: release', publish)
        self.assertNotIn('release/', publish)
        self.assertEqual(set(re.findall(r'sha256sum --check --strict \S+', WORKFLOW)), {'sha256sum --check --strict SHA256SUMS'})

    def test_a_manual_run_builds_and_publishes_one_resolved_commit_of_its_ref(self):
        # A pull request records its SHA-256 before it merges: main's workflow builds the given ref and publishes that commit.
        self.assertIn("ref: ${{ inputs.ref || github.sha }}", WORKFLOW)
        self.assertIn('echo "sha=$(git rev-parse HEAD)" >> "$GITHUB_OUTPUT"', WORKFLOW)
        self.assertEqual(WORKFLOW.count('ref: ${{ needs.definition.outputs.sha }}'), 2)
        publish = WORKFLOW[WORKFLOW.index('  publish:'):]
        self.assertIn("github.ref == 'refs/heads/main'", publish)
        self.assertIn('--target "$SOURCE"', publish)
        self.assertNotIn('GITHUB_SHA', publish)
        self.assertIn('Published builds are never replaced.', publish)
        self.assertIn('group: static-php-${{ github.ref }}-${{ inputs.ref }}', WORKFLOW)
        save = WORKFLOW[WORKFLOW.index('- name: Keep the proven build under its build id'):]
        self.assertIn("if: inputs.ref == ''", save.split('uses:', 1)[0])


def readelf(interpreter=None, needed=('libc.so.6',), glibc=('2.2.5', '2.17')):
    """What `readelf --wide --program-headers --dynamic --version-info` prints for one x86_64 file."""
    lines = ['Program Headers:']
    if interpreter:
        lines += ['  INTERP         0x000318 0x0000000000000318 0x0000000000000318 0x00001c 0x00001c R   0x1',
                  f'      [Requesting program interpreter: {interpreter}]']
    lines += ['  LOAD           0x000000 0x0000000000000000 0x0000000000000000 0x0a0e48 0x0a0e48 R   0x1000']
    if needed:
        lines += ['', f"Dynamic section at offset 0x2d7ab50 contains {len(needed) + 1} entries:", '  Tag        Type                         Name/Value']
        lines += [f' 0x0000000000000001 (NEEDED)             Shared library: [{library}]' for library in needed]
        lines += [' 0x000000000000001e (FLAGS)              BIND_NOW']
        lines += ['', "Version needs section '.gnu.version_r' contains 1 entry:", ' Addr: 0x0000000000000a48  Offset: 0x00000a48  Link: 7 (.dynstr)',
                  f'  000000: Version: 1  File: libc.so.6  Cnt: {len(glibc)}']
        lines += [f'  0x{16 * (index + 1):04x}:   Name: GLIBC_{version}  Flags: none  Version: {index + 2}' for index, version in enumerate(glibc)]
        lines += ['  0x0040:   Name: GLIBC_PRIVATE  Flags: none  Version: 9']
    else:
        lines += ['', 'There is no dynamic section in this file.']
    return '\n'.join(lines) + '\n'


class StaticPhpProofTest(unittest.TestCase):
    def test_a_binary_that_needs_only_glibc_passes(self):
        glibc = readelf('/lib64/ld-linux-x86-64.so.2', ['libc.so.6', 'libm.so.6', 'libpthread.so.0', 'libdl.so.2', 'librt.so.1',
                                                        'libresolv.so.2', 'libutil.so.1', 'ld-linux-x86-64.so.2'], ('2.2.5', '2.17', '2.43'))
        self.assertEqual(prove.linkage_problems('bin/php', glibc), [])
        # A CLI-only extension is a shared object: it needs libc but asks for no interpreter.
        self.assertEqual(prove.linkage_problems('modules/pcov.so', readelf(None, ['libc.so.6'])), [])

    def test_a_fully_static_binary_passes(self):
        self.assertEqual(prove.linkage_problems('bin/php', readelf(None, ())), [])

    def test_a_shared_library_other_than_glibc_fails(self):
        for library in ('libstdc++.so.6', 'libz.so.1'):
            with self.subTest(library=library):
                found = prove.linkage_problems('bin/php', readelf('/lib64/ld-linux-x86-64.so.2', ['libc.so.6', library]))
                self.assertEqual(found, [f'bin/php needs {library}; only glibc may stay shared.'])

    def test_another_program_interpreter_fails(self):
        found = prove.linkage_problems('bin/php', readelf('/lib/ld-musl-x86_64.so.1', ['libc.so.6']))
        self.assertEqual(found, ['bin/php asks for the program interpreter /lib/ld-musl-x86_64.so.1, not /lib64/ld-linux-x86-64.so.2.'])

    def test_a_glibc_newer_than_ubuntu_26_04_fails(self):
        found = prove.linkage_problems('bin/frankenphp', readelf('/lib64/ld-linux-x86-64.so.2', ['libc.so.6'], ('2.17', '2.44')))
        self.assertEqual(found, ["bin/frankenphp needs GLIBC_2.44, newer than Ubuntu 26.04's glibc 2.43."])
        # Versions compare as numbers, not text: 2.5 is older than 2.43.
        self.assertEqual(prove.linkage_problems('bin/php', readelf('/lib64/ld-linux-x86-64.so.2', ['libc.so.6'], ('2.5', '2.43'))), [])

    def test_the_cli_ini_loads_each_cli_extension_from_modules(self):
        self.assertEqual(prove.cli_ini(Path('/build/modules'), ['pcov']), 'extension=/build/modules/pcov.so\n')

    def test_the_cli_must_list_each_cli_extension(self):
        self.assertEqual(prove.cli_problems('[PHP Modules]\nCore\npcov\nzip\n', ['pcov']), [])
        self.assertEqual(prove.cli_problems('[PHP Modules]\nCore\nzip\n', ['pcov']), ['php -m with php-cli.ini lacks pcov.'])

    def test_the_served_probe_must_run_under_frankenphp_without_cli_extensions(self):
        self.assertEqual(prove.served_problems({'sapi': 'frankenphp', 'loaded': []}), [])
        self.assertEqual(prove.served_problems({'sapi': 'frankenphp', 'loaded': ['pcov']}),
                         ['frankenphp php-server loads pcov; only the PHP CLI may.'])
        self.assertEqual(prove.served_problems({'sapi': 'cli', 'loaded': []}), ['The served probe ran under the SAPI cli, not frankenphp.'])

    def test_the_served_probe_asks_for_each_cli_extension(self):
        self.assertIn("array_filter([\"pcov\"], 'extension_loaded')", prove.SERVED_PROBE % json.dumps(['pcov']))

    def test_the_proof_reads_the_cli_extensions_from_the_archive_and_gives_both_binaries_the_same_config(self):
        with tempfile.TemporaryDirectory() as scratch:
            folder, config = Path(scratch) / 'bin', Path(scratch) / 'php-config'
            folder.mkdir()
            (Path(scratch) / 'build.json').write_text(json.dumps({'cli_extensions': ['pcov']}))
            calls = []

            def run(argv, **kwargs):
                calls.append(argv)
                self.assertEqual(kwargs['env']['PHPRC'], str(config))
                return mock.Mock(stdout='Core\npcov\n')

            def serve(frankenphp, env, cli_extensions):
                calls.append('serve')
                self.assertEqual((env['PHPRC'], cli_extensions), (str(config), ['pcov']))
                return {'sapi': 'frankenphp', 'loaded': []}

            with mock.patch.object(prove, 'readelf', return_value=readelf('/lib64/ld-linux-x86-64.so.2')) as elf, \
                    mock.patch.object(prove.subprocess, 'run', run), mock.patch.object(prove, 'serve', serve):
                prove.prove(folder, config)
            self.assertEqual([call.args[0].name for call in elf.call_args_list], ['php', 'frankenphp', 'pcov.so'])
            self.assertEqual(calls, [[str(folder / 'php'), '-m'], 'serve'])
            self.assertEqual((config / 'php-cli.ini').read_text(), f'extension={Path(scratch) / "modules/pcov.so"}\n')

    def test_the_proof_refuses_before_any_binary_runs_when_a_file_needs_more_than_glibc(self):
        with tempfile.TemporaryDirectory() as scratch, \
                mock.patch.object(prove, 'readelf', return_value=readelf('/lib64/ld-linux-x86-64.so.2', ['libc.so.6', 'libz.so.1'])), \
                mock.patch.object(prove.subprocess, 'run') as run:
            (Path(scratch) / 'bin').mkdir()
            (Path(scratch) / 'build.json').write_text(json.dumps({'cli_extensions': ['pcov']}))
            with self.assertRaisesRegex(prove.ProofError, r'(?s)bin/php needs libz\.so\.1.*modules/pcov\.so needs libz\.so\.1'):
                prove.prove(Path(scratch) / 'bin', Path(scratch) / 'php-config')
            run.assert_not_called()

    def test_an_archive_without_cli_extensions_fails_with_a_clear_reason(self):
        with tempfile.TemporaryDirectory() as scratch:
            (Path(scratch) / 'bin').mkdir()
            (Path(scratch) / 'build.json').write_text(json.dumps({'extensions': ['zip']}))
            with self.assertRaisesRegex(prove.ProofError, 'build.json names no cli_extensions list'):
                prove.prove(Path(scratch) / 'bin', Path(scratch) / 'php-config')

    def test_coverage_must_cover_lines_of_the_source(self):
        clover = ('<?xml version="1.0"?><coverage><project><package name="App"><file name="/w/apps/gateway/app/X/StaticPhpBuild.php">'
                  '<line num="10" type="stmt" count="{count}"/><metrics loc="40" statements="12" coveredstatements="{count}"/>'
                  '</file></package><metrics files="1"/></project></coverage>')
        self.assertEqual(prove.coverage_problems(clover.format(count=7), 'app/X/StaticPhpBuild.php'), [])
        self.assertEqual(prove.coverage_problems(clover.format(count=0), 'app/X/StaticPhpBuild.php'),
                         ['The clover report covers no line of app/X/StaticPhpBuild.php.'])
        self.assertEqual(prove.coverage_problems(clover.format(count=7), 'app/X/RecipeLoader.php'),
                         ['The clover report has no file app/X/RecipeLoader.php.'])

    def test_coverage_without_a_report_fails(self):
        with tempfile.TemporaryDirectory() as scratch, self.assertRaisesRegex(prove.ProofError, 'no coverage driver'):
            prove.coverage(Path(scratch) / 'coverage.xml', 'app/X/StaticPhpBuild.php')


class StaticPhpProofWorkflowTest(unittest.TestCase):
    def setUp(self):
        self.prove = WORKFLOW[WORKFLOW.index('\n  prove:\n'):WORKFLOW.index('\n  publish:\n')]

    def test_the_prove_job_runs_mains_proof_after_the_check_instead_of_refusing_every_interpreter(self):
        self.assertNotIn('INTERP', self.prove)
        # The proof is the run's own commit, so a manual run of a branch cannot change its own proof.
        checkout = self.prove[self.prove.index('- name: Check out the proof'):self.prove.index('- name: Download the build')]
        self.assertIn('ref: ${{ github.sha }}', checkout)
        self.assertIn('path: .proof', checkout)
        self.assertIn('python3 .proof/tools/static-php/prove.py build --bin "$STATIC" --config "$RUNNER_TEMP/php-config"', self.prove)
        self.assertIn('python3 ../../.proof/tools/static-php/prove.py coverage', self.prove)
        self.assertNotIn('python3 tools/static-php/prove.py', self.prove)
        # The check runs before php-cli.ini exists; the proof runs before any other step runs the binaries.
        self.assertLess(self.prove.index('bin/static-php-build check'), self.prove.index('prove.py build'))
        self.assertLess(self.prove.index('prove.py build'), self.prove.index('"$STATIC/php" -v'))

    def test_pest_runs_once_with_the_cli_ini_and_must_measure_coverage(self):
        runs = re.findall(r'"\$STATIC/php"[^\n]*vendor/bin/pest(?! --version)[^\n]*', self.prove)
        self.assertEqual(runs, ['"$STATIC/php" -c "$RUNNER_TEMP/php-config" -d memory_limit=1G vendor/bin/pest \\'])
        self.assertIn('--coverage-clover "$RUNNER_TEMP/coverage.xml" tests/Unit/Infrastructure/MachineSoftware', self.prove)
        self.assertIn('prove.py coverage --clover "$RUNNER_TEMP/coverage.xml"', self.prove)
        self.assertIn('--source app/Infrastructure/MachineSoftware/StaticPhpBuild.php', self.prove)
        self.assertTrue((ROOT / 'apps/gateway/app/Infrastructure/MachineSoftware/StaticPhpBuild.php').is_file())

    def test_the_served_gateway_still_answers_up(self):
        self.assertIn('"$STATIC/frankenphp" php-server --root public --listen 127.0.0.1:8088', self.prove)
        self.assertIn('[ "$status" = 200 ]', self.prove)


if __name__ == '__main__':
    unittest.main()
