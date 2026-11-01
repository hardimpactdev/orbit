"""Legacy source cases executed against real Git, without an agent or live Node."""
import importlib.util
import os
import pwd
import subprocess
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


if __name__ == '__main__':
    unittest.main()
