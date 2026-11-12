"""Read-only admission guard with real Git and unprivileged filesystem failures. No playbook launches."""
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
        self.linked = self.root / 'linked'
        self.git(self.root, 'init', '-q', str(self.owner))
        (self.owner / 'work').write_bytes(b'preserve owner work')
        self.git(self.owner, 'add', '.')
        self.git(self.owner, '-c', 'user.name=Fixture', '-c', 'user.email=f@example.test', 'commit', '-qm', 'fixture')

    def git(self, path, *args):
        return subprocess.run(['git', '-C', str(path), *args], capture_output=True, check=True,
                              env={**os.environ, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'}).stdout

    def read(self, instances=None, target=None, **extra):
        done = subprocess.run([sys.executable, str(PROGRAM)], check=True, capture_output=True,
            input=json.dumps({'action': 'inspect', 'paths': [str(target or self.owner)], 'instances': instances or [], **extra}).encode())
        self.assertEqual(done.stderr, b'')
        return json.loads(done.stdout)

    def snapshot(self):
        return {str(p.relative_to(self.owner)): p.read_bytes() for p in self.owner.rglob('*') if p.is_file()}

    def warning(self, candidate, identity=69, cause='unreadable'):
        return {'code': 'instance.checkout_unknown', 'path': str(candidate), 'cause': cause, 'instance_ids': [identity]}

    def test_confirmed_registered_and_moved_dependents_are_found_even_with_force(self):
        self.git(self.owner, 'worktree', 'add', '-q', '--detach', str(self.linked))
        for moved in [False, True]:
            path = self.root / 'moved' if moved else self.linked
            if moved:
                self.linked.rename(path)
            before = self.snapshot()
            result = self.read([{'id': 69, 'path': str(path), 'source_layout': 'checkout'}], force=True)
            self.assertEqual(result, {'outcome': 'inspected', 'data': {'dependents': [{'path': str(path), 'instance_ids': [69]}]}})
            self.assertEqual(self.snapshot(), before)
            self.assertEqual(self.git(path, 'rev-parse', 'HEAD'), self.git(self.owner, 'rev-parse', 'HEAD'))

    def test_existing_owner_registration_refuses_without_reading_candidate_git(self):
        self.git(self.owner, 'worktree', 'add', '-q', '--detach', str(self.linked))
        (self.linked / '.git').write_text('gitdir: /missing-elsewhere')
        self.assertEqual(self.read()['data']['dependents'], [{'path': str(self.linked), 'instance_ids': []}])

    def test_prunable_gone_registration_and_independent_checkouts_do_not_block(self):
        self.git(self.owner, 'worktree', 'add', '-q', '--detach', str(self.linked))
        shutil.rmtree(self.linked)
        other = self.root / 'other'
        self.git(self.root, 'clone', '-q', str(self.owner), str(other))
        self.assertEqual(self.read([{'id': 69, 'path': str(other)}]), {'outcome': 'inspected', 'data': {'dependents': []}})

    def test_broken_gitdir_and_commondir_into_target_are_confirmed(self):
        other = self.root / 'other'
        other.mkdir()
        marker = other / '.git'
        marker.write_text('gitdir: ../owner/.git/worktrees/missing\n')
        self.assertEqual(self.read([{'id': 69, 'path': str(other)}])['data']['dependents'], [{'path': str(other), 'instance_ids': [69]}])
        external = self.root / 'external'
        external.mkdir()
        marker.write_text('gitdir: ../external\n')
        (external / 'commondir').write_text('../owner/.git\n')
        self.assertEqual(self.read([{'id': 69, 'path': str(other)}])['data']['dependents'], [{'path': str(other), 'instance_ids': [69]}])

    def test_unreadable_candidate_shapes_are_named_unknown_and_never_veto(self):
        # Each case uses real Git and real filesystem boundaries, including non-blocking FIFO reads.
        for shape in ['gitdir-mode', 'marker-mode', 'fifo', 'loop', 'commondir', 'alternates', 'oversized', 'not-directory', 'missing', 'broken-elsewhere']:
            with self.subTest(shape=shape):
                other = self.root / shape
                self.git(self.root, 'clone', '-q', str(self.owner), str(other))
                marker = other / '.git'
                restore = None
                if shape == 'gitdir-mode':
                    marker.chmod(0)
                    restore = marker
                elif shape in ['marker-mode', 'fifo', 'loop', 'oversized', 'not-directory', 'broken-elsewhere']:
                    shutil.rmtree(marker)
                    if shape == 'marker-mode':
                        marker.write_text('gitdir: /missing\n')
                        marker.chmod(0)
                        restore = marker
                    elif shape == 'fifo':
                        os.mkfifo(marker)
                    elif shape == 'loop':
                        marker.symlink_to('.git')
                    elif shape == 'oversized':
                        marker.write_bytes(b'gitdir: ' + b'x' * 4097)
                    elif shape == 'not-directory':
                        (other / 'regular').write_text('file')
                        marker.write_text('gitdir: regular/child\n')
                    else:
                        marker.write_text('gitdir: /missing-elsewhere\n')
                elif shape in ['commondir', 'alternates']:
                    blocked = marker / ('commondir' if shape == 'commondir' else 'objects/info/alternates')
                    blocked.write_text('/missing\n')
                    blocked.chmod(0)
                    restore = blocked
                elif shape == 'missing':
                    shutil.rmtree(other)
                before = self.snapshot()
                try:
                    result = self.read([{'id': 69, 'path': str(other)}])
                    self.assertEqual(result, {'outcome': 'inspected', 'data': {'dependents': [], 'warnings': [self.warning(other)]}})
                    self.assertEqual(self.snapshot(), before)
                finally:
                    if restore:
                        restore.chmod(0o700 if restore.is_dir() else 0o644)

    def test_unreadable_parent_in_mixed_inventory_warns_including_the_moved_worktree_limit(self):
        private = self.root / 'private'
        private.mkdir()
        self.git(self.owner, 'worktree', 'add', '-q', '--detach', str(self.linked))
        moved = private / 'moved'
        self.linked.rename(moved)
        container = private / 'container/rootfs/srv/workspace'
        container.mkdir(parents=True)
        private.chmod(0)
        try:
            result = self.read([{'id': 69, 'path': str(moved)}, {'id': 70, 'path': str(container)}])
            self.assertEqual(result, {'outcome': 'inspected', 'data': {'dependents': [], 'warnings': [self.warning(moved), self.warning(container, 70)]}})
            self.assertTrue((self.owner / '.git').is_dir())
        finally:
            private.chmod(0o700)
        self.git(moved, 'status', '--porcelain')

    def test_candidate_timeouts_share_one_allowance_and_warn(self):
        inventory = []
        for index in range(4):
            other = self.root / f'fifo-{index}'
            self.git(self.root, 'clone', '-q', str(self.owner), str(other))
            (other / '.git/config').unlink()
            os.mkfifo(other / '.git/config')
            inventory.append({'id': index + 69, 'path': str(other)})
        started = time.monotonic()
        result = self.read(inventory)
        self.assertLess(time.monotonic() - started, 6)
        self.assertEqual(result, {'outcome': 'inspected', 'data': {'dependents': [], 'warnings': [self.warning(r['path'], r['id'], 'timed_out') for r in inventory]}})

    def test_malformed_rows_cannot_poison_other_candidates_and_diagnostics_are_bounded(self):
        rows = [{'id': 69, 'path': value} for value in ['', 'relative/path', '/' + 'x' * 5000]]
        rows += [{'id': 70}, None, {'path': 123}, {'id': 'untyped', 'path': '/missing'}]
        result = self.read(rows)
        self.assertEqual(result['outcome'], 'inspected')
        self.assertEqual(result['data']['dependents'], [])
        self.assertEqual(len(result['data']['warnings']), len(rows))
        for item in result['data']['warnings']:
            self.assertLessEqual(len(item['path']), 4096)
            self.assertEqual(item['cause'], 'unreadable')
        self.assertEqual(result['data']['warnings'][1], self.warning('relative/path'))
        many = self.read([{'id': 69, 'path': ''}] * 25)
        self.assertEqual(len(many['data']['warnings']), 20)
        self.assertEqual(many['data']['other_warnings'], 5)

    def test_target_directory_marker_resolve_and_git_failures_keep_path_and_cause(self):
        cases = ['directory', 'parent', 'marker', 'symlink-loop', 'fifo-config']
        for shape in cases:
            with self.subTest(shape=shape):
                target = self.root / shape
                self.git(self.root, 'clone', '-q', str(self.owner), str(target))
                restore = None
                if shape in ['directory', 'parent']:
                    if shape == 'parent':
                        child = target / 'child'
                        target.rename(self.root / 'saved')
                        target.mkdir()
                        (self.root / 'saved').rename(child)
                        restore = target
                        target = child
                    else:
                        restore = target
                    restore.chmod(0)
                elif shape == 'marker':
                    (target / '.git').chmod(0)
                    restore = target / '.git'
                elif shape == 'symlink-loop':
                    shutil.rmtree(target)
                    target.symlink_to(target.name)
                else:
                    (target / '.git/config').unlink()
                    os.mkfifo(target / '.git/config')
                try:
                    self.assertEqual(self.read(target=target), {'outcome': 'refused', 'data': {'error_code': 'instance.remove_refused', 'path': str(target),
                        'cause': 'timed_out' if shape == 'fifo-config' else 'unreadable', 'instance_ids': []}})
                finally:
                    if restore:
                        restore.chmod(0o700)

    def test_alternates_dependency_and_symlinked_candidate_are_confirmed(self):
        other = self.root / 'other'
        alias = self.root / 'alias'
        self.git(self.root, 'clone', '-q', '--shared', str(self.owner), str(other))
        alias.symlink_to(other, target_is_directory=True)
        self.assertEqual(self.read([{'id': 69, 'path': str(alias)}])['data']['dependents'], [{'path': str(alias), 'instance_ids': [69]}])

    def test_inspect_never_walks_checkout_contents_or_accepts_a_remove_action(self):
        folder = self.owner / 'node_modules'
        folder.mkdir()
        folder.chmod(0)
        try:
            self.assertEqual(self.read(), {'outcome': 'inspected', 'data': {'dependents': []}})
            self.assertEqual(self.read(action='remove')['outcome'], 'refused')
            self.assertTrue((self.owner / '.git').is_dir())
        finally:
            folder.chmod(0o700)

    def test_source_removal_stays_unchanged_and_workflow_never_checks_dependencies(self):
        import hashlib
        import yaml
        text = (ROOT / 'swamp/workflows/workflow-operation-instance-destroy.yaml').read_text()
        workflow = yaml.safe_load(text)
        source = next(step for job in workflow['jobs'] for step in job['steps'] if step['name'] == 'Remove the source')
        # Pin only the source-removal step, including its inline program and argv, from pre-reland main 6b2e7bc1.
        self.assertEqual(hashlib.sha256(json.dumps(source, sort_keys=True, separators=(',', ':')).encode()).hexdigest(), '106c0ad7578ad0c11e1e02ad41e2cec8732f185b6d01ff7ae008beef18963a51')
        for reference in ['destroy-git', 'instance-checkout-destroy', 'checkout_instances', 'worktree_dependents']:
            self.assertNotIn(reference, text)
        self.assertFalse((ROOT / 'swamp/ansible/instance-checkout-destroy.yml').exists())



if __name__ == '__main__':
    unittest.main()
