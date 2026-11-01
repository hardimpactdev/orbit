"""Legacy create prepare and source cases (F9 parity), executed against real Git without a Node."""
import importlib.util
import os
import pwd
import subprocess
import tempfile
import unittest
from pathlib import Path


def module(name):
    return module_from('create', name)


def module_from(directory, name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).parent / directory / (name + '.py'))
    value = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(value)
    return value


class LegacyCreatePhases(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.seed = self.root / 'default'
        self.seed.mkdir()
        self.git('init', '-q', '--initial-branch=main')
        self.git('config', 'user.name', 'Fixture')
        self.git('config', 'user.email', 'fixture@example.test')
        self.git('remote', 'add', 'origin', 'https://github.com/example/project.git')
        (self.seed / 'README').write_text('seed\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'seed')
        self.commit = self.git('rev-parse', 'HEAD')
        self.target = self.root / 'feature'
        self.value = {'instance_name': 'feature', 'application': str(self.target), 'project_type': 'node-package',
                      'skip_profile': False, 'entry_point': 'artisan', 'retained_source': None,
                      'source': {'checkout': str(self.target), 'root': str(self.root),
                                 'user': pwd.getpwuid(os.geteuid()).pw_name, 'prepare_id': 'own-prepare',
                                 'repository': 'https://github.com/example/project.git', 'seed_repository': str(self.seed)},
                      'resolve': {'branch': 'feature', 'default_branch': 'main', 'seed_commit': self.commit, 'network': False,
                                  'protocols': ['https']}}

    def git(self, *args, directory=None):
        return subprocess.run(['git', '-C', str(directory or self.seed), *args], capture_output=True,
                              text=True, check=True).stdout.strip()

    def create(self, token=None):
        module('prepare').prepare(self.value)
        return module('source').source(self.value, {'github_token': token})

    def test_absent_branch_starts_at_seed_without_changing_default(self):
        result = self.create()
        self.assertEqual(result['commit'], self.commit)
        self.assertEqual(subprocess.run(['git', '-C', str(self.target), 'rev-parse', '--abbrev-ref', 'HEAD'], capture_output=True, text=True, check=True).stdout.strip(), 'feature')
        self.assertEqual(self.git('rev-parse', '--abbrev-ref', 'HEAD'), 'main')
        self.assertEqual(self.git('rev-parse', 'HEAD'), self.commit)
        self.assertTrue((self.target / '.git').is_file())
        self.assertFalse((self.seed / '.env').exists())

    def test_branch_in_use_refuses_before_target_effect(self):
        self.value['resolve']['branch'] = 'main'
        with self.assertRaisesRegex(ValueError, 'instance.branch_in_use'):
            self.create()
        self.assertFalse(self.target.exists())
        self.assertEqual(self.git('rev-parse', 'HEAD'), self.commit)

    def test_retained_source_reuses_exact_checkout_without_reset(self):
        first = self.create()
        self.value['retained_source'] = {'commit': first['commit']}
        self.assertEqual(self.create()['commit'], self.commit)
        self.value['retained_source']['commit'] = 'a' * 40
        with self.assertRaisesRegex(ValueError, 'instance.source_identity_changed'):
            self.create()

    def test_equivalent_git_ssh_origin_preserves_seed_identity(self):
        self.git('remote', 'set-url', 'origin', 'git@github.com:example/project.git')
        result = self.create()
        self.assertEqual(result['commit'], self.commit)
        self.assertEqual(self.git('config', '--get', 'remote.origin.url'), 'git@github.com:example/project.git')

    def test_foreign_origin_refuses_in_prepare_before_any_write(self):
        self.git('remote', 'set-url', 'origin', 'https://github.com/foreign/project.git')
        with self.assertRaisesRegex(ValueError, 'instance.source_identity_invalid'):
            module('prepare').prepare(self.value)
        self.assertFalse(self.target.exists())

    def test_foreign_origin_on_a_retained_checkout_refuses_in_prepare(self):
        self.create()
        self.git('remote', 'set-url', 'origin', 'https://github.com/foreign/project.git', directory=self.target)
        with self.assertRaisesRegex(ValueError, 'instance.source_identity_invalid'):
            module('prepare').prepare(self.value)

    def test_unsupported_protocol_refuses_in_prepare_before_any_write(self):
        self.value['resolve']['protocols'] = ['ext']
        with self.assertRaisesRegex(ValueError, 'instance.source_invalid'):
            module('prepare').prepare(self.value)
        self.assertFalse(self.target.exists())

    def test_stateless_github_token_with_dots_is_accepted_and_malformed_material_refused(self):
        token = 'ghs_12345_' + 'a' * 170 + '.' + 'b' * 170 + '.' + 'c' * 170
        self.assertEqual(self.create(token)['commit'], self.commit)
        with self.assertRaisesRegex(ValueError, 'instance.git_mint_invalid'):
            self.create('token with spaces')

    def test_foreign_preparation_marker_refuses_retry(self):
        self.create()
        gitdir = Path(self.git('rev-parse', '--absolute-git-dir', directory=self.target))
        (gitdir / 'orbit-source-prepare').write_text('foreign\n')
        with self.assertRaisesRegex(ValueError, 'instance.source_identity_changed'):
            module('prepare').prepare(self.value)

    def test_symlink_parent_refuses_before_effect(self):
        alias = self.root / 'alias'
        alias.symlink_to(self.root, target_is_directory=True)
        self.value['source']['checkout'] = str(alias / 'feature')
        with self.assertRaisesRegex(ValueError, 'instance.source_invalid'):
            module('prepare').prepare(self.value)
        self.assertFalse(self.target.exists())

    def test_source_prints_only_the_checked_out_commit(self):
        (self.seed / '.env').write_text('PRIVATE=never-return-node-value\n')
        self.assertEqual(self.create(), {'ready': True, 'commit': self.commit})

    def test_environment_write_refuses_a_symlinked_application_parent(self):
        outside = self.root / 'outside'
        (outside / 'site').mkdir(parents=True)
        (self.seed / 'linked').symlink_to(outside, target_is_directory=True)
        publish = module_from('scripts', 'write_environment').publish
        with self.assertRaisesRegex(ValueError, 'env.sync_failed'):
            publish({'directory': str(self.seed / 'linked/site'), 'checkout': str(self.seed), 'content': 'A="1"\n'})
        self.assertFalse((outside / 'site/.env').exists())
        self.assertEqual(publish({'directory': str(self.seed), 'checkout': str(self.seed), 'content': 'A="1"\n'}), {'ready': True})
        self.assertEqual(((self.seed / '.env').read_text(), oct((self.seed / '.env').stat().st_mode & 0o777)), ('A="1"\n', '0o600'))

    def setup_steps(self, *commands, seconds=30):
        import hashlib
        return [{'name': 'step-' + str(index), 'command': command, 'sha256': hashlib.sha256(command.encode()).hexdigest(),
                 'timeout_seconds': seconds, 'directory': str(self.seed), 'instance_id': 17, 'instance_name': 'proof',
                 'seed_path': '', 'seed_commit': ''} for index, command in enumerate(commands)]

    def run_setup(self, steps):
        runner = (Path(__file__).parent / 'scripts/run_project_step.py').read_text()
        return module('setup').setup(runner, steps)

    def test_setup_keeps_legacy_missing_command_and_timeout_codes(self):
        missing = self.run_setup(self.setup_steps('true', 'definitely-not-a-command-orbit'))
        self.assertEqual((missing['error_code'], missing['completed'], missing['failed_command']),
                         ('instance.setup_step_unavailable', ['step-0'], 'step-1'))
        slow = self.run_setup(self.setup_steps('sleep 5', seconds=1))
        self.assertEqual(slow['error_code'], 'operation.timed_out')

    def test_setup_exports_the_account_vite_plus_store_like_the_legacy_runner(self):
        home = self.root / 'home'
        (home / '.vite-plus/bin').mkdir(parents=True)
        (home / '.vite-plus/bin/vp').write_text('')
        setup = module('setup')
        setup.pwd = type('pwd', (), {'getpwuid': staticmethod(lambda uid: type('entry', (), {'pw_dir': str(home)}))})
        steps = self.setup_steps('test "$VP_HOME" = ' + str(home / '.vite-plus'))
        self.assertTrue(setup.setup((Path(__file__).parent / 'scripts/run_project_step.py').read_text(), steps)['ready'])


if __name__ == '__main__':
    unittest.main()
