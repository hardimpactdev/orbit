"""Run the shipped queries with manager subprocesses mocked, never a live Node."""
import contextlib
import io
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

QUERIES = Path(__file__).with_name('queries')


class QueriesTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory(dir=os.environ.get('TMPDIR'))
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.calls = []
        self.account = SimpleNamespace(pw_dir='/home/orbit', pw_name='orbit', pw_uid=os.geteuid())

    def path(self, value):
        return self.root / str(value).lstrip('/')

    def run_query(self, manager, outputs, platform='linux', family='queries', extra=None):
        input = {'user': 'orbit', 'package': 'hello', 'manager': manager, 'platform': platform, **(extra or {})}
        source = (QUERIES / 'support.py').read_text().replace('QUERY_INPUT = None', 'QUERY_INPUT = ' + repr(json.dumps(input)))
        namespace = {'__name__': '__main__'}
        with patch('pwd.getpwnam', return_value=self.account):
            exec(compile(source, str(QUERIES / 'support.py'), 'exec'), namespace)  # noqa: S102 -- run shipped fixture source
        namespace['Path'] = self.path
        def run(argv, **kwargs):
            self.calls.append((argv, kwargs))
            value = outputs.pop(0)
            code, out, err = value if isinstance(value, tuple) else (0, json.dumps(value) if not isinstance(value, str) else value, '')
            if kwargs['check'] and code:
                raise subprocess.CalledProcessError(code, argv)
            return subprocess.CompletedProcess(argv, code, out, err)
        sources = ([QUERIES / 'brew_query.py'] if manager.startswith('brew') else []) + [QUERIES / (manager.replace('-', '_') + '.py')]
        if family != 'queries':
            sources = [QUERIES.parent / family / (('brew' if manager.startswith('brew') else manager) + '.py')]
        output = io.StringIO()
        with patch('subprocess.run', side_effect=run), contextlib.redirect_stdout(output):
            for file in sources:
                text = '\n'.join(line for line in file.read_text().splitlines() if not line.startswith(('from support import', 'from brew_query import')))
                exec(compile(text, str(file), 'exec'), namespace)  # noqa: S102 -- run shipped fixture source
        self.assertEqual(outputs, [])
        return json.loads(output.getvalue())

    def test_apt_installed_absent_and_partial_status_are_distinct(self):
        for row, versions in [('hello\tinstall ok installed\t1.2.3\n', ['1.2.3']), ('hello\tdeinstall ok config-files\t1.2.3\n', []), ('hello\tinstall reinstreq half-installed\t1.2.3\n', [None])]:
            value = self.run_query('apt', ['apt 3.0.0\n', row])
            self.assertEqual(value['versions'], versions)
        self.assertEqual(self.run_query('apt', ['apt 3\n', (1, '', 'dpkg-query: no packages found matching hello\n')])['versions'], [])
        self.assertEqual(self.run_query('apt', ['apt 3\n', (1, '', 'database unreadable')])['error_code'], 'tool.version_probe_failed')

    def test_apt_repository_candidate_excludes_distribution_sources_and_installed_status(self):
        value = self.run_query('apt', ['hello:\n  Candidate: 0.0.123-gabc\n'], family='candidates', extra={'repository': {'name': 'vendor'}})
        self.assertEqual(value, {'version': '0.0.123-gabc'})
        argv = self.calls[-1][0]
        self.assertIn('Dir::Etc::sourcelist=/etc/apt/sources.list.d/orbit-vendor.sources', argv)
        self.assertIn('Dir::Etc::sourceparts=-', argv)
        self.assertIn('Dir::State::status=/dev/null', argv)

    def test_repository_package_is_downloaded_from_its_source_then_installed_with_normal_dependencies(self):
        source = (QUERIES.parent / 'writes/apt.py').read_text().replace('from support import INPUT', '')
        calls = []
        def run(argv, **kwargs):
            calls.append(argv)
            if 'download' in argv:
                self.assertEqual(argv[-1], 'hello=0.0.123-gabc')
                (Path(kwargs['cwd']) / 'hello.deb').write_bytes(b'fixture deb')
            return subprocess.CompletedProcess(argv, 0, '', '')
        namespace = {'INPUT': {'package': 'hello', 'repository': {'name': 'vendor'}, 'candidate': {'version': '0.0.123-gabc'}}}
        with patch('subprocess.run', side_effect=run), contextlib.redirect_stdout(io.StringIO()):
            exec(compile(source, 'writes/apt.py', 'exec'), namespace)
        self.assertIn('Dir::Etc::sourcelist=/etc/apt/sources.list.d/orbit-vendor.sources', calls[0])
        self.assertEqual(calls[1][1:6], ['install', '--yes', '--no-install-recommends', '--no-remove', '--'])
        self.assertTrue(calls[1][-1].endswith('/hello.deb'))
        self.assertFalse(any('Dir::Etc' in arg or 'allow-downgrades' in arg for arg in calls[1]))

    def test_composer_roots_and_inventory_use_existing_shared_scope(self):
        scope = self.path('/opt/orbit/composer'); scope.mkdir(parents=True)
        (scope / 'composer.json').write_text('{"require":{"hello":"*"}}')
        result = self.run_query('composer', ['Composer 2.9.0\n', {'installed': [{'name': 'hello', 'version': '1.2.3'}]}])
        self.assertEqual(result['roots'], ['hello'])
        self.assertEqual(result['versions'], ['1.2.3'])
        self.assertTrue(all(call[1]['env']['COMPOSER_HOME'] == str(scope) for call in self.calls))

    def test_owned_composer_scope_interrupted_before_manifest_can_retry_setup(self):
        scope = self.path('/opt/orbit/composer'); scope.mkdir(parents=True)
        self.assertEqual(self.run_query('composer', [])['scope'], 'absent')
        (scope / 'composer.json').symlink_to(self.root / 'missing')
        self.assertEqual(self.run_query('composer', [])['scope'], 'conflict')

    def test_vp_skips_cache_only_and_preserves_incomplete_inventory(self):
        self.path('/home/orbit/.vite-plus').mkdir(parents=True)
        binary = self.path('/home/orbit/.local/share/vite-plus/bin/vp'); binary.parent.mkdir(parents=True); binary.touch()
        result = self.run_query('vp', ['vp 1.0.0\n', [{'name': 'hello', 'version': '1.2.3'}, {'name': 'hello', 'version': '1.3.0'}]])
        self.assertFalse(result['complete'])
        self.assertEqual(result['versions'], ['1.2.3', '1.3.0'])
        self.assertEqual(self.calls[-1][0][-3:], ['list', '-g', '--json'])

    def test_homebrew_reads_selected_native_metadata_and_never_mutates_packages(self):
        binary = self.path('/home/linuxbrew/.linuxbrew/bin/brew'); binary.parent.mkdir(parents=True); binary.touch()
        record = {'name': 'hello', 'installed': [{'version': '1.2.3'}]}
        result = self.run_query('brew', ['Homebrew 7.0.0\n', {'formulae': [record]}, 'hello\n'])
        self.assertEqual(result['homebrew'], record)
        self.assertEqual(result['versions'], ['1.2.3'])
        self.assertEqual(self.calls[-2][0][1:], ['info', '--json=v2', '--formula', '--installed'])
        self.assertEqual(self.calls[-1][1]['env']['HOMEBREW_NO_AUTO_UPDATE'], '1')
        self.assertNotIn('HOMEBREW_FORCE_API_AUTO_UPDATE', self.calls[-1][1]['env'])

    def test_unavailable_formula_is_absent_without_a_named_lookup(self):
        binary = self.path('/home/linuxbrew/.linuxbrew/bin/brew'); binary.parent.mkdir(parents=True); binary.touch()
        self.assertEqual(self.run_query('brew', ['Homebrew 7.0.0\n', {'formulae': []}, ''])['versions'], [])
        self.assertEqual(self.calls[-1][0][-1], '-1')

    def test_casks_filter_other_taps_and_read_os_for_variations(self):
        binary = self.path('/opt/homebrew/bin/brew'); binary.parent.mkdir(parents=True); binary.touch()
        record = {'token': 'hello', 'tap': 'homebrew/cask', 'installed': '1.2.3'}
        result = self.run_query('brew-cask', ['Homebrew 7.0.0\n', {'casks': [{'token': 'hello', 'tap': 'other/tap', 'installed': '9.0'}, record]}, 'hello\n', '15.4\n'], 'macos')
        self.assertEqual(result['versions'], ['1.2.3'])
        self.assertEqual(result['os_major'], 15)
        self.assertTrue(all('sudo' not in argv for argv, _ in self.calls))

    def test_installed_name_metadata_disagreement_cannot_prove_absence(self):
        binary = self.path('/home/linuxbrew/.linuxbrew/bin/brew'); binary.parent.mkdir(parents=True); binary.touch()
        for rows, names in [([], 'hello\n'), ([{'name': 'hello', 'installed': [{'version': '1'}]}], '')]:
            self.assertFalse(self.run_query('brew', ['Homebrew 7\n', {'formulae': rows}, names])['complete'])
        binary = self.path('/opt/homebrew/bin/brew'); binary.parent.mkdir(parents=True); binary.touch()
        for rows in ([], [{'token': 'hello', 'tap': 'foreign/tap', 'installed': '9.0'}]):
            self.assertFalse(self.run_query('brew-cask', ['Homebrew 7\n', {'casks': rows}, 'hello\n', '15.4\n'], 'macos')['complete'])

    def test_apt_candidate_and_write_use_normal_selection_without_removal(self):
        self.assertEqual(self.run_query('apt', [' Candidate: 2.0.1\n'], family='candidates')['version'], '2.0.1')
        self.run_query('apt', [''], family='writes')
        self.assertEqual(self.calls[-1][0], ['/usr/bin/apt-get', 'install', '--yes', '--no-install-recommends', '--no-remove', '--', 'hello'])
        self.assertEqual(self.calls[-1][1]['env']['DEBIAN_FRONTEND'], 'noninteractive')

    def test_composer_dry_run_parses_normal_operations_and_rejects_ambiguity(self):
        for text in ['  - Locking hello (v2.0.1)\n  - Installing hello (v2.0.1)', '  - Upgrading hello (v1.0.0 => v2.0.1)']:
            self.assertEqual(self.run_query('composer', [text], family='candidates', extra={'kind': 'tool.install'})['version'], 'v2.0.1')
        self.assertIn('--dry-run', self.calls[-1][0])
        self.assertEqual(self.run_query('composer', ['  - Removing hello (v1.0.0)'], family='candidates', extra={'kind': 'tool.update'})['error_code'], 'tool.version_probe_failed')
        self.run_query('composer', [''], family='writes', extra={'kind': 'tool.install'})
        self.assertEqual(self.calls[-1][0][1:4], ['global', 'require', 'hello:*'])
        self.assertEqual(self.calls[-1][1]['env']['COMPOSER_HOME'], '/opt/orbit/composer')

    def test_vp_write_uses_observed_scope_and_selected_package(self):
        self.run_query('vp', [''], family='writes', extra={'kind': 'tool.update', 'before': {'scope_path': '/home/orbit/.vite-plus'}})
        self.assertEqual(self.calls[-1][0], ['/home/orbit/.vite-plus/bin/vp', 'update', '-g', 'hello', '--reinstall-node-mismatch'])
        self.assertEqual(self.calls[-1][1]['cwd'], '/home/orbit')

    def test_homebrew_writes_keep_bottle_and_official_cask_boundaries(self):
        for manager, coordinate in [('brew', 'homebrew/core/hello'), ('brew-cask', 'homebrew/cask/hello')]:
            self.run_query(manager, [''], platform='macos' if manager == 'brew-cask' else 'linux', family='writes', extra={'kind': 'tool.install', 'before': {'prefix': '/brew'}})
            argv, options = self.calls[-1]
            self.assertEqual(argv[-1], coordinate)
            self.assertEqual('--force-bottle' in argv, manager == 'brew')
            self.assertEqual(options['env']['HOMEBREW_NO_AUTOREMOVE'], '1')
            self.assertEqual(options['env'].get('HOMEBREW_FORCE_API_AUTO_UPDATE'), '1' if manager == 'brew-cask' else None)
            self.assertNotIn('sudo', argv)

    def test_scope_absence_and_invalid_manager_json_are_bounded(self):
        self.assertEqual(self.run_query('composer', [])['scope'], 'absent')
        binary = self.path('/opt/orbit/vite-plus/bin/vp'); binary.parent.mkdir(parents=True); binary.touch()
        self.assertEqual(self.run_query('vp', ['vp 1\n', '{'])['error_code'], 'tool.version_probe_failed')


if __name__ == '__main__':
    unittest.main()
