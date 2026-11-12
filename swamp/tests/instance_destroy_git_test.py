"""Real Git, real source-removal helper and three unprivileged Ansible phase launches."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PROGRAM = ROOT / 'swamp/ansible/instance/destroy-git.py'


class DestroyGitTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit-destroy-git-')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.owner = self.root / 'owner'
        self.linked = self.root / 'linked worktree'
        self.git(self.root, 'init', '-q', str(self.owner))
        (self.owner / 'work.txt').write_bytes(b'owner work remains')
        self.git(self.owner, 'add', '.')
        self.git(self.owner, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.test', 'commit', '-qm', 'fixture')

    def git(self, path, *args):
        return subprocess.run(['git', '-C', str(path), *args], check=True, capture_output=True,
                              env={**os.environ, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}).stdout

    def add_worktree(self):
        self.git(self.owner, 'worktree', 'add', '-q', '--detach', str(self.linked))

    def run_helper(self, path, action='remove', instances=None, **extra):
        result = subprocess.run([sys.executable, str(PROGRAM)],
                                input=json.dumps({'action': action, 'paths': [str(path)], 'instances': instances or [], **extra}).encode(),
                                capture_output=True, check=True)
        self.assertEqual(result.stderr, b'')
        return json.loads(result.stdout)

    def snapshot(self, path, registration=False):
        return {str(file.relative_to(path)): file.read_bytes() for file in path.rglob('*')
                if file.is_file() and (registration or not str(file.relative_to(path)).startswith('worktrees/'))}

    def test_owner_refuses_for_both_force_values_and_lying_layout_records(self):
        self.add_worktree()
        owner = self.snapshot(self.owner, True)
        linked = self.snapshot(self.linked, True)
        instances = [{'id': 69, 'path': str(self.linked), 'source_layout': 'checkout'}]
        for force in [False, True]:
            result = self.run_helper(self.owner, instances=instances, force=force, source_layout='checkout')
            self.assertEqual(result, {'outcome': 'refused', 'data': {'error_code': 'instance.worktree_dependents',
                             'dependents': [{'path': str(self.linked), 'instance_ids': [69]}]}})
            self.assertTrue(self.snapshot(self.owner, True) == owner)
            self.assertTrue(self.snapshot(self.linked, True) == linked)

    def test_dependent_removal_unregisters_only_it_and_preserves_owner_git_and_work(self):
        self.add_worktree()
        before = self.snapshot(self.owner / '.git')
        self.assertEqual(self.run_helper(self.linked, source_layout='checkout')['outcome'], 'removed')
        self.assertFalse(self.linked.exists())
        self.assertTrue((self.owner / '.git').is_dir())
        self.assertTrue(self.snapshot(self.owner / '.git') == before)
        self.assertEqual((self.owner / 'work.txt').read_bytes(), b'owner work remains')
        self.assertNotIn(os.fsencode(self.linked), self.git(self.owner, 'worktree', 'list', '--porcelain', '-z'))

    def test_unowned_worktree_blocks_with_its_path(self):
        self.add_worktree()
        result = self.run_helper(self.owner)
        self.assertEqual(result['data']['error_code'], 'instance.worktree_dependents')
        self.assertEqual(result['data']['dependents'], [{'path': str(self.linked), 'instance_ids': []}])
        self.assertTrue((self.owner / '.git').is_dir())
        self.assertTrue((self.linked / '.git').is_file())

    def test_standalone_checkout_and_absent_repeat_are_removed(self):
        self.assertEqual(self.run_helper(self.owner)['outcome'], 'removed')
        self.assertFalse(self.owner.exists())
        self.assertEqual(self.run_helper(self.owner)['outcome'], 'removed')
        # A separate git directory also makes .git a file, but it is not a linked worktree.
        external = self.root / 'separate-git'
        self.git(self.root, 'init', '-q', '--separate-git-dir', str(external), str(self.owner))
        self.assertEqual(self.run_helper(self.owner)['outcome'], 'removed')
        self.assertFalse(self.owner.exists())
        self.assertTrue(external.is_dir())

    def test_unreadable_git_and_failed_unregister_never_fall_back_to_rm(self):
        self.add_worktree()
        self.git(self.owner, 'worktree', 'lock', str(self.linked))
        before = self.snapshot(self.linked, True)
        self.assertEqual(self.run_helper(self.linked)['data']['error_code'], 'instance.remove_refused')
        self.assertTrue(self.snapshot(self.linked, True) == before)
        (self.linked / '.git').write_text('gitdir: /does-not-exist\n')
        self.assertEqual(self.run_helper(self.linked)['data']['error_code'], 'instance.remove_refused')
        self.assertTrue(self.linked.exists())
        self.assertTrue((self.owner / '.git').is_dir())

    def test_real_ansible_phase_refuses_owner_removes_dependent_then_owner(self):
        import yaml
        self.add_worktree()
        shutil.copytree(ROOT / 'swamp/ansible/instance', self.root / 'instance')
        wrapper = yaml.safe_load((ROOT / 'swamp/ansible/instance-checkout-destroy.yml').read_text())[1]
        wrapper.update({'hosts': 'fixture', 'connection': 'local'})
        wrapper['vars'] = {'ansible_python_interpreter': sys.executable, 'run': str(self.root), 'intent': {'source_paths': [str(self.owner)]}}
        env = {**os.environ, 'ANSIBLE_STDOUT_CALLBACK': 'default', 'ANSIBLE_COLLECTIONS_PATH': str(self.root / 'collections')}
        for target, refused in [(self.owner, True), (self.linked, False), (self.owner, False)]:
            wrapper['vars']['intent']['source_paths'] = [str(target)]
            play = self.root / 'play.yml'
            play.write_text(yaml.safe_dump([wrapper], sort_keys=False))
            result = subprocess.run(['ansible-playbook', '-i', 'fixture,', '--tags', 'remove', str(play)],
                                    capture_output=True, env=env)
            self.assertEqual(result.returncode == 0, not refused, result.stdout.decode() + result.stderr.decode())
            receipt = json.loads((self.root / 'source-result.json').read_text())
            self.assertEqual(receipt['outcome'], 'refused' if refused else 'removed')
            if refused:
                self.assertEqual(receipt['data']['error_code'], 'instance.worktree_dependents')
                self.assertTrue((self.owner / '.git').is_dir())
                self.assertTrue((self.linked / '.git').is_file())
            else:
                self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
