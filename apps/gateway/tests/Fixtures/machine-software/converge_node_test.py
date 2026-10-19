"""Regression tests for the fixed remote program; all package/service/network operations are faked."""
import importlib.util
import hashlib
import json
import io
import os
import tarfile
from types import SimpleNamespace
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('recipe', sys.argv.pop())
recipe = importlib.util.module_from_spec(spec)
exec(compile((Path(spec.origin).parent / 'artifacts.py').read_text(), 'lib/artifacts.py', 'exec'), recipe.__dict__)
source = Path(spec.origin).read_text().replace('# ORBIT_TASK_UNIT_STATE',
    (Path(spec.origin).parents[2] / 'tasks/unit-state.py').read_text())
exec(compile(source, spec.origin, 'exec'), recipe.__dict__)


class ConvergenceTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.policy = Path(self.directory.name) / 'policy'
        self.commands = []
        self.unit_states = {}
        self.published = {}
        self.before = {'curl': '1.0', 'unchanged': '1.0', 'php8.5-common': '1.0'}
        self.after = {'curl': '2.0', 'unchanged': '1.0', 'php8.5-common': '2.0', 'dependency': '3.0'}
        self.payload = {'snapshot': '20261014T000000Z', 'architecture': 'amd64', 'tools': {
            'curl': {'source': 'ubuntu', 'version': '2.0', 'package': 'curl'}}}

    def command(self, argv, **kwargs):
        self.commands.append(argv)
        output = ''
        if argv[:2] == ['dpkg-query', '-L']:
            output = {'curl': '/usr/lib/systemd/system/curl.service\n',
                      'dependency': '/lib/systemd/system/dependency.service\n',
                      'unchanged': '/lib/systemd/system/unchanged.service\n'}.get(argv[2], '')
        elif argv[:2] == ['systemctl', 'show']:
            output = self.unit_states.get(argv[-1], 'LoadState=loaded\nRequiredBy=\nBoundBy=\nConsistsOf=\n')
        return subprocess.CompletedProcess(argv, 1 if argv[:2] == ['dpkg', '--compare-versions'] else 0, output, '')

    def publish(self, path, content, *args):
        self.published[str(path)] = content
        if path == self.policy:
            self.policy.write_bytes(content)

    def apply(self, command=None, active_units=None):
        def execute(argv, **kwargs):
            result = (command or self.command)(argv, **kwargs)
            if result.returncode != 0 and kwargs.get('required', True):
                raise recipe.RecipeFailure(result=result)
            return result
        with patch.object(recipe, 'POLICY', self.policy), \
             patch.object(recipe, 'packages', side_effect=[self.before, self.after]), \
             patch.object(recipe, 'inspect', side_effect=[
                 {name: '1.0' for name in self.payload['tools']},
                 {name: '2.0' if name == 'curl' else '1.0' for name in self.payload['tools']}]), \
             patch.object(recipe, 'active_units', side_effect=active_units,
                          return_value={'curl.service', 'unchanged.service', 'php8.5-fpm.service'}), \
             patch.object(recipe, 'publish', side_effect=self.publish), \
             patch.object(recipe, 'run', side_effect=execute), \
             patch.object(recipe.pwd, 'getpwnam', return_value=None):
            return recipe.apply(self.payload)

    def test_restarts_only_services_of_changed_packages_including_php_extensions(self):
        result = self.apply()
        self.assertEqual(result['changed_packages'], ['curl', 'dependency', 'php8.5-common'])
        self.assertEqual(result['restarted_services'], ['curl.service', 'php8.5-fpm.service'])
        self.assertNotIn(['systemctl', 'restart', 'unchanged.service'], self.commands)
        self.assertNotIn(['systemctl', 'restart', 'dependency.service'], self.commands)
        self.assertNotIn(['systemctl', 'is-active', '--quiet', 'unchanged.service'], self.commands)
        self.assertFalse(self.policy.exists())
        source_path = next(path for path in self.published if path.endswith('/ubuntu.sources'))
        self.assertNotIn('/etc/apt/sources.list.d/ubuntu.sources', self.published)
        source = self.published[source_path].decode()
        self.assertIn('https://snapshot.ubuntu.com/ubuntu/20261014T000000Z/', source)
        self.assertIn('Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg', source)
        install = next(c for c in self.commands if c[:2] == ['apt-get', 'install'])
        self.assertIn('curl=2.0', install)
        self.assertIn('--no-remove', install)
        self.assertNotIn('--allow-downgrades', install)
        self.assertNotIn('upgrade', install)
        self.assertIn('Dir::Etc::sourceparts=-', install)
        self.assertNotIn('/etc/apt/apt.conf.d/99orbit-recipe', self.published)
        self.assertEqual(set(self.published), {source_path, str(self.policy)})

    def test_restarts_active_instances_of_a_changed_package_service_template(self):
        output = subprocess.CompletedProcess([], 0, '/usr/lib/systemd/system/wg-quick@.service\n', '')
        with patch.object(recipe, 'run', return_value=output):
            self.assertEqual(recipe.services_for(['wireguard-tools'], {'wg-quick@orbit.service', 'caddy.service'}),
                             ['wg-quick@orbit.service'])

    def test_health_checks_unchanged_recipe_services_without_restarting_them(self):
        self.payload['tools']['unchanged'] = {'source': 'ubuntu', 'package': 'unchanged', 'version': '1.0'}

        result = self.apply()

        self.assertTrue(result['healthy'])
        self.assertIn(['systemctl', 'is-active', '--quiet', 'unchanged.service'], self.commands)
        self.assertNotIn(['systemctl', 'restart', 'unchanged.service'], self.commands)

    def test_health_checks_services_of_changed_dependencies_outside_the_recipe(self):
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv[:2] == ['systemctl', 'list-units']:
                result.stdout = 'dependency.service loaded active running\n'
            elif argv[:2] == ['systemctl', 'show']:
                result.stdout = 'LoadState=loaded\nRequiredBy=\nBoundBy=\nConsistsOf=\n'
            return result

        result = self.apply(command, active_units=recipe.active_units)

        self.assertTrue(result['healthy'])
        self.assertEqual(result['restarted_services'], ['dependency.service'])
        self.assertIn(['systemctl', 'is-active', '--quiet', 'dependency.service'], self.commands)

    def test_health_checks_a_requires_dependent_of_a_restarted_service(self):
        self.before = {'dependency': '1.0'}
        self.after = {'dependency': '2.0'}
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv[:2] == ['systemctl', 'show']:
                result.stdout = ('LoadState=loaded\nRequiredBy=' +
                                 ('consumer.service' if argv[-1] == 'dependency.service' else '') +
                                 '\nBoundBy=\nConsistsOf=\nTransient=no\n')
            elif argv[:2] == ['systemctl', 'is-active'] and argv[-1] == 'consumer.service':
                return subprocess.CompletedProcess(argv, 3, '', '')
            return result

        with self.assertRaisesRegex(recipe.RecipeFailure, '^health:consumer.service$'):
            self.apply(command, active_units=lambda: {'dependency.service', 'consumer.service'})

        self.assertIn(['systemctl', 'restart', 'consumer.service', 'dependency.service'], self.commands)

    def test_health_failure_never_retries_or_rolls_back(self):
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv[:2] == ['systemctl', 'is-active']:
                raise recipe.RecipeFailure()
            return result
        with self.assertRaisesRegex(recipe.RecipeFailure, '^health:curl.service$'):
            self.apply(command)
        self.assertEqual(sum(c[:2] == ['apt-get', 'install'] for c in self.commands), 1)
        self.assertEqual([c for c in self.commands if c[:2] == ['systemctl', 'restart']],
                         [['systemctl', 'restart', 'curl.service', 'php8.5-fpm.service']])
        self.assertFalse(self.policy.exists())

    def test_health_excludes_unrelated_transient_work(self):
        transient = 'orbit-task-check-' + '1' * 32 + '.service'
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv[:2] == ['systemctl', 'list-units']:
                result.stdout = f'curl.service loaded active running\n{transient} loaded active running\n'
            elif argv[:2] == ['systemctl', 'show']:
                result.stdout += 'Transient=' + ('yes' if argv[-1] == transient else 'no') + '\n'
            elif argv[:2] == ['systemctl', 'is-active'] and argv[-1] == transient:
                return subprocess.CompletedProcess(argv, 4, '', '')
            return result

        result = self.apply(command, active_units=recipe.active_units)

        self.assertTrue(result['healthy'])
        self.assertNotIn(['systemctl', 'is-active', '--quiet', transient], self.commands)
        self.assertFalse(any(argv[:2] == ['systemctl', 'show'] and argv[-1] == transient for argv in self.commands))
        self.assertIn(['systemctl', 'is-active', '--quiet', 'curl.service'], self.commands)

    def test_restarts_the_transitive_active_unit_closure_in_one_transaction(self):
        self.unit_states = {
            'curl.service': 'LoadState=loaded\nRequiredBy=bridge.target dormant.service\nBoundBy=\nConsistsOf=\n',
            'bridge.target': 'LoadState=loaded\nRequiredBy=\nBoundBy=worker.service\nConsistsOf=\n',
            'worker.service': 'LoadState=loaded\nRequiredBy=\nBoundBy=\nConsistsOf=curl.service leaf.service\n',
            'leaf.service': 'LoadState=loaded\nRequiredBy=\nBoundBy=\nConsistsOf=\nTransient=yes\n'}
        active = {'curl.service', 'bridge.target', 'worker.service', 'leaf.service', 'unrelated.service'}

        result = self.apply(active_units=lambda: active)

        expected = ['bridge.target', 'curl.service', 'leaf.service', 'worker.service']
        self.assertEqual(result['restarted_services'], expected)
        self.assertEqual([c for c in self.commands if c[:2] == ['systemctl', 'restart']],
                         [['systemctl', 'restart', *expected]])
        for unit in expected:
            self.assertIn(['systemctl', 'is-active', '--quiet', unit], self.commands)
            self.assertEqual(sum(c[:2] == ['systemctl', 'show'] and c[-1] == unit for c in self.commands), 2)
        self.assertFalse(any(c[:2] == ['systemctl', 'show'] and c[-1] in ('unrelated.service', 'dormant.service')
                             for c in self.commands))

    def test_health_checks_dependents_of_unchanged_pinned_packages_without_restarting_them(self):
        self.unit_states['unchanged.service'] = 'LoadState=loaded\nRequiredBy=consumer.service\nBoundBy=\nConsistsOf=\n'
        self.payload['tools']['unchanged'] = {'source': 'ubuntu', 'package': 'unchanged', 'version': '1.0'}

        result = self.apply(active_units=lambda: {'unchanged.service', 'consumer.service'})

        self.assertTrue(result['healthy'])
        self.assertIn(['systemctl', 'is-active', '--quiet', 'consumer.service'], self.commands)
        self.assertFalse(any(c[:2] == ['systemctl', 'restart'] for c in self.commands))

    def test_health_owns_debian_artifact_services_but_not_archive_name_collisions(self):
        for suffix, checked in [('.deb', True), ('.tar.gz', False)]:
            with self.subTest(suffix=suffix):
                self.commands.clear()
                self.payload['tools'] = {'caddy': {'source': 'artifact', 'version': '1.0', 'artifacts': {
                    'amd64': {'url': 'https://example.test/caddy' + suffix, 'sha256': '0' * 64}}}}
                def command(argv, **kwargs):
                    result = self.command(argv, **kwargs)
                    if argv[:2] == ['dpkg-query', '-L'] and argv[-1] == 'caddy':
                        result.stdout = '/usr/lib/systemd/system/caddy.service\n'
                    return result

                result = self.apply(command, active_units=lambda: {'caddy.service'})

                self.assertTrue(result['healthy'])
                self.assertEqual(['systemctl', 'is-active', '--quiet', 'caddy.service'] in self.commands, checked)

    def test_dependent_selection_skips_vanished_units_and_names_ambiguous_or_unreadable_units(self):
        stub = 'LoadState=not-found\nActiveState=inactive\nSubState=dead\nControlGroup=\nJob=\nDescription=consumer.service\n'
        for state in [stub, 'LoadState=not-found\n', stub.replace('inactive', 'active'),
                      stub.replace('ControlGroup=\n', 'ControlGroup=/system.slice/live.service\n'),
                      stub.replace('Job=\n', 'Job=123\n'), 'LoadState=error\n', 'LoadState=loaded\n', '', None]:
            with self.subTest(state=state):
                self.commands.clear()
                self.unit_states['curl.service'] = 'LoadState=loaded\nRequiredBy=consumer.service\nBoundBy=\nConsistsOf=\n'
                self.unit_states['consumer.service'] = state
                def command(argv, **kwargs):
                    if argv[:2] == ['systemctl', 'show'] and argv[-1] == 'consumer.service' and state is None:
                        return subprocess.CompletedProcess(argv, 1, '', 'unreadable')
                    return self.command(argv, **kwargs)

                if state == stub:
                    result = self.apply(command, active_units=lambda: {'curl.service', 'consumer.service'})
                    self.assertEqual(result['restarted_services'], ['curl.service'])
                else:
                    with self.assertRaisesRegex(recipe.RecipeFailure, '^preflight:consumer.service$'):
                        self.apply(command, active_units=lambda: {'curl.service', 'consumer.service'})
                    self.assertFalse(any(c[:2] == ['systemctl', 'restart'] for c in self.commands))

    def test_health_accepts_a_collected_transient_dependent_after_the_restart_transaction(self):
        transient = 'orbit-task-check-' + '2' * 32 + '.service'
        self.unit_states['curl.service'] = 'LoadState=loaded\nRequiredBy=' + transient + '\nBoundBy=\nConsistsOf=\n'
        self.unit_states[transient] = 'LoadState=loaded\nRequiredBy=\nBoundBy=\nConsistsOf=\nTransient=yes\n'
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv[:2] == ['systemctl', 'is-active'] and argv[-1] == transient:
                return subprocess.CompletedProcess(argv, 4, '', '')
            if argv[:2] == ['systemctl', 'show'] and argv[-1] == transient and ['systemctl', 'is-active', '--quiet', transient] in self.commands:
                result.stdout = 'LoadState=not-found\nActiveState=inactive\nSubState=dead\nControlGroup=\nJob=\nDescription=' + transient + '\n'
            return result

        result = self.apply(command, active_units=lambda: {'curl.service', transient})

        self.assertTrue(result['healthy'])
        self.assertIn(['systemctl', 'restart', 'curl.service', transient], self.commands)
        self.assertEqual(self.commands.count(['systemctl', 'is-active', '--quiet', transient]), 1)

    def test_health_still_refuses_a_loaded_stopped_or_unreadable_dependent(self):
        for state in ['LoadState=loaded\nActiveState=inactive\n', 'LoadState=loaded\nActiveState=failed\n',
                      'LoadState=not-found\nActiveState=active\nControlGroup=/system.slice/live.service\nJob=\n',
                      'LoadState=not-found\nActiveState=inactive\nControlGroup=/system.slice/live.service\nJob=\n',
                      'LoadState=not-found\nActiveState=inactive\nControlGroup=\nJob=123\n',
                      'LoadState=not-found\n', 'LoadState=error\n', '', None]:
            with self.subTest(state=state):
                self.commands.clear()
                self.unit_states['curl.service'] = 'LoadState=loaded\nRequiredBy=consumer.service\nBoundBy=\nConsistsOf=\n'
                def command(argv, **kwargs):
                    result = self.command(argv, **kwargs)
                    if argv[:2] == ['systemctl', 'is-active'] and argv[-1] == 'consumer.service':
                        return subprocess.CompletedProcess(argv, 3, '', '')
                    if argv[:2] == ['systemctl', 'show'] and argv[-1] == 'consumer.service' and ['systemctl', 'is-active', '--quiet', 'consumer.service'] in self.commands:
                        if state is None:
                            return subprocess.CompletedProcess(argv, 1, '', 'unreadable')
                        result.stdout = state
                    return result

                with self.assertRaisesRegex(recipe.RecipeFailure, '^health:consumer.service$'):
                    self.apply(command, active_units=lambda: {'curl.service', 'consumer.service'})
                self.assertFalse(self.policy.exists())

    def test_package_failure_cleans_up_suppression_without_restart_or_rollback(self):
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv[:2] == ['apt-get', 'install']:
                self.assertEqual(kwargs['env']['NEEDRESTART_SUSPEND'], '1')
                self.assertEqual(self.policy.read_text(), '#!/bin/sh\nexit 101\n')
                raise recipe.RecipeFailure(result=subprocess.CompletedProcess(argv, 100, 'curl install failed', 'dependency conflict'))
            return result
        with self.assertRaisesRegex(recipe.RecipeFailure, '^packages$') as failed:
            self.apply(command)
        self.assertIn('curl install failed', failed.exception.result.stdout)
        self.assertIn('dependency conflict', failed.exception.result.stderr)
        self.assertEqual(failed.exception.packages, ['curl=2.0'])
        self.assertFalse(self.policy.exists())
        self.assertFalse(any(c[:2] == ['systemctl', 'restart'] for c in self.commands))
        self.assertEqual(sum(c[:2] == ['apt-get', 'install'] for c in self.commands), 1)

    def test_does_not_replace_an_existing_restart_policy(self):
        self.policy.write_text('existing policy')
        with self.assertRaisesRegex(recipe.RecipeFailure, '^preflight$'):
            self.apply()
        self.assertEqual(self.policy.read_text(), 'existing policy')
        self.assertTrue(all(c[:2] == ['dpkg', '--compare-versions'] for c in self.commands))
        self.assertFalse(self.published)

    def test_dependency_check_failure_stops_before_restarts_and_cleans_the_restart_policy(self):
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv[:2] == ['apt-get', 'check']:
                raise recipe.RecipeFailure(result=subprocess.CompletedProcess(argv, 100, '', 'broken dependencies'))
            return result
        with self.assertRaisesRegex(recipe.RecipeFailure, '^packages$') as failed:
            self.apply(command)
        self.assertEqual(failed.exception.result.stderr, 'broken dependencies')
        self.assertFalse(self.policy.exists())
        self.assertFalse(any(c[:2] == ['systemctl', 'restart'] for c in self.commands))

    def test_no_package_changes_produce_no_restarts_or_install(self):
        self.before = self.after
        # Tool versions are supplied separately to test the post-install package diff.
        result = self.apply()
        self.assertEqual(result['changed_packages'], [])
        self.assertEqual(result['restarted_services'], [])
        self.assertFalse(any(c[:2] == ['systemctl', 'restart'] for c in self.commands))

    def test_unchanged_explicit_pins_are_not_reinstalled(self):
        with patch.object(recipe, 'POLICY', self.policy), \
             patch.object(recipe, 'packages', return_value={'curl': '2.0'}), \
             patch.object(recipe, 'active_units', return_value=set()), \
             patch.object(recipe, 'publish', side_effect=self.publish), \
             patch.object(recipe, 'run', side_effect=self.command), \
             patch.object(recipe.pwd, 'getpwnam', return_value=None):
            result = recipe.apply(self.payload)
        self.assertEqual(result['changed_tools'], [])
        self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    def test_exact_version_mismatch_stops_before_restarts(self):
        with patch.object(recipe, 'POLICY', self.policy), \
             patch.object(recipe, 'packages', return_value=self.before), \
             patch.object(recipe, 'inspect', return_value={'curl': 'wrong'}), \
             patch.object(recipe, 'active_units', return_value=set()), \
             patch.object(recipe, 'publish', side_effect=self.publish), \
             patch.object(recipe, 'run', side_effect=self.command), \
             patch.object(recipe.pwd, 'getpwnam', return_value=None):
            with self.assertRaisesRegex(recipe.RecipeFailure, '^inventory$'):
                recipe.apply(self.payload)
        self.assertFalse(any(c[:2] == ['systemctl', 'restart'] for c in self.commands))

    def test_rejects_malformed_or_duplicate_os_identity_without_evaluating_it(self):
        for document in ['ID="ubuntu\nVERSION_CODENAME=resolute',
                         'ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute',
                         'ID=$(false)\nVERSION_CODENAME=resolute']:
            with self.subTest(document=document), patch.object(recipe.Path, 'read_text', return_value=document):
                with self.assertRaisesRegex(recipe.RecipeFailure, '^platform:unknown:unknown$'):
                    recipe.platform_check('amd64')

    def test_node_version_prefix_and_missing_binary_are_read_only(self):
        with patch.object(recipe, 'packages', return_value={}), \
             patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, 'v24.22.0\n', '')):
            self.assertEqual(recipe.binary_version('/fixed/node'), '24.22.0')
        with patch.object(recipe.subprocess, 'Popen', side_effect=FileNotFoundError):
            self.assertEqual(recipe.run(['/absent'], required=False).returncode, 127)

    def test_dry_run_never_invokes_shims_that_could_bootstrap_or_download_a_runtime(self):
        scope = Path(self.directory.name)
        (scope / 'bin').mkdir()
        (scope / 'bin/vp').write_text('uninitialized standalone binary')
        (scope / 'config.json').write_text('{"defaultNodeVersion":"lts"}')
        tools = {name: {'source': 'artifact'} for name in ['node', 'vp', 'vp-cli', 'pnpm']}
        with patch.object(recipe, 'packages', return_value={}), \
             patch.object(recipe, 'vite_scope', return_value=scope), \
             patch.object(recipe, 'run') as command:
            self.assertEqual(recipe.inspect(tools), {'node': None, 'vp': None, 'vp-cli': None, 'pnpm': None})
        command.assert_not_called()

    def test_artifact_hash_failure_stops_before_installation(self):
        artifact = Path(self.directory.name) / 'artifact'
        artifact.write_bytes(b'wrong bytes')
        pin = {'artifacts': {'amd64': {'url': 'https://example.test/v1.0.0/tool', 'sha256': '0' * 64}}}
        with patch.object(recipe, 'run', side_effect=self.command):
            with self.assertRaises(recipe.RecipeFailure):
                recipe.download(pin, 'amd64', artifact)
        self.assertEqual(len(self.commands), 1)
        self.assertIn('--proto-redir', self.commands[0])

    def test_installs_verified_node_into_the_existing_vite_runtime_without_replacing_global_store(self):
        home = Path(self.directory.name)
        scope = home / '.vite-plus'
        (scope / 'bin').mkdir(parents=True)
        (scope / 'bin/vp').write_text('existing vp')
        (scope / 'packages').mkdir()
        (scope / 'packages/keep').write_text('existing global tool')
        work = home / 'work'
        work.mkdir()
        archive = work / 'node.archive'
        with tarfile.open(archive, 'w:gz') as output:
            data = b'#!/bin/sh\necho v24.22.0\n'
            item = tarfile.TarInfo('node-v24.22.0-linux-x64/bin/node')
            item.mode = 0o755
            item.size = len(data)
            output.addfile(item, io.BytesIO(data))
        account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=str(home), pw_name='test-user')
        real_safe_directory = recipe.safe_directory
        real_publish = recipe.publish
        def safe_directory(path, uid=0, gid=0):
            if str(path) == '/opt/orbit':
                return home
            return real_safe_directory(path, uid, gid)
        def publish(path, content, *args, **kwargs):
            if str(path).startswith('/usr/local/bin/'):
                self.published[str(path)] = content
                return
            real_publish(path, content, *args, **kwargs)
        with patch.object(recipe, 'safe_directory', side_effect=safe_directory), \
             patch.object(recipe, 'publish', side_effect=publish), \
             patch.object(recipe, 'run', side_effect=self.command):
            recipe.install_javascript({'node': {'version': '24.22.0'}}, {'node': archive}, work, account)
        cached = scope / 'js_runtime/node/24.22.0/bin/node'
        self.assertEqual(subprocess.check_output([str(cached)], text=True).strip(), 'v24.22.0')
        self.assertEqual((scope / 'packages/keep').read_text(), 'existing global tool')
        self.assertEqual((scope / 'bin/node').readlink(), Path('vp'))
        self.assertIn(['sudo', '-u', 'test-user', '-H', 'env', 'VP_HOME=' + str(scope),
                       str(scope / 'bin/vp'), 'env', 'default', '24.22.0'], self.commands)

    def test_pinned_vp_does_not_invoke_its_first_start_installer(self):
        home = Path(self.directory.name)
        scope = home / '.vite-plus'
        (scope / 'bin').mkdir(parents=True)
        (scope / 'bin/vp').write_text('existing vp')
        work = home / 'work'
        work.mkdir()
        archive = work / 'vp.archive'
        with tarfile.open(archive, 'w:gz') as output:
            data = b'#!/bin/sh\ntest -f "$(dirname "$0")/.vp-setup-complete" || exit 3\necho "vp 1.2.0"\n'
            item = tarfile.TarInfo('vp')
            item.mode = 0o755
            item.size = len(data)
            output.addfile(item, io.BytesIO(data))
        account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=str(home), pw_name='test-user')
        real_safe_directory = recipe.safe_directory
        real_publish = recipe.publish
        def safe_directory(path, uid=0, gid=0):
            if str(path) == '/opt/orbit':
                return home
            return real_safe_directory(path, uid, gid)
        def publish(path, content, *args, **kwargs):
            if str(path).startswith('/usr/local/bin/'):
                return
            real_publish(path, content, *args, **kwargs)
        with patch.object(recipe, 'safe_directory', side_effect=safe_directory), \
             patch.object(recipe, 'publish', side_effect=publish):
            recipe.install_javascript({'vp-cli': {'version': '1.2.0'}}, {'vp-cli': archive}, work, account)
        self.assertEqual(subprocess.check_output([str(scope / 'bin/vp'), '--version'], text=True).strip(), 'vp 1.2.0')

    def test_installs_native_pnpm_without_referencing_the_removed_cjs_entrypoint(self):
        home = Path(self.directory.name)
        scope = home / '.vite-plus'
        (scope / 'bin').mkdir(parents=True)
        (scope / 'bin/vp').write_text('existing vp')
        work = home / 'work'
        work.mkdir()
        archive = work / 'pnpm.archive'
        native = b'\x7fELF-pinned-native-pnpm'
        with tarfile.open(archive, 'w:gz') as output:
            for name, data in [('package/pnpm', native), ('package/package.json', b'{"version":"12.11.2"}')]:
                item = tarfile.TarInfo(name)
                item.mode = 0o755
                item.size = len(data)
                output.addfile(item, io.BytesIO(data))
        account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_dir=str(home), pw_name='test-user')
        real_safe = recipe.safe_directory
        real_publish = recipe.publish
        def safe(path, uid=0, gid=0):
            return home if str(path) == '/opt/orbit' else real_safe(path, uid, gid)
        def publish(path, content, *args, **kwargs):
            if not str(path).startswith('/usr/local/bin/'):
                real_publish(path, content, *args, **kwargs)
        with patch.object(recipe, 'safe_directory', side_effect=safe), patch.object(recipe, 'publish', side_effect=publish):
            recipe.install_javascript({'pnpm': {'version': '12.11.2'}}, {'pnpm': archive}, work, account)
        self.assertEqual((scope / 'bin/pnpm').read_bytes(), native)
        self.assertEqual((scope / 'bin/pnpm').stat().st_mode & 0o777, 0o755)

    def test_pinned_vite_distribution_replaces_only_its_runtime_with_the_complete_lock(self):
        scope = Path(self.directory.name)
        old = scope / 'old-vite'
        old.mkdir()
        (old / 'package.json').write_text('{"version":"1.0.0","dependencies":{"dep":"1.0.0"}}')
        active = scope / 'node_modules/vite-plus'
        active.parent.mkdir()
        active.symlink_to(old)
        runtime = scope / 'recipe'
        runtime.mkdir()
        lock = json.dumps({'name': 'orbit-recipe-vite-plus', 'version': '2.0.0', 'packages': {'': {'dependencies': {'vite-plus': '2.0.0'}}}})
        tools = {'vp': {'version': '2.0.0', 'lock_sha256': hashlib.sha256(lock.encode()).hexdigest()}, 'node': {'version': '24.22.0'}}
        account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_name='test-user')
        def command(argv):
            self.assertIn('--ignore-scripts', argv)
            candidate = Path(argv[-1]) / 'node_modules/vite-plus'
            candidate.mkdir(parents=True)
            (candidate / 'package.json').write_text('{"version":"2.0.0","dependencies":{"dep":"2.0.0"}}')
        with patch.object(recipe, 'run', side_effect=command):
            recipe.install_vite_runtime(scope, runtime, tools, lock, account)
        self.assertEqual(json.loads((active / 'package.json').read_text())['version'], '2.0.0')
        self.assertEqual(json.loads((old / 'package.json').read_text())['version'], '1.0.0')

    def test_refuses_a_vite_runtime_without_its_pinned_lock(self):
        scope = Path(self.directory.name)
        account = SimpleNamespace(pw_uid=os.getuid(), pw_gid=os.getgid(), pw_name='test-user')
        with patch.object(recipe, 'run') as command:
            with self.assertRaises(recipe.RecipeFailure):
                recipe.install_vite_runtime(scope, scope, {'vp': {'lock_sha256': '0' * 64}}, '{}', account)
        command.assert_not_called()

    def test_publication_refuses_foreign_symlinks(self):
        target = Path(self.directory.name) / 'target'
        target.write_text('keep')
        link = Path(self.directory.name) / 'link'
        link.symlink_to(target)
        with self.assertRaises(recipe.RecipeFailure):
            recipe.publish(link, b'replace')
        self.assertEqual(target.read_text(), 'keep')


if __name__ == '__main__':
    unittest.main()
