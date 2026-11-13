"""Scope retirement guards with real Git stores through the shipped Ansible tasks."""
import json
import os
import runpy
import shlex
import shutil
import subprocess
import sys
import tempfile
import time
import types
import unittest
from pathlib import Path
from unittest.mock import patch

import yaml

ROOT = Path(__file__).resolve().parents[2]
HELPERS = runpy.run_path(str(ROOT / "swamp/tests/fixtures/instance-migrate-retention.py"))
git, repository, snapshot = (HELPERS[name] for name in ("git", "repository", "snapshot"))


@unittest.skipUnless(shutil.which("ansible-playbook"), "needs pinned Ansible")
class RetirementScopeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="orbit-retirement-scope-")
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name)
        self.owner, self.child = self.root / "owner", self.root / "child"
        repository(self.owner)
        remote = self.root / "origin.git"
        git(self.root, "init", "--bare", "--quiet", str(remote))
        git(self.owner, "remote", "add", "origin", str(remote))
        git(self.owner, "push", "--quiet", "--set-upstream", "origin", "main")
        git(self.owner, "worktree", "add", "--quiet", "-b", "child", str(self.child))
        self.private = Path(git(self.child, "rev-parse", "--absolute-git-dir"))
        tree = git(self.owner, "rev-parse", "HEAD^{tree}")
        self.other = git(self.owner, "commit-tree", tree, "-p", "HEAD", "-m", "other local-only branch")
        git(self.owner, "update-ref", "refs/heads/other-local", self.other)
        for number in range(2):
            checkout = self.owner if number == 0 else self.child
            (checkout / "content.txt").write_text(f"shared stash {number}\\n")
            git(checkout, "stash", "push", "--quiet", "-m", f"shared-{number}")
        self.stash = git(self.owner, "rev-parse", "refs/stash")
        self.sequence = 0

    def check(self, target, retire=False, expected=None):
        self.sequence += 1
        run = self.root / f"run-{self.sequence}"
        run.mkdir()
        result_file = run / "result.json"
        tasks = ROOT / "swamp/ansible/tasks"
        include = [{"ansible.builtin.include_tasks": str(tasks / "instance-migrate-retirement-check.yml")}]
        helper = ROOT / "swamp/ansible/instance_migrate_git.py"
        shutil.copy2(helper, run / helper.name)
        if retire:
            runpy.run_path(str(helper))["export"](target, run / "source", git(target, "branch", "--show-current"))
        if retire:
            include.append({"ansible.builtin.include_tasks": str(tasks / "instance-migrate-retire.yml")})
        record = {"ansible.builtin.copy": {
            "dest": str(result_file), "mode": "0600",
            "content": "{{ {'error_code': migrate_refusal_code | default(none), "
                       "'message': migrate_refusal_message | default('')} | to_json }}"}}
        play = [{"hosts": "localhost", "connection": "local", "gather_facts": False,
                 "vars": {"ansible_python_interpreter": sys.executable,
                          "phase": "retire" if retire else "container",
                          "node_archive": str(run / "source"),
                          "retired": {"path": str(target)}, "migrate": {"checkout": str(target)}},
                 "tasks": [{"block": include, "rescue": [
                     record, {"ansible.builtin.fail": {"msg": "fixture records the production refusal"}}]}, record]}]
        playbook = run / "play.yml"
        playbook.write_text(yaml.safe_dump(play, sort_keys=False))
        before_owner, before_child = snapshot(self.owner), snapshot(self.child) if self.child.exists() else {}
        started = time.monotonic()
        done = subprocess.run(
            ["ansible-playbook", "-i", "localhost,", str(playbook)],
            env={**os.environ, "ORBIT_RUN": str(run), "ANSIBLE_HOME": str(run / "ansible"),
                 "ANSIBLE_FORKS": "1", "ANSIBLE_NOCOLOR": "1", "GIT_OPTIONAL_LOCKS": "0"},
            check=False, capture_output=True, text=True)
        self.assertTrue(result_file.exists(), done.stdout + done.stderr)
        result = json.loads(result_file.read_text())
        self.assertEqual(result["error_code"], expected, done.stdout + done.stderr)
        self.assertEqual(done.returncode == 0, expected is None, done.stdout + done.stderr)
        if expected is not None or not retire:
            self.assertEqual(snapshot(self.owner), before_owner)
            self.assertEqual(snapshot(self.child) if self.child.exists() else {}, before_child)
        print(f"{self.id()} phase {self.sequence}: {time.monotonic() - started:.3f}s", flush=True)
        return result

    def test_linked_child_passes_preflight_and_retirement_with_other_branch_and_shared_stashes(self):
        before = snapshot(self.owner, (self.private,))
        self.check(self.child, retire=True)
        self.assertFalse(self.child.exists())
        self.assertFalse(self.private.exists())
        self.assertEqual(snapshot(self.owner), before)
        self.assertEqual(git(self.owner, "rev-parse", "refs/heads/other-local"), self.other)
        self.assertEqual(git(self.owner, "rev-parse", "refs/stash"), self.stash)
        retained = next(Path(str(self.child) + ".orbit-retired-git").glob("*/.git")).parent
        self.assertEqual(git(retained, "symbolic-ref", "--short", "HEAD"), "child")
        git(retained, "fsck", "--full")

    def test_child_own_unpushed_branch_passes_without_changing_shared_git_state(self):
        self.check(self.child)
        git(self.child, "commit", "--quiet", "--allow-empty", "-m", "child-only commit")
        self.check(self.child)
        self.assertEqual(git(self.owner, "rev-parse", "refs/heads/other-local"), self.other)
        self.assertEqual(git(self.owner, "rev-parse", "refs/stash"), self.stash)

    def test_owner_keeps_branches_and_shared_stashes_after_the_child_leaves(self):
        self.check(self.child, retire=True)
        self.check(self.owner, retire=True)
        self.assertFalse(self.owner.exists())
        retained = next(Path(str(self.owner) + ".orbit-retired-git").glob("*/.git")).parent
        self.assertEqual(git(retained, "rev-parse", "refs/heads/other-local"), self.other)
        self.assertEqual(git(retained, "rev-parse", "refs/stash"), self.stash)
        git(retained, "fsck", "--full")

    def test_owner_with_another_worktree_still_refuses_and_keeps_everything(self):
        result = self.check(self.owner, expected="instance.migrate_retirement_blocked")
        self.assertIn(str(self.child), result["message"])
        self.assertIn("All data stays", result["message"])

    def test_a_linked_checkout_still_refuses_changed_submodule_metadata(self):
        (self.child / ".gitmodules").write_text("[submodule \"child\"]\n")
        git(self.child, "add", ".gitmodules")
        result = self.check(self.child, expected="instance.migrate_retirement_blocked")
        self.assertIn(".gitmodules", result["message"])

    def test_intent_to_add_refuses_the_real_retirement_preflight(self):
        (self.child / "intent").write_text("not staged")
        git(self.child, "add", "-N", "intent")
        result = self.check(self.child, expected="instance.migrate_git_intent_to_add")
        self.assertIn("intent-to-add", result["message"])
        self.assertIn("All data stays", result["message"])

    def test_a_clean_filter_refuses_the_real_retirement_preflight(self):
        (self.child / ".gitattributes").write_text("content.txt filter=fixture\n")
        git(self.child, "config", "filter.fixture.clean", "false")
        result = self.check(self.child, expected="instance.migrate_git_filter_unsupported")
        self.assertIn("clean/smudge", result["message"])
        self.assertIn("All data stays", result["message"])


class MigrationGitStateTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.source = self.folder / 'source'
        self.target = self.folder / 'target'
        self.source.mkdir()
        self.environment = {**os.environ, 'GIT_OPTIONAL_LOCKS': '0', 'GIT_AUTHOR_NAME': 'Source actor',
                            'GIT_AUTHOR_EMAIL': 'source@example.test', 'GIT_COMMITTER_NAME': 'Source actor',
                            'GIT_COMMITTER_EMAIL': 'source@example.test'}
        self.command(self.source, 'init', '--quiet', '--initial-branch=main')
        (self.source / '.gitignore').write_text('ignored/\n')
        for name in ['file', 'remove', 'rename', 'staged']:
            (self.source / name).write_text('base ' + name)
        self.command(self.source, 'add', '.')
        self.command(self.source, 'commit', '--quiet', '-m', 'base')
        self.command(self.folder, 'clone', '--quiet', '--no-hardlinks', str(self.source), str(self.target))
        self.state = runpy.run_path(str(ROOT / 'swamp/ansible/instance_migrate_git.py'))
        module = types.ModuleType('instance_migrate_git')
        module.__dict__.update(self.state)
        self.enterContext(patch.dict(sys.modules, {'instance_migrate_git': module}))
        self.prefix = self.folder / 'transfer'
        self.manifest = Path(str(self.prefix) + '.git.json')
        self.pack = Path(str(self.prefix) + '.git.pack')

    def command(self, root, *args, **options):
        return subprocess.run(['git', '-c', 'protocol.file.allow=always', '-C', str(root), *args],
                              env=self.environment, capture_output=True, check=True, **options).stdout

    def metadata(self):
        directories = {self.command(self.source, 'rev-parse', '--absolute-git-dir').strip().decode(),
                       self.command(self.source, 'rev-parse', '--path-format=absolute', '--git-common-dir').strip().decode()}
        return {str(path): path.read_bytes() for directory in directories
                for path in Path(directory).rglob('*') if path.is_file()}

    def overlay(self):
        prefix = str(self.folder / 'overlay')
        runpy.run_path(str(ROOT / 'swamp/ansible/instance_migrate_links.py'))['CopyLayout'](self.source).archive(prefix)
        subprocess.run(['tar', '-xf', prefix + '.tar', '-C', str(self.target)], check=True, capture_output=True)
        for name in Path(prefix + '.deleted').read_bytes().split(b'\0'):
            if name:
                (self.target / os.fsdecode(name)).unlink(missing_ok=True)

    def carry(self):
        before = self.metadata()
        self.state['export'](self.source, self.prefix, self.command(self.source, 'branch', '--show-current').strip().decode())
        self.overlay()
        self.state['check_source'](self.source, self.manifest)
        self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(self.state['snapshot'](self.source), self.state['snapshot'](self.target))
        self.state['fsck'](self.source)
        self.state['fsck'](self.target)
        self.assertEqual(self.metadata(), before)

    def stash(self, number):
        (self.source / 'file').write_text('staged ' + str(number))
        self.command(self.source, 'add', 'file')
        (self.source / 'file').write_text('unstaged ' + str(number))
        (self.source / ('untracked-' + str(number))).write_text('untracked ' + str(number))
        (self.source / 'ignored').mkdir(exist_ok=True)
        (self.source / 'ignored' / str(number)).write_text('ignored ' + str(number))
        self.environment['GIT_COMMITTER_DATE'] = f'2001-01-0{number}T12:34:56 +0230'
        self.command(self.source, 'stash', 'push', '--quiet', '--all', '-m', 'duplicate message')
        self.environment.pop('GIT_COMMITTER_DATE')

    def test_carries_divergent_local_branches_complete_stashes_and_staged_and_unstaged_changes(self):
        self.command(self.source, 'checkout', '--quiet', '-b', 'unpublished')
        (self.source / 'branch-only').write_text('unpublished branch')
        self.command(self.source, 'add', '.')
        self.command(self.source, 'commit', '--quiet', '-m', 'unpublished')
        self.command(self.source, 'checkout', '--quiet', 'main')
        self.command(self.source, 'commit', '--quiet', '--allow-empty', '-m', 'divergent local main')
        for number in [1, 2, 3]:
            self.stash(number)
        (self.source / 'file').write_text('working tree exact bytes\n')
        (self.source / 'staged').write_text('staged-only exact bytes\n')
        self.command(self.source, 'add', 'staged')
        (self.source / 'remove').unlink()
        self.command(self.source, 'mv', 'rename', 'renamed')
        (self.source / 'new-file').write_text('untracked live')
        self.command(self.source, 'config', 'private.secret', 'must not cross')
        (self.source / '.git/hooks/post-checkout').write_text('must not cross')
        self.carry()
        self.assertEqual(self.command(self.source, 'show-ref', '--heads'),
                         self.command(self.target, 'show-ref', '--heads'))
        self.assertEqual(self.command(self.source, 'stash', 'list', '--date=raw'),
                         self.command(self.target, 'stash', 'list', '--date=raw'))
        self.assertEqual(self.command(self.source, 'status', '--porcelain'),
                         self.command(self.target, 'status', '--porcelain'))
        self.assertFalse((self.target / '.git/hooks/post-checkout').exists())
        self.assertNotIn(b'private', (self.target / '.git/config').read_bytes())
        self.assertFalse((self.target / '.git/worktrees').exists())
        before = {str(path): path.read_bytes() for path in (self.target / '.git').rglob('*') if path.is_file()}
        self.assertEqual(self.state['import_state'](self.target, self.manifest, self.pack),
                         {'verified': True, 'changed': False})
        self.assertEqual(before, {str(path): path.read_bytes() for path in (self.target / '.git').rglob('*') if path.is_file()})
        self.command(self.target, 'reset', '--hard', '--quiet')
        (self.target / 'new-file').unlink()
        self.command(self.target, 'stash', 'apply', '--index', 'stash@{1}')
        self.assertEqual((self.target / 'file').read_text(), 'unstaged 2')
        self.assertEqual(self.command(self.target, 'show', ':file'), b'staged 2')
        self.assertEqual((self.target / 'untracked-2').read_text(), 'untracked 2')
        self.assertEqual((self.target / 'ignored/2').read_text(), 'ignored 2')
        self.state['fsck'](self.target)

    def test_a_staged_only_deletion_keeps_the_untracked_working_file(self):
        self.command(self.source, 'rm', '--cached', 'remove')
        self.carry()
        self.assertEqual((self.target / 'remove').read_text(), 'base remove')
        self.assertEqual(self.command(self.target, 'ls-files', '--', 'remove'), b'')

    def test_surviving_stash_ref_with_expired_reflog_keeps_its_objects_without_inventing_entries(self):
        self.stash(1)
        oid = self.command(self.source, 'rev-parse', 'refs/stash').strip()
        self.command(self.source, 'reflog', 'expire', '--expire=all', 'refs/stash')
        self.carry()
        self.assertEqual(self.command(self.target, 'stash', 'list'), b'')
        self.command(self.target, 'stash', 'apply', '--index', oid.decode())
        self.assertEqual((self.target / 'untracked-1').read_text(), 'untracked 1')

    def test_materializes_alternate_objects_and_keeps_a_linked_worktree_independent(self):
        owner = self.source
        alternate = self.folder / 'alternate'
        self.command(self.folder, 'clone', '--quiet', '--shared', str(owner), str(alternate))
        self.source = alternate
        linked = self.folder / 'linked'
        self.command(alternate, 'worktree', 'add', '--quiet', '-b', 'linked', str(linked))
        self.source = linked
        (linked / 'staged').write_text('linked staged')
        self.command(linked, 'add', 'staged')
        self.carry()
        self.assertFalse((self.target / '.git/objects/info/alternates').exists())
        self.assertFalse((self.target / '.git/worktrees').exists())
        shutil.rmtree(owner)
        self.state['fsck'](self.target)
        self.assertEqual(self.command(self.target, 'show', ':staged'), b'linked staged')

    def test_corrupt_pack_cannot_publish_refs_and_a_repeated_import_recovers(self):
        self.command(self.source, 'commit', '--quiet', '--allow-empty', '-m', 'unpushed')
        self.command(self.source, 'branch', 'second')
        self.state['export'](self.source, self.prefix, self.command(self.source, 'branch', '--show-current').strip().decode())
        self.overlay()
        saved = self.pack.read_bytes()
        refs = self.command(self.target, 'show-ref', '--heads')
        self.pack.write_bytes(saved[:len(saved) // 2])
        with self.assertRaises(self.state['GitStateError']):
            self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(self.command(self.target, 'show-ref', '--heads'), refs)
        self.pack.write_bytes(saved)
        self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(self.state['snapshot'](self.source), self.state['snapshot'](self.target))

    def test_branch_refs_are_atomic_when_one_expected_ref_changes(self):
        self.command(self.source, 'commit', '--quiet', '--allow-empty', '-m', 'local')
        self.command(self.source, 'branch', 'second')
        self.state['export'](self.source, self.prefix, 'main')
        self.overlay()
        previous = self.command(self.target, 'rev-parse', 'HEAD').strip().decode()
        before = self.metadata()
        original = self.state['git']
        def conflict(root, *args, **options):
            if args[:2] == ('update-ref', '--stdin'):
                original(root, 'update-ref', 'refs/heads/second', previous)
            return original(root, *args, **options)
        with patch.dict(self.state['import_state'].__globals__, {'git': conflict}), self.assertRaises(self.state['GitStateError']):
            self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(self.command(self.target, 'rev-parse', 'refs/heads/main').strip().decode(), previous)
        self.assertEqual(self.metadata(), before)
        self.command(self.target, 'update-ref', '-d', 'refs/heads/second')
        self.state['import_state'](self.target, self.manifest, self.pack)

    def test_an_invalid_checkout_root_cannot_export_its_parent_repository(self):
        child = self.source / 'not-a-checkout'
        child.mkdir()
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_retirement_blocked'):
            self.state['export'](child, self.prefix, 'main')
        self.assertFalse(self.pack.exists())

    def test_export_refuses_a_changed_selected_branch(self):
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_branch_changed'):
            self.state['export'](self.source, self.prefix, 'different')

    def test_partial_reflog_failure_is_retryable_without_source_changes(self):
        self.stash(1)
        self.state['export'](self.source, self.prefix, self.command(self.source, 'branch', '--show-current').strip().decode())
        self.overlay()
        before = self.metadata()
        original = self.state['atomic_file']
        def fail_reflog(path, body, mode=0o600):
            if str(path).endswith('logs/refs/stash'):
                raise OSError('fixture write interrupted')
            return original(path, body, mode)
        with patch.dict(self.state['import_state'].__globals__, {'atomic_file': fail_reflog}), self.assertRaises(OSError):
            self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(self.metadata(), before)
        self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(self.state['snapshot'](self.source), self.state['snapshot'](self.target))

    def test_index_flags_cannot_hide_uncopied_tracked_bytes_from_verification(self):
        self.command(self.source, 'update-index', '--assume-unchanged', 'file')
        (self.source / 'file').write_text('hidden local content')
        before = self.metadata()
        self.state['export'](self.source, self.prefix, 'main')
        self.overlay()
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_state_mismatch'):
            self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(self.metadata(), before)
        self.assertEqual((self.source / 'file').read_text(), 'hidden local content')

    def test_changed_source_and_wrong_copied_bytes_refuse_before_cutover(self):
        (self.source / 'file').write_text('exported value')
        self.state['export'](self.source, self.prefix, self.command(self.source, 'branch', '--show-current').strip().decode())
        self.overlay()
        (self.target / 'file').write_text('truncated')
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_state_mismatch'):
            self.state['import_state'](self.target, self.manifest, self.pack)
        (self.source / 'file').write_text('changed after export')
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_source_changed'):
            self.state['check_source'](self.source, self.manifest)



    def test_clean_checkout_records_cost_without_rewriting_source_or_destination(self):
        original = subprocess.run
        calls = []
        def count(argv, *args, **kwargs):
            if argv[0] in ('git', '/usr/bin/git'):
                calls.append(argv)
            return original(argv, *args, **kwargs)
        before = self.metadata()
        with patch.object(subprocess, 'run', count):
            started = time.monotonic()
            self.state['export'](self.source, self.prefix, 'main')
            self.overlay()
            self.state['check_source'](self.source, self.manifest)
            result = self.state['import_state'](self.target, self.manifest, self.pack)
            self.state['check_source'](self.source, self.manifest)
            seconds = time.monotonic() - started
        self.assertEqual(result, {'verified': True, 'changed': False})
        self.assertEqual(self.metadata(), before)
        print(f"clean Git transfer: {len(calls)} Git calls, {seconds:.3f}s", flush=True)

    def test_repository_drivers_never_run_and_filters_refuse_before_export(self):
        for kind in ('clean', 'smudge', 'textconv', 'diff', 'merge'):
            with self.subTest(kind=kind):
                marker = self.source / '.git' / ('executed-' + kind)
                program = sys.executable + ' -c ' + shlex.quote(
                    "import pathlib,sys;pathlib.Path(" + repr(str(marker)) + ").write_text('executed');"
                    "sys.stdout.buffer.write(sys.stdin.buffer.read())")
                attribute = 'filter=trap' if kind in ('clean', 'smudge') else 'merge=trap' if kind == 'merge' else 'diff=trap'
                (self.source / '.gitattributes').write_text('file ' + attribute + '\n')
                self.command(self.source, 'add', '.gitattributes')
                key = {'clean': 'filter.trap.clean', 'smudge': 'filter.trap.smudge',
                       'textconv': 'diff.trap.textconv', 'diff': 'diff.trap.command', 'merge': 'merge.trap.driver'}[kind]
                self.command(self.source, 'config', key, program)
                target_marker = self.target / '.git' / ('executed-' + kind)
                target_program = program.replace(str(marker), str(target_marker))
                self.command(self.target, 'config', key, target_program)
                (self.source / 'file').write_text('a changed working value')
                before = self.metadata()
                self.overlay()
                self.state['git'](self.source, 'diff', '--ext-diff', '--textconv', 'HEAD')
                if kind in ('clean', 'smudge'):
                    with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_filter_unsupported'):
                        self.state['export'](self.source, self.prefix, 'main')
                    self.assertFalse(self.manifest.exists())
                else:
                    self.carry()
                self.assertFalse(marker.exists())
                self.assertFalse(target_marker.exists())
                self.assertEqual(self.metadata(), before)
                self.command(self.source, 'config', '--unset', key)
                self.command(self.target, 'config', '--unset', key)

    def test_staged_removal_of_filter_attributes_cannot_bypass_preflight(self):
        attributes = self.source / '.gitattributes'
        attributes.write_text('file filter=fixture\n')
        self.command(self.source, 'add', '.gitattributes')
        self.command(self.source, 'commit', '--quiet', '-m', 'filtered base')
        attributes.write_text('')
        self.command(self.source, 'add', '.gitattributes')
        before = self.metadata()
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_filter_unsupported'):
            self.state['export'](self.source, self.prefix, 'main')
        self.assertEqual(self.metadata(), before)

    def test_every_carried_untracked_and_ignored_byte_is_verified(self):
        (self.source / 'loose').write_text('original')
        (self.source / 'ignored').mkdir()
        (self.source / 'ignored/file').write_text('original')
        for name in ('loose', 'ignored/file'):
            with self.subTest(name=name):
                self.state['export'](self.source, self.prefix, 'main')
                self.overlay()
                path = self.target / name
                metadata = path.stat()
                path.write_text('corrupt!')
                os.utime(path, ns=(metadata.st_atime_ns, metadata.st_mtime_ns))
                with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_state_mismatch'):
                    self.state['import_state'](self.target, self.manifest, self.pack)
                path.write_text('original')

    def test_source_recheck_fences_edited_new_and_deleted_ignored_files(self):
        (self.source / 'loose').write_text('original')
        (self.source / 'ignored').mkdir()
        (self.source / 'ignored/file').write_text('original')
        for kind in ('edited-untracked', 'edited-ignored', 'new-ignored', 'deleted-ignored'):
            with self.subTest(kind=kind):
                self.state['export'](self.source, self.prefix, 'main')
                if kind == 'edited-untracked':
                    (self.source / 'loose').write_text('later value')
                elif kind == 'edited-ignored':
                    (self.source / 'ignored/file').write_text('later value')
                elif kind == 'new-ignored':
                    (self.source / 'ignored/new').write_text('later value')
                else:
                    (self.source / 'ignored/file').unlink()
                with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_source_changed'):
                    self.state['check_source'](self.source, self.manifest)
                (self.source / 'loose').write_text('original')
                (self.source / 'ignored/file').write_text('original')
                (self.source / 'ignored/new').unlink(missing_ok=True)

    def test_intent_to_add_is_named_before_export_or_source_writes(self):
        (self.source / 'intent').write_text('not staged')
        self.command(self.source, 'add', '-N', 'intent')
        before = self.metadata()
        self.assertEqual(self.state['guards'](self.source)['intent_to_add'], ['intent'])
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_intent_to_add'):
            self.state['export'](self.source, self.prefix, 'main')
        self.assertFalse(self.manifest.exists())
        self.assertFalse(self.pack.exists())
        self.assertEqual(self.metadata(), before)

    def test_wrong_staged_blob_with_identical_status_is_rejected(self):
        (self.source / 'staged').write_text('right staged value')
        self.command(self.source, 'add', 'staged')
        (self.source / 'staged').write_text('working value')
        self.state['export'](self.source, self.prefix, 'main')
        self.overlay()
        original = self.state['snapshot']
        calls = 0
        def corrupt_index(root, **options):
            nonlocal calls
            if root == self.target:
                calls += 1
                if calls == 2:
                    wrong = self.command(self.target, 'hash-object', '-w', '--stdin', input=b'wrong staged value').strip().decode()
                    self.command(self.target, 'update-index', '--cacheinfo', '100644', wrong, 'staged')
                    self.assertEqual(self.command(self.source, 'status', '--porcelain'),
                                     self.command(self.target, 'status', '--porcelain'))
            return original(root, **options)
        with (
            patch.dict(self.state['import_state'].__globals__, {'snapshot': corrupt_index}),
            self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_state_mismatch'),
        ):
            self.state['import_state'](self.target, self.manifest, self.pack)
        self.assertEqual(calls, 2)

    def test_fsck_roots_include_older_stash_reflog_entries(self):
        self.stash(1)
        old = self.command(self.source, 'rev-parse', 'refs/stash').strip().decode()
        self.stash(2)
        self.carry()
        unpacked = self.folder / 'unpacked-stashes'
        unpacked.mkdir()
        subprocess.run(['git', '-C', str(self.target), 'unpack-objects'], input=self.pack.read_bytes(),
                       env={**self.environment, 'GIT_OBJECT_DIRECTORY': str(unpacked)}, check=True, capture_output=True)
        objects = self.target / '.git/objects'
        shutil.copytree(unpacked, objects, dirs_exist_ok=True)
        for path in (objects / 'pack').iterdir():
            path.unlink()
        (objects / old[:2] / old[2:]).unlink()
        self.assertEqual(self.state['snapshot'](self.source), self.state['snapshot'](self.target))
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_objects_incomplete'):
            self.state['import_state'](self.target, self.manifest, self.pack)

    def test_missing_off_branch_tree_requires_fsck_even_on_identical_snapshot(self):
        self.command(self.source, 'checkout', '--quiet', '-b', 'off-main')
        (self.source / 'off-main').write_text('only on another local branch')
        self.command(self.source, 'add', '.')
        self.command(self.source, 'commit', '--quiet', '-m', 'off main')
        tree = self.command(self.source, 'rev-parse', 'HEAD^{tree}').strip().decode()
        self.command(self.source, 'checkout', '--quiet', 'main')
        self.carry()
        unpacked = self.folder / 'unpacked'
        unpacked.mkdir()
        subprocess.run(['git', '-C', str(self.target), 'unpack-objects'], input=self.pack.read_bytes(),
                       env={**self.environment, 'GIT_OBJECT_DIRECTORY': str(unpacked)}, check=True, capture_output=True)
        objects = self.target / '.git/objects'
        shutil.copytree(unpacked, objects, dirs_exist_ok=True)
        for path in (objects / 'pack').iterdir():
            path.unlink()
        (objects / tree[:2] / tree[2:]).unlink()
        self.assertEqual(self.state['snapshot'](self.source), self.state['snapshot'](self.target))
        with self.assertRaisesRegex(self.state['GitStateError'], 'instance.migrate_git_objects_incomplete'):
            self.state['import_state'](self.target, self.manifest, self.pack)


if __name__ == "__main__":
    unittest.main()
