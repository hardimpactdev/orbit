"""The idle save phase commits the changes of a workspace and fetches a bundle of its HEAD, or fails and fetches nothing."""
import importlib.util
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
PLAYBOOK = ANSIBLE / 'task-vm-idle-save.yml'
spec = importlib.util.spec_from_file_location('build_vars', ANSIBLE / 'build_vars.py')
builder = importlib.util.module_from_spec(spec)
spec.loader.exec_module(builder)
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')
GIT = {'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_AUTHOR_NAME': 'Test', 'GIT_AUTHOR_EMAIL': 'test@example.com',
       'GIT_COMMITTER_NAME': 'Test', 'GIT_COMMITTER_EMAIL': 'test@example.com'}


def git(path, *argv):
    return subprocess.run(['git', '-C', str(path), *argv], check=True, capture_output=True, text=True, env={**os.environ, **GIT}).stdout.strip()


class TaskVmIdleSaveTest(unittest.TestCase):
    def test_the_save_intent_is_reached_with_its_pinned_host_key(self):
        intent = {'checkout_path': '/home/orbit/apps/orbit', 'ssh_execution': {'identity_file': '/role/identity', 'target': {
            'host': '10.252.7.9', 'port': 22, 'user': 'orbit', 'host_keys': ['ssh-ed25519 vm']}}}
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            (folder / 'intent.json').write_text(json.dumps(intent))
            builder.build(folder, 'tasks.idle_save')
            host = json.loads((folder / 'inventory.yml').read_text())['all']['children']['task_vm']['hosts']['vm']
            self.assertEqual((host['ansible_host'], host['ansible_user'], host['ansible_ssh_private_key_file']), ('10.252.7.9', 'orbit', '/role/identity'))
            self.assertEqual((folder / 'known_hosts').read_text(), '10.252.7.9 ssh-ed25519 vm\n')

    def save(self, workspace, run_dir):
        """Run the phase on this machine against the workspace; returns the exit code and the output."""
        inventory = run_dir / 'inventory.yml'
        inventory.write_text(json.dumps({'all': {'children': {'task_vm': {'hosts': {'vm': {
            'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable}}}}}}))
        extra = {'intent': {'checkout_path': str(workspace)}, 'run_dir': str(run_dir), 'save_run': run_dir.name}
        result = subprocess.run([COMMAND, '-i', str(inventory), '-e', json.dumps(extra), str(PLAYBOOK)], check=False, capture_output=True,
                                text=True, timeout=120, env={**os.environ, 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1',
                                                             'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
        return result.returncode, result.stdout + result.stderr

    def workspace(self, root):
        workspace = root / 'workspace'
        workspace.mkdir()
        git(workspace, 'init', '--quiet', '--initial-branch=task-7')
        (workspace / 'tracked.txt').write_text('one\n')
        (workspace / '.gitignore').write_text('vendor/\n')
        git(workspace, 'add', '--all')
        git(workspace, 'commit', '--quiet', '-m', 'Base')
        return workspace

    @NEEDS_ANSIBLE
    def test_a_dirty_workspace_is_committed_and_its_head_bundled(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            workspace = self.workspace(root)
            base = git(workspace, 'rev-parse', 'HEAD')
            (workspace / 'tracked.txt').write_text('two\n')
            (workspace / 'new.txt').write_text('new\n')
            run_dir = root / 'run'
            run_dir.mkdir()
            code, output = self.save(workspace, run_dir)
            self.assertEqual(code, 0, output)
            head = git(workspace, 'rev-parse', 'HEAD')
            self.assertEqual(git(workspace, 'rev-parse', 'HEAD^'), base)
            self.assertEqual(git(workspace, 'log', '-1', '--format=%an %s'), 'Orbit Save the idle workspace')
            self.assertEqual(git(workspace, 'status', '--porcelain'), '')
            self.assertIn(head, git(run_dir, 'bundle', 'list-heads', str(run_dir / 'save.bundle')))
            self.assertEqual(git(workspace, 'show', 'HEAD:new.txt'), 'new')
            self.assertFalse(Path(f'/tmp/orbit-task-idle-save-{run_dir.name}.bundle').exists())

    @NEEDS_ANSIBLE
    def test_a_clean_workspace_keeps_its_head_and_makes_no_commit(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            workspace = self.workspace(root)
            base = git(workspace, 'rev-parse', 'HEAD')
            run_dir = root / 'run'
            run_dir.mkdir()
            code, output = self.save(workspace, run_dir)
            self.assertEqual(code, 0, output)
            self.assertEqual(git(workspace, 'rev-parse', 'HEAD'), base)
            self.assertIn(base, git(run_dir, 'bundle', 'list-heads', str(run_dir / 'save.bundle')))

    @NEEDS_ANSIBLE
    def test_a_workspace_that_stays_dirty_after_the_commit_fails_the_save_and_fetches_nothing(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            workspace = self.workspace(root)
            # A nested repository is committed as a gitlink, so its own uncommitted change keeps the workspace dirty.
            nested = workspace / 'nested'
            nested.mkdir()
            git(nested, 'init', '--quiet')
            (nested / 'inner.txt').write_text('one\n')
            git(nested, 'add', '--all')
            git(nested, 'commit', '--quiet', '-m', 'Inner')
            (nested / 'inner.txt').write_text('two\n')
            run_dir = root / 'run'
            run_dir.mkdir()
            code, output = self.save(workspace, run_dir)
            self.assertNotEqual(code, 0, output)
            self.assertIn('Check the workspace is clean', output)
            self.assertFalse((run_dir / 'save.bundle').exists())

    @NEEDS_ANSIBLE
    def test_a_workspace_that_cannot_be_read_fails_the_save_and_fetches_nothing(self):
        with tempfile.TemporaryDirectory() as folder:
            root = Path(folder)
            run_dir = root / 'run'
            run_dir.mkdir()
            code, _output = self.save(root / 'missing', run_dir)
            self.assertNotEqual(code, 0)
            self.assertFalse((run_dir / 'save.bundle').exists())


if __name__ == '__main__':
    unittest.main()
