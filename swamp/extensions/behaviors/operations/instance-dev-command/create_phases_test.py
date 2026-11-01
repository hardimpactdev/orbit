"""Legacy source cases executed against real Git, without an agent or live Node."""
import importlib.util
import json
import os
import pwd
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path


def module(name):
    spec = importlib.util.spec_from_file_location(name, Path(__file__).parent / 'create' / (name + '.py'))
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
                      'resolve': {'branch_override': None, 'default_branch': 'main', 'seed_commit': self.commit, 'network': False}}

    def git(self, *args, directory=None):
        return subprocess.run(['git', '-C', str(directory or self.seed), *args], capture_output=True,
                              text=True, check=True).stdout.strip()

    def create(self):
        module('prepare').prepare(self.value)
        return module('source').source(self.value, {'github_token': None})

    def test_absent_branch_starts_at_seed_without_changing_default(self):
        result = self.create()
        self.assertEqual((result['branch'], result['commit']), ('feature', self.commit))
        self.assertEqual(self.git('rev-parse', '--abbrev-ref', 'HEAD'), 'main')
        self.assertEqual(self.git('rev-parse', 'HEAD'), self.commit)
        self.assertTrue((self.target / '.git').is_file())
        self.assertFalse((self.seed / '.env').exists())

    def test_branch_in_use_refuses_before_target_effect(self):
        self.value['resolve']['branch_override'] = 'main'
        with self.assertRaisesRegex(ValueError, 'instance.branch_in_use'):
            self.create()
        self.assertFalse(self.target.exists())
        self.assertEqual(self.git('rev-parse', 'HEAD'), self.commit)

    def test_retained_source_reuses_exact_checkout_without_reset(self):
        first = self.create()
        self.value['retained_source'] = {**first, 'php_version': None, 'laravel': False}
        self.assertEqual(self.create()['commit'], self.commit)
        self.value['retained_source']['commit'] = 'a' * 40
        with self.assertRaisesRegex(ValueError, 'instance.source_identity_changed'):
            self.create()

    def test_equivalent_git_ssh_origin_preserves_seed_identity(self):
        self.git('remote', 'set-url', 'origin', 'git@github.com:example/project.git')
        result = self.create()
        self.assertEqual(result['commit'], self.commit)
        self.assertEqual(self.git('config', '--get', 'remote.origin.url'), 'git@github.com:example/project.git')

    def test_foreign_origin_refuses_before_new_worktree(self):
        self.git('remote', 'set-url', 'origin', 'https://github.com/foreign/project.git')
        module('prepare').prepare(self.value)
        with self.assertRaisesRegex(ValueError, 'instance.source_identity_invalid'):
            module('source').source(self.value, {'github_token': None})
        self.assertFalse(self.target.exists())

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

    def test_environment_refuses_unsafe_testing_file_before_replacing_environment(self):
        # Both output targets belong to this phase; validate both before either write.
        sys.path.insert(0, str(Path(__file__).parent / 'scripts'))
        (self.seed / '.env').write_text('ORIGINAL=keep\n')
        outside = self.root / 'outside'
        outside.write_text('FOREIGN=keep\n')
        (self.seed / '.env.testing').symlink_to(outside)
        with self.assertRaisesRegex(ValueError, 'env.sync_failed'):
            module('environment').environment({'directory': str(self.seed), 'content': 'NEW=value\n',
                                               'testing_content': 'NEW=testing\n', 'keys': ['NEW']})
        self.assertEqual((self.seed / '.env').read_text(), 'ORIGINAL=keep\n')
        self.assertEqual(outside.read_text(), 'FOREIGN=keep\n')

    def test_nested_application_symlink_refuses_before_environment_effect(self):
        outside = self.root / 'outside'
        (outside / 'site').mkdir(parents=True)
        (outside / 'site/.env').write_text('PRIVATE=keep\n')
        (self.seed / 'linked').symlink_to(outside, target_is_directory=True)
        self.git('add', 'linked')
        self.git('commit', '-qm', 'unsafe nested application')
        self.value['resolve']['seed_commit'] = self.git('rev-parse', 'HEAD')
        self.value['application'] = str(self.target / 'linked/site')
        with self.assertRaisesRegex(ValueError, 'env.import_invalid'):
            self.create()
        self.assertEqual((outside / 'site/.env').read_text(), 'PRIVATE=keep\n')

    def test_only_the_committed_public_template_leaves_the_source_phase(self):
        (self.seed / '.env.example').write_text('PUBLIC=" committed "\n\n')
        self.git('add', '.env.example')
        self.git('commit', '-qm', 'public template')
        self.value['resolve']['seed_commit'] = self.git('rev-parse', 'HEAD')
        (self.seed / '.env').write_text('PRIVATE=never-return-node-value\n')
        result = self.create()
        self.assertEqual(result['template'], 'PUBLIC=" committed "\n\n')
        self.assertNotIn('never-return-node-value', json.dumps(result))
        self.assertFalse((self.target / '.env').exists())


if __name__ == '__main__':
    unittest.main()
