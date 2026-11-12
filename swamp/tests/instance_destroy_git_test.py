"""Real Git, real source-removal helper and three unprivileged Ansible phase launches."""
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
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

    def test_moved_worktree_is_found_from_recorded_path_at_admission_and_removal(self):
        self.add_worktree()
        moved = self.root / 'moved'
        self.linked.rename(moved)
        inventory = [{'id': 69, 'path': str(moved), 'source_layout': 'checkout'}]
        before = self.snapshot(self.owner, True)
        for action in ['inspect', 'remove']:
            result = self.run_helper(self.owner, action, inventory, force=True)
            self.assertEqual(result['outcome'], 'inspected' if action == 'inspect' else 'refused')
            self.assertEqual(result['data']['dependents'], [{'path': str(moved), 'instance_ids': [69]}])
            self.assertTrue(self.snapshot(self.owner, True) == before)
            self.assertEqual(self.git(moved, 'rev-parse', 'HEAD'), self.git(self.owner, 'rev-parse', 'HEAD'))

    def test_deleted_prunable_registration_is_nonblocking(self):
        self.add_worktree()
        shutil.rmtree(self.linked)
        self.assertEqual(self.run_helper(self.owner, 'inspect')['data']['dependents'], [])
        self.assertEqual(self.run_helper(self.owner)['outcome'], 'removed')

    def test_checkout_contents_are_never_walked_during_inspection(self):
        dependency = self.owner / 'node_modules'
        dependency.mkdir()
        (dependency / 'irrelevant').write_bytes(b'fixture')
        dependency.chmod(0)
        try:
            self.assertEqual(self.run_helper(self.owner, 'inspect'),
                             {'outcome': 'inspected', 'data': {'dependents': []}})
            self.add_worktree()
            self.assertEqual(self.run_helper(self.owner)['data']['error_code'], 'instance.worktree_dependents')
        finally:
            dependency.chmod(0o700)

    def test_broken_recorded_pointer_into_target_is_a_named_dependent(self):
        other = self.root / 'other'
        self.git(self.root, 'clone', '-q', str(self.owner), str(other))
        shutil.rmtree(other / '.git')
        (other / '.git').write_text('gitdir: ../owner/.git/worktrees/missing\n')
        inventory = [{'id': 69, 'path': str(other)}]
        original = self.snapshot(self.owner, True)
        for action in ['inspect', 'remove']:
            result = self.run_helper(self.owner, action, inventory)
            self.assertEqual(result['data']['dependents'], [{'path': str(other), 'instance_ids': [69]}])
            if action == 'remove':
                self.assertEqual(result['data']['error_code'], 'instance.worktree_dependents')
            self.assertTrue(self.snapshot(self.owner, True) == original)

    def test_broken_recorded_pointer_elsewhere_does_not_block_unrelated_removal(self):
        other = self.root / 'other'
        self.git(self.root, 'clone', '-q', str(self.owner), str(other))
        shutil.rmtree(other / '.git')
        (other / '.git').write_text('gitdir: ../gone/.git/worktrees/missing\n')
        inventory = [{'id': 69, 'path': str(other)}]
        self.assertEqual(self.run_helper(self.owner, 'inspect', inventory), {'outcome': 'inspected', 'data': {'dependents': []}})
        self.assertTrue((self.owner / '.git').is_dir())
        self.assertEqual(self.run_helper(self.owner, 'remove', inventory), {'outcome': 'removed', 'data': {}})
        self.assertFalse(self.owner.exists())

    def test_missing_recorded_folder_warns_at_inspection_and_removal(self):
        path = str(self.root / 'missing')
        inventory = [{'id': 69, 'path': path}]
        warnings = [{'code': 'instance.checkout_unknown', 'path': path, 'cause': 'unreadable', 'instance_ids': [69]}]
        self.assertEqual(self.run_helper(self.owner, 'inspect', inventory),
                         {'outcome': 'inspected', 'data': {'dependents': [], 'warnings': warnings}})
        self.assertTrue((self.owner / '.git').is_dir())
        self.assertEqual(self.run_helper(self.owner, 'remove', inventory), {'outcome': 'removed', 'data': {'warnings': warnings}})

    def test_unrelated_fifo_config_times_out_then_own_store_proves_it_unrelated(self):
        inventory = []
        for index in range(4):
            other = self.root / f'other-{index}'
            self.git(self.root, 'clone', '-q', str(self.owner), str(other))
            (other / '.git/config').unlink()
            os.mkfifo(other / '.git/config')
            inventory.append({'id': 69 + index, 'path': str(other)})
        for action in ['inspect', 'remove']:
            start = time.monotonic()
            result = self.run_helper(self.owner, action, inventory)
            self.assertGreater(time.monotonic() - start, 2.8)
            self.assertLess(time.monotonic() - start, 7)
            self.assertEqual(result, {'outcome': 'inspected', 'data': {'dependents': []}} if action == 'inspect'
                             else {'outcome': 'removed', 'data': {}})
            if action == 'inspect':
                self.assertTrue((self.owner / '.git').is_dir())

    def test_unreadable_moved_pointer_can_hide_a_dependency_and_refuses_with_identity(self):
        self.add_worktree()
        moved = self.root / 'moved'
        self.linked.rename(moved)
        inventory = [{'id': 69, 'path': str(moved)}]
        marker = moved / '.git'
        marker.chmod(0)
        original = self.snapshot(self.owner, True)
        try:
            for action in ['inspect', 'remove']:
                result = self.run_helper(self.owner, action, inventory)
                self.assertEqual(result, {'outcome': 'refused', 'data': {'error_code': 'instance.remove_refused',
                    'path': str(moved), 'cause': 'unreadable', 'instance_ids': [69]}})
                self.assertTrue(self.snapshot(self.owner, True) == original)
        finally:
            marker.chmod(0o644)
        self.git(moved, 'status', '--porcelain')

    def test_own_repository_with_alternates_into_target_and_symlinked_paths_refuses(self):
        other = self.root / 'other'
        alias = self.root / 'alias'
        self.git(self.root, 'clone', '-q', '--shared', str(self.owner), str(other))
        alias.symlink_to(other, target_is_directory=True)
        for action in ['inspect', 'remove']:
            result = self.run_helper(self.owner, action, [{'id': 69, 'path': str(alias)}])
            self.assertEqual(result['data']['dependents'], [{'path': str(alias), 'instance_ids': [69]}])
            self.git(other, 'status', '--porcelain')
            self.assertTrue((self.owner / '.git').is_dir())

    def test_owner_registry_refuses_existing_broken_dependent_without_reading_its_git(self):
        self.add_worktree()
        (self.linked / '.git').write_text('gitdir: ../missing-elsewhere\n')
        for action in ['inspect', 'remove']:
            result = self.run_helper(self.owner, action)
            self.assertEqual(result['data']['dependents'], [{'path': str(self.linked), 'instance_ids': []}])
            self.assertTrue((self.owner / '.git').is_dir())

    def test_commondir_relative_pointer_into_target_survives_a_git_error(self):
        self.add_worktree()
        marker = self.linked / '.git'
        directory = Path(marker.read_text().removeprefix('gitdir: ').strip())
        external = self.root / 'external-gitdir'
        directory.rename(external)
        marker.write_text('gitdir: ' + str(external) + '\n')
        (external / 'commondir').write_text(os.path.relpath(self.owner / '.git', external) + '\n')
        (external / 'HEAD').write_text('invalid-head')
        for action in ['inspect', 'remove']:
            result = self.run_helper(self.owner, action, [{'id': 69, 'path': str(self.linked)}])
            self.assertEqual(result['data']['dependents'], [{'path': str(self.linked), 'instance_ids': [69]}])
            self.assertTrue((self.owner / '.git').is_dir())

    def test_non_git_pointer_reader_rejects_fifo_and_oversized_regular_files_without_blocking(self):
        import runpy
        read = runpy.run_path(str(PROGRAM))['pointer_text']
        fifo = self.root / 'pointer-fifo'
        os.mkfifo(fifo)
        started = time.monotonic()
        with self.assertRaises(ValueError):
            read(fifo)
        self.assertLess(time.monotonic() - started, 0.5)
        large = self.root / 'large-pointer'
        large.write_bytes(b'x' * 4097)
        with self.assertRaises(ValueError):
            read(large)

    def test_target_own_git_timeout_keeps_refusal_with_its_path_and_cause(self):
        config = self.owner / '.git/config'
        config.unlink()
        os.mkfifo(config)
        for action in ['inspect', 'remove']:
            result = self.run_helper(self.owner, action)
            self.assertEqual(result, {'outcome': 'refused', 'data': {'error_code': 'instance.remove_refused',
                'path': str(self.owner), 'cause': 'timed_out', 'instance_ids': []}})
            self.assertTrue((self.owner / '.git').is_dir())

    def test_inaccessible_container_style_inventory_warns_without_blocking_host_removal(self):
        private = self.root / 'container-private'
        workspace = private / 'rootfs/srv/workspace'
        workspace.mkdir(parents=True)
        private.chmod(0)
        try:
            inventory = [{'id': 2103, 'path': str(self.owner)}, {'id': 2078, 'path': str(workspace)}]
            warnings = [{'code': 'instance.checkout_unknown', 'path': str(workspace), 'cause': 'unreadable', 'instance_ids': [2078]}]
            self.assertEqual(self.run_helper(self.owner, 'inspect', inventory),
                {'outcome': 'inspected', 'data': {'dependents': [], 'warnings': warnings}})
            self.assertTrue((self.owner / '.git').is_dir())
            self.assertEqual(self.run_helper(self.owner, 'remove', inventory), {'outcome': 'removed', 'data': {'warnings': warnings}})
            self.assertFalse(self.owner.exists())
        finally:
            private.chmod(0o700)

    def test_real_ansible_phase_refuses_owner_removes_dependent_then_owner(self):
        import yaml
        self.add_worktree()
        shutil.copytree(ROOT / 'swamp/ansible/instance', self.root / 'instance')
        # Move without git repair so the real removal phase must use the admitted inventory.
        moved = self.root / 'moved'
        self.linked.rename(moved)
        self.linked = moved
        wrapper = yaml.safe_load((ROOT / 'swamp/ansible/instance-checkout-destroy.yml').read_text())[1]
        wrapper.update({'hosts': 'fixture', 'connection': 'local'})
        wrapper['vars'] = {'ansible_python_interpreter': sys.executable, 'run': str(self.root), 'intent': {'source_paths': [str(self.owner)], 'checkout_instances': [{'id': 69, 'path': str(self.linked)}]}}
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
                # Git refuses unregistering a moved tree until its registration is repaired.
                self.git(self.owner, 'worktree', 'repair', str(self.linked))
            else:
                self.assertFalse(target.exists())


if __name__ == '__main__':
    unittest.main()
