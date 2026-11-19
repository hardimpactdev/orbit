"""Converge reuses a verified install (docs/reference/node-provisioning.md#check-many-files-in-one-read): Squid's apt task runs only
when the package is not installed, and Reverb's Git checkout and Composer install run only when its release does not verify at the
wanted ref. The production tasks run with real Git and PHP; apt and Composer are replaced by a logged call."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
LOCK = {'packages': [{'name': 'laravel/reverb', 'version': 'v1.4.0'}, {'name': 'react/socket', 'version': 'v1.16.0'}]}
# Composer's shape: autoload.php requires composer/autoload_real.php, which here declares Reverb's service provider.
AUTOLOAD = "<?php\nrequire __DIR__ . '/composer/autoload_real.php';\n"
AUTOLOAD_REAL = "<?php\nnamespace Laravel\\Reverb;\nclass ReverbServiceProvider {}\n"
INSTALLED = {'packages': [{'name': 'react/socket', 'version': 'v1.16.0'}, {'name': 'laravel/reverb', 'version': 'v1.4.0'}],
             'dev': False}


def git(*args, cwd):
    subprocess.run(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', *args], cwd=cwd, check=True, capture_output=True)


def loaded(task_file, names, replace):
    """The named production tasks, become dropped (the test runs as its own user) and each module in `replace` logged instead."""
    tasks = [task for task in yaml.safe_load((ANSIBLE / 'tasks' / task_file).read_text()) if task['name'] in names]
    for task in tasks:
        task.pop('become', None)
        task.pop('become_user', None)
        for module in replace:
            if module in task:
                del task[module]
                task['ansible.builtin.shell'] = f'echo {module} >> "$STUB_DIR/calls.log"'
    return tasks


@unittest.skipUnless(Path(COMMAND).exists() and shutil.which('php') and shutil.which('git'), 'needs ansible-playbook, PHP and Git')
class ReuseInstallTest(unittest.TestCase):
    def play(self, folder, tasks, variables, status=None, code=0):
        (folder / 'bin').mkdir(exist_ok=True)
        stub = folder / 'bin/dpkg-query'
        # dpkg prints the status word of a known package; an unknown one exits 1 with nothing on stdout.
        stub.write_text('#!/bin/sh\n' + (f'printf %s {status}\n' if status else 'echo "no packages found" >&2\n') + f'exit {code}\n')
        stub.chmod(0o755)
        (folder / 'play.yml').write_text(json.dumps([{'hosts': 'node', 'gather_facts': False, 'tasks': tasks}]))
        (folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'node': {
            'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}))
        (folder / 'calls.log').write_text('')
        result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), '-e', json.dumps(variables), str(folder / 'play.yml')],
                                check=False, capture_output=True, text=True, timeout=120, env={
                                    **os.environ, 'PATH': f"{folder / 'bin'}:{os.environ['PATH']}", 'STUB_DIR': str(folder),
                                    'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
        output = result.stdout + result.stderr
        self.assertEqual(result.returncode, 0, output)
        return output, (folder / 'calls.log').read_text().split()

    def squid(self, status, code=0):
        with tempfile.TemporaryDirectory() as folder:
            tasks = loaded('instance-egress.yml', ('Read the installed Squid package', 'Install Squid'), ['ansible.builtin.apt'])
            return self.play(Path(folder), tasks, {}, status, code)

    def test_an_installed_squid_skips_apt(self):
        output, calls = self.squid('installed')
        self.assertEqual(calls, [])
        self.assertIn('changed=0', output)

    def test_squid_removed_with_its_config_files_left_installs_again(self):
        _, calls = self.squid('config-files')
        self.assertEqual(calls, ['ansible.builtin.apt'])

    def test_an_unknown_squid_package_installs(self):
        _, calls = self.squid(None, 1)
        self.assertEqual(calls, ['ansible.builtin.apt'])

    def test_a_failed_squid_read_runs_apt(self):
        # Any failed read runs apt, even one that printed `installed`.
        for status, code in ((None, 2), ('installed', 2)):
            _, calls = self.squid(status, code)
            self.assertEqual(calls, ['ansible.builtin.apt'], (status, code))

    def reverb(self, change=None, clone=True):
        """Converge a release folder: absent when `clone` is false, else installed at the wanted ref and then changed by `change`.
        Returns the output, the logged calls and the checkout's revision after the run."""
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            source = folder / 'source'
            source.mkdir()
            (source / 'composer.lock').write_text(json.dumps(LOCK))
            (source / 'artisan').write_text('old\n')
            git('init', '-q', '-b', 'main', cwd=source)
            git('add', '.', cwd=source)
            git('commit', '-q', '-m', 'one', cwd=source)
            (source / 'artisan').write_text('new\n')
            git('commit', '-q', '-am', 'two', cwd=source)
            ref = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=source, check=True, capture_output=True, text=True).stdout.strip()
            release = folder / 'release'
            if clone:
                git('clone', '-q', str(source), str(release), cwd=folder)
                (release / 'vendor/composer').mkdir(parents=True)
                (release / 'vendor/autoload.php').write_text(AUTOLOAD)
                (release / 'vendor/composer/autoload_real.php').write_text(AUTOLOAD_REAL)
                (release / 'vendor/composer/installed.json').write_text(json.dumps(INSTALLED))
                if change:
                    change(release)
            tasks = loaded('websocket.yml', ('Read the Reverb release', 'Check out Reverb', 'Install Reverb packages'),
                           ['community.general.composer'])
            websocket = {'repository': str(source), 'ref': ref, 'release': str(release), 'user': os.environ.get('USER', 'root')}
            output, calls = self.play(folder, tasks, {'rt': {'websocket': websocket}})
            head = subprocess.run(['git', 'rev-parse', 'HEAD'], cwd=release, check=True, capture_output=True, text=True).stdout.strip()
            self.assertEqual(head, ref)
            return output, calls

    def test_a_release_that_verifies_at_the_ref_is_reused(self):
        output, calls = self.reverb()
        self.assertEqual(calls, [])
        self.assertIn('changed=0', output)

    def test_a_missing_release_is_checked_out_and_installed(self):
        output, calls = self.reverb(clone=False)
        self.assertEqual(calls, ['community.general.composer'])
        self.assertIn('changed=2', output)

    def test_a_checkout_at_another_ref_is_checked_out_again(self):
        output, calls = self.reverb(lambda release: git('checkout', '-q', 'HEAD~1', cwd=release))
        self.assertEqual(calls, ['community.general.composer'])
        self.assertIn('changed=2', output)

    def test_a_changed_tracked_file_is_restored(self):
        output, calls = self.reverb(lambda release: (release / 'artisan').write_text('edited\n'))
        self.assertEqual(calls, ['community.general.composer'])
        self.assertIn('changed=2', output)

    def test_a_missing_vendor_folder_installs_again(self):
        _, calls = self.reverb(lambda release: shutil.rmtree(release / 'vendor'))
        self.assertEqual(calls, ['community.general.composer'])

    def test_an_autoloader_that_does_not_load_installs_again(self):
        _, calls = self.reverb(lambda release: (release / 'vendor/composer/autoload_real.php').unlink())
        self.assertEqual(calls, ['community.general.composer'])

    def test_an_autoloader_without_reverb_installs_again(self):
        _, calls = self.reverb(lambda release: (release / 'vendor/composer/autoload_real.php').write_text('<?php\n'))
        self.assertEqual(calls, ['community.general.composer'])

    def test_an_install_that_differs_from_the_lock_file_installs_again(self):
        installed = {**INSTALLED, 'packages': [{'name': 'laravel/reverb', 'version': 'v1.3.0'}]}
        _, calls = self.reverb(lambda release: (release / 'vendor/composer/installed.json').write_text(json.dumps(installed)))
        self.assertEqual(calls, ['community.general.composer'])

    def test_an_install_with_dev_packages_installs_again(self):
        installed = {**INSTALLED, 'dev': True}
        _, calls = self.reverb(lambda release: (release / 'vendor/composer/installed.json').write_text(json.dumps(installed)))
        self.assertEqual(calls, ['community.general.composer'])


if __name__ == '__main__':
    unittest.main()
