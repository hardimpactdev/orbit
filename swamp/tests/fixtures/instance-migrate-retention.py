"""Run the shipped retention command against real Git repositories, without needing Ansible or Deno."""
import hashlib
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

TASKS = Path(__file__).resolve().parents[2] / 'ansible/tasks/instance-migrate-retire.yml'
TASK_LIST = yaml.safe_load(TASKS.read_text())
COMMAND = next(task['ansible.builtin.command']['argv'][2] for task in TASK_LIST
               if task['name'] == 'Retain the checkout without deleting any files')
PREPARE = next(task['ansible.builtin.command']['argv'][2] for task in TASK_LIST
               if task['name'] == 'Prepare the retained Git path before moving any checkout')
GIT_ENV = {**os.environ, 'GIT_AUTHOR_NAME': 'Orbit', 'GIT_AUTHOR_EMAIL': 'orbit@example.test',
           'GIT_COMMITTER_NAME': 'Orbit', 'GIT_COMMITTER_EMAIL': 'orbit@example.test'}


def git(folder, *args):
    return subprocess.run(['git', '-c', 'protocol.file.allow=always', *args], cwd=folder, env=GIT_ENV,
                          check=True, capture_output=True, text=True).stdout.strip()


def identity(source):
    private = Path(git(source, 'rev-parse', '--absolute-git-dir')).resolve()
    common = Path(git(source, 'rev-parse', '--path-format=absolute', '--git-common-dir')).resolve()
    return {'git_dir': str(private), 'common_dir': str(common), 'linked': private != common}


def snapshot(folder, exclude=()):
    return {str(path.relative_to(folder)): path.read_bytes()
            for path in folder.rglob('*') if path.is_file() and not any(path == item or item in path.parents for item in exclude)}


def repository(folder):
    folder.mkdir()
    git(folder, 'init', '--quiet', '--initial-branch=main')
    (folder / '.gitignore').write_text('ignored/\n')
    (folder / 'content.txt').write_text('committed content\n')
    git(folder, 'add', '.')
    git(folder, 'commit', '--quiet', '-m', 'initial')
    git(folder, 'update-ref', 'refs/remotes/origin/main', 'HEAD')


class RetentionTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.source = self.folder / 'checkout'
        self.root = Path(str(self.source) + '.orbit-retired-git')
        self.retained = self.root / hashlib.sha256(os.fsencode(self.source)).hexdigest()
        repository(self.source)

    def retire(self, eligible=True):
        result = subprocess.run([sys.executable, '-c', PREPARE, str(self.root), json.dumps(eligible)],
                                check=True, capture_output=True, text=True)
        receipt = json.loads(result.stdout)
        if eligible:
            # Production writes this prepared receipt to the worker before it executes the rename.
            self.assertTrue(self.source.exists())
            subprocess.run([sys.executable, '-c', COMMAND, str(self.source), str(self.root), json.dumps(identity(self.source))], check=True, capture_output=True, text=True)
        self.assertEqual(receipt['retained_git_path'], str(self.root))
        self.assertRegex(receipt['retained_git_at'], r'^\d{4}-\d{2}-\d{2}T')
        self.assertFalse(self.source.exists())
        return receipt

    def test_linked_retirement_keeps_all_files_and_independent_recovery_without_changing_owner_or_sibling(self):
        owner = self.source
        child, sibling = self.folder / 'child', self.folder / 'sibling'
        git(owner, 'worktree', 'add', '--quiet', '-b', 'child', str(child))
        git(owner, 'worktree', 'add', '--quiet', '-b', 'sibling', str(sibling))
        # Recovery must preserve objects referenced only by the child's HEAD reflog, plus per-worktree refs/config.
        git(child, 'commit', '--quiet', '--allow-empty', '-m', 'reflog only')
        reflog_commit = git(child, 'rev-parse', 'HEAD')
        git(child, 'reset', '--hard', 'HEAD~1')
        git(owner, 'config', 'extensions.worktreeConfig', 'true')
        git(child, 'config', '--worktree', 'core.worktree', str(child))
        git(child, 'update-ref', 'refs/worktree/recovery', reflog_commit)
        (owner / '.git/info/exclude').write_text('.env\nstorage/\nvendor/\nnode_modules/\n')
        (child / 'untracked.txt').write_text('keep untracked')
        for name in ('.env', 'storage/cache', 'vendor/package', 'node_modules/package'):
            path = child / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text('keep ignored ' + name)
        child_private = Path(identity(child)['git_dir'])
        index = (child_private / 'index').read_bytes()
        private_head_log = (child_private / 'logs/HEAD').read_bytes()
        original_files = snapshot(child, (child / '.git',))
        before = snapshot(owner, (child_private,))
        sibling_before = snapshot(sibling)
        self.source = child
        self.root = Path(str(child) + '.orbit-retired-git')
        self.retained = self.root / hashlib.sha256(os.fsencode(child)).hexdigest()

        self.retire()

        self.assertEqual(snapshot(owner), before)
        self.assertEqual(snapshot(sibling), sibling_before)
        self.assertFalse(child_private.exists())
        listing = git(owner, 'worktree', 'list', '--porcelain')
        self.assertNotIn(str(child), listing)
        self.assertIn(str(sibling), listing)
        self.assertEqual(snapshot(self.retained, (self.retained / '.git',)), original_files)
        self.assertEqual((self.retained / '.git/index').read_bytes(), index)
        self.assertEqual((self.retained / '.git/logs/HEAD').read_bytes(), private_head_log)
        self.assertFalse((self.retained / '.git/objects/info/alternates').exists())
        self.assertFalse((self.retained / '.git/commondir').exists())
        # Prove the recovery tree needs neither the owner's store nor its path.
        owner.rename(self.folder / 'owner-offline')
        self.assertEqual(git(self.retained, 'symbolic-ref', '--short', 'HEAD'), 'child')
        self.assertEqual(git(self.retained, 'show', reflog_commit + ':content.txt'), 'committed content')
        self.assertEqual(git(self.retained, 'rev-parse', 'refs/worktree/recovery'), reflog_commit)
        self.assertEqual(git(self.retained, 'ls-files'), '.gitignore\ncontent.txt')
        self.assertEqual(git(self.retained, 'status', '--porcelain'), '?? untracked.txt')
        git(self.retained, 'fsck', '--full')

    def test_failed_git_install_keeps_files_and_verified_recovery_before_unregistering(self):
        owner = self.source
        child = self.folder / 'child'
        git(owner, 'worktree', 'add', '--quiet', '-b', 'child', str(child))
        (child / 'untracked.txt').write_text('keep through failure')
        expected = identity(child)
        self.root.mkdir()
        before = snapshot(owner)
        interrupted = COMMAND.replace("recovery.rename(destination / '.git')", "raise OSError('fixture: Git install interrupted')")

        result = subprocess.run([sys.executable, '-c', interrupted, str(child), str(self.root), json.dumps(expected)],
                                check=False, capture_output=True, text=True)

        self.assertNotEqual(result.returncode, 0)
        retained = self.root / hashlib.sha256(os.fsencode(child)).hexdigest()
        self.assertEqual((retained / 'untracked.txt').read_text(), 'keep through failure')
        recovery = next(self.root.glob('.recovery-*')) / '.git'
        self.assertEqual(git(self.folder, '--git-dir=' + str(recovery), 'show', 'HEAD:content.txt'), 'committed content')
        self.assertFalse((recovery / 'objects/info/alternates').exists())
        self.assertEqual(snapshot(owner), before)
        self.assertTrue(Path(expected['git_dir']).exists())

    def test_recovery_materializes_relative_alternates(self):
        store = self.folder / 'store'
        self.source.rename(store)
        git(self.folder, 'clone', '--quiet', '--shared', str(store), str(self.source))
        objects = self.source / '.git/objects'
        (objects / 'info/alternates').write_text(os.path.relpath(store / '.git/objects', objects) + '\n')
        child = self.folder / 'child'
        git(self.source, 'worktree', 'add', '--quiet', '-b', 'child', str(child))
        self.source = child
        self.root = Path(str(child) + '.orbit-retired-git')
        self.retained = self.root / hashlib.sha256(os.fsencode(child)).hexdigest()

        self.retire()

        store.rename(self.folder / 'store-offline')
        (self.folder / 'checkout').rename(self.folder / 'owner-offline')
        self.assertEqual(git(self.retained, 'show', 'HEAD:content.txt'), 'committed content')
        self.assertFalse((self.retained / '.git/objects/info/alternates').exists())
        git(self.retained, 'fsck', '--full')

    def test_changed_linked_identity_refuses_before_rename_or_registration_removal(self):
        owner = self.source
        child, sibling = self.folder / 'child', self.folder / 'sibling'
        git(owner, 'worktree', 'add', '--quiet', '-b', 'child', str(child))
        git(owner, 'worktree', 'add', '--quiet', '-b', 'sibling', str(sibling))
        expected = identity(child)
        expected['git_dir'] = str(Path(expected['git_dir']).with_name('sibling'))
        self.root.mkdir()
        before = snapshot(self.folder, (self.root,))

        result = subprocess.run([sys.executable, '-c', COMMAND, str(child), str(self.root), json.dumps(expected)],
                                check=False, capture_output=True, text=True)

        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(snapshot(self.folder, (self.root,)), before)

    def test_tag_only_commits_and_packed_refs_are_still_present_after_retirement(self):
        tree = git(self.source, 'rev-parse', 'HEAD^{tree}')
        commit = git(self.source, 'commit-tree', tree, '-m', 'reachable only from a tag')
        git(self.source, 'tag', '--annotate', 'only-tag', commit, '-m', 'keep this tag')
        tag = git(self.source, 'rev-parse', 'only-tag')
        git(self.source, 'pack-refs', '--all')

        self.retire()

        self.assertEqual(git(self.retained, 'rev-parse', 'only-tag^{commit}'), commit)
        self.assertEqual(git(self.retained, 'rev-parse', 'only-tag'), tag)
        self.assertEqual(git(self.retained, 'show', f'{commit}:content.txt'), 'committed content')

    def test_stash_ref_with_expired_reflog_is_still_present_after_retirement(self):
        # This addition happens after preflight. The early-refusal fixture separately checks it before preflight.
        (self.source / 'stash-only.txt').write_text('only in stash\n')
        git(self.source, 'stash', 'push', '--quiet', '--include-untracked')
        stash = git(self.source, 'rev-parse', 'refs/stash')
        git(self.source, 'reflog', 'expire', '--expire=all', 'refs/stash')
        self.assertEqual(git(self.source, 'stash', 'list'), '')

        self.retire()

        self.assertEqual(git(self.retained, 'rev-parse', 'refs/stash'), stash)
        self.assertEqual(git(self.retained, 'show', f'{stash}^3:stash-only.txt'), 'only in stash')

    def test_worktree_added_after_final_listing_keeps_its_commits_and_git_metadata(self):
        listing = git(self.source, 'worktree', 'list', '--porcelain')
        late = self.folder / 'late'
        self.assertNotIn(str(late), listing)
        git(self.source, 'worktree', 'add', '--quiet', '--detach', str(late))
        git(late, 'commit', '--quiet', '--allow-empty', '-m', 'late worktree-only commit')
        commit = git(late, 'rev-parse', 'HEAD')

        self.retire()

        self.assertEqual(git(self.retained, 'cat-file', '-t', commit), 'commit')
        self.assertEqual((self.retained / '.git/worktrees/late/HEAD').read_text().strip(), commit)
        self.assertTrue((late / 'content.txt').exists())
        self.assertTrue((late / '.git').exists())
        self.assertEqual(git(self.retained, '--git-dir=.git/worktrees/late', 'show', 'HEAD:content.txt'), 'committed content')

    def test_submodules_their_worktrees_and_nested_git_directories_are_still_present(self):
        module = self.folder / 'module'
        repository(module)
        git(self.source, 'submodule', 'add', '--quiet', str(module), 'library')
        git(self.source, 'commit', '--quiet', '-am', 'submodule')
        git(self.source, 'update-ref', 'refs/remotes/origin/main', 'HEAD')
        linked = self.folder / 'module-worktree'
        git(self.source / 'library', 'worktree', 'add', '--quiet', '--detach', str(linked))
        git(linked, 'commit', '--quiet', '--allow-empty', '-m', 'module worktree-only commit')
        module_commit = git(linked, 'rev-parse', 'HEAD')
        (self.source / 'ignored').mkdir()
        nested = self.source / 'ignored/nested'
        repository(nested)
        git(nested, 'commit', '--quiet', '--allow-empty', '-m', 'nested-only commit')
        nested_commit = git(nested, 'rev-parse', 'HEAD')
        self.assertEqual(git(self.source, 'status', '--porcelain'), '')

        self.retire()

        self.assertEqual(git(self.retained, '--git-dir=.git/modules/library', 'cat-file', '-t', module_commit), 'commit')
        self.assertEqual((self.retained / '.git/modules/library/worktrees/module-worktree/HEAD').read_text().strip(), module_commit)
        self.assertEqual(git(self.retained / 'ignored/nested', 'rev-parse', 'HEAD'), nested_commit)
        self.assertTrue((self.retained / 'library/.git').exists())
        self.assertTrue((linked / '.git').exists())

    def test_repeat_reports_the_same_path_and_time_without_changing_the_retained_tree(self):
        first = self.retire()
        second = self.retire(eligible=False)
        self.assertEqual(first | {'changed': False}, second)
        self.assertEqual(git(self.retained, 'show', 'HEAD:content.txt'), 'committed content')

    def test_interrupted_preparation_reuses_its_path_and_time_before_renaming(self):
        prepared = subprocess.run([sys.executable, '-c', PREPARE, str(self.root), 'true'],
                                  check=True, capture_output=True, text=True)
        receipt = json.loads(prepared.stdout)
        self.assertTrue((self.source / '.git').is_dir())
        self.assertFalse(self.retained.exists())

        completed = self.retire()

        self.assertEqual(receipt['retained_git_path'], completed['retained_git_path'])
        self.assertEqual(receipt['retained_git_at'], completed['retained_git_at'])

    def test_an_existing_destination_is_never_replaced(self):
        self.retire()
        repository(self.source)
        result = subprocess.run([sys.executable, '-c', COMMAND, str(self.source), str(self.root), json.dumps(identity(self.source))],
                                check=False, capture_output=True, text=True)
        self.assertNotEqual(result.returncode, 0)
        self.assertTrue((self.source / '.git').is_dir())
        self.assertEqual(git(self.retained, 'show', 'HEAD:content.txt'), 'committed content')


if __name__ == '__main__':
    unittest.main()
