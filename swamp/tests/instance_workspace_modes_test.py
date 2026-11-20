"""The container phase's workspace pass and its Git calls in a live workspace (swamp/ansible/tasks/instance-container-phase.yml).

On 2026-11-14 the checkout of a release refresh failed: the fetch of the new commit started Git's automatic maintenance in the
background, the repack removed pack files while `find … -exec chmod g+rwX {} +` ran over the workspace, and chmod's "No such file or
directory" failed the phase. These cases run the shipped pass and the shipped Git options on a scratch repository.

On 2026-11-16 files that deploy steps made as `orbit` in a default reached its copies, where the agent, as `agent`, could not change
their modes or times: the pass now also gives each `orbit` entry to `agent` (ADR 0268).
"""

import os
import re
import socket
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

PHASE = Path(__file__).resolve().parents[1] / 'ansible/tasks/instance-container-phase.yml'
TASKS = yaml.safe_load(PHASE.read_text())
PASS = next(task for task in TASKS if task.get('name') == 'Keep the workspace writable for its group')
PROGRAM = (PHASE.parents[1] / 'instance_workspace_owner.py').read_text()
# A uid that owns no fixture file: the pass then changes modes only.
NOBODY = '4242424'
# The Git options of every Orbit Git call in the workspace: the quoted words after 'git' in `in_workspace`.
IN_WORKSPACE = next(task for task in TASKS if task.get('name') == 'Name the container commands')['ansible.builtin.set_fact']['in_workspace']
GIT_OPTIONS = re.findall(r"'([^']*)'", IN_WORKSPACE.split("'git',", 1)[1])
CHECKOUT = next(task for task in TASKS if task.get('name') == 'Check out the commit')['block']
REPACK = next(task for task in CHECKOUT if task['name'] == 'Repack the workspace')
REPACK_NOTE = next(task for task in CHECKOUT if task['name'] == 'Report a repack that did not pass')
# The repack's own words after the workspace's Git options, and the time limit before them.
REPACK_ARGV = REPACK['ansible.builtin.command']['argv']
REPACK_ARGS = re.findall(r"'([^']*)'", REPACK_ARGV[REPACK_ARGV.index("['repack'"):])

# A fault injection that runs before the shipped program: an entry named in VANISH is removed just before the pass opens it, as a
# repack removes a pack file; a file named in LOCKED refuses its mode change, as a file the pass may not change does.
INJECT = '''
import errno, os
_open, _fchmod, _vanish, _locked = os.open, os.fchmod, set(os.environ.get('VANISH', '').split(',')) - {''}, set()
for _path in os.environ.get('LOCKED', '').split(','):
    if _path:
        _locked.add(os.stat(_path).st_ino)
def _vanishing_open(name, flags, mode=0o777, *, dir_fd=None):
    if os.path.basename(name) in _vanish:
        target = os.path.join(os.readlink(f'/proc/self/fd/{dir_fd}'), name) if dir_fd is not None else name
        subprocess.run(['rm', '-rf', target], check=True)
    return _open(name, flags, mode, dir_fd=dir_fd)
def _locked_fchmod(fd, mode):
    if os.fstat(fd).st_ino in _locked:
        raise PermissionError(errno.EPERM, os.strerror(errno.EPERM))
    return _fchmod(fd, mode)
import subprocess
os.open, os.fchmod = _vanishing_open, _locked_fchmod
# With CHOWN_LOG, an owner change is written to that file instead of made, as a test user may not give a file away: `fd <path> <uid>`
# for a change through an open entry, `entry <path> <uid>` for the owner of a link or a socket itself.
_fchown, _chown, _log = os.fchown, os.chown, os.environ.get('CHOWN_LOG', '')
def _recorded_fchown(fd, uid, gid):
    if not _log:
        return _fchown(fd, uid, gid)
    with open(_log, 'a') as stream:
        stream.write(f"fd {os.readlink(f'/proc/self/fd/{fd}')} {uid}\\n")
def _recorded_chown(path, uid, gid, *, dir_fd=None, follow_symlinks=True):
    if not _log:
        return _chown(path, uid, gid, dir_fd=dir_fd, follow_symlinks=follow_symlinks)
    target = os.path.join(os.readlink(f'/proc/self/fd/{dir_fd}'), path) if dir_fd is not None else path
    with open(_log, 'a') as stream:
        stream.write(f"{'path' if follow_symlinks else 'entry'} {target} {uid}\\n")
os.fchown, os.chown = _recorded_fchown, _recorded_chown
'''


def mode(path):
    return stat.S_IMODE(os.lstat(path).st_mode)


class WorkspaceModesTest(unittest.TestCase):
    def setUp(self):
        self.folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(self.folder)])
        self.workspace = self.folder / 'workspace'
        (self.workspace / 'node_modules/react').mkdir(parents=True)
        (self.workspace / '.git/objects/pack').mkdir(parents=True)
        for path in ['node_modules/react/index.js', 'run.sh', '.git/objects/pack/pack-1.pack', '.git/objects/pack/.tmp-1-pack-2.pack']:
            (self.workspace / path).write_text('x')
        for path in ['.', '.git', '.git/objects', 'node_modules', 'node_modules/react', '.git/objects/pack']:
            os.chmod(self.workspace / path, 0o755)
        for path in ['node_modules/react/index.js', '.git/objects/pack/pack-1.pack', '.git/objects/pack/.tmp-1-pack-2.pack']:
            os.chmod(self.workspace / path, 0o644)
        os.chmod(self.workspace / 'run.sh', 0o744)

    def run_pass(self, from_uid=NOBODY, agent_uid='2750', **environment):
        return subprocess.run([sys.executable, '-I', '-c', INJECT + PROGRAM, str(self.workspace), str(from_uid), str(agent_uid)], check=False,
                              capture_output=True, text=True, env={**os.environ, 'VANISH': '', 'LOCKED': '', 'CHOWN_LOG': '', **environment})

    def test_the_phase_runs_the_shipped_program_as_root_with_the_app_uid_and_agents(self):
        self.assertEqual(PASS['when'], "phase in ['checkout', 'processes', 'proof_checkout']")
        # The intent names the app user (InstanceContainer::intent `workspace_ids`): `www-data` (33) in a normal container, the inner
        # fleet's `orbit` (1000) in a nested-fleet one. Its entries go to `agent`, and the Laravel runtime trees go to it.
        self.assertEqual(re.sub(r'\s+', ' ', PASS['ansible.builtin.command']['argv']),
                         "{{ as_root + ['python3', '-I', '-c', lookup('ansible.builtin.file', playbook_dir ~ '/instance_workspace_owner.py'),"
                         " container.workspace, container.workspace_ids.writer | string, '2750'] }}")

    def test_entries_of_the_other_user_become_agents_and_a_link_is_changed_itself_never_its_target(self):
        # The test user stands for `orbit`: it owns every fixture entry. Outside the workspace, a file and a folder that links point at.
        outside, outside_folder = self.folder / 'outside', self.folder / 'outside-folder'
        outside.write_text('x')
        outside_folder.mkdir()
        (self.workspace / 'storage').mkdir()
        (self.workspace / 'storage/view.php').write_text('x')
        for path in ['.', '.git', '.git/objects', 'node_modules', 'node_modules/react', '.git/objects/pack', 'storage']:
            os.chmod(self.workspace / path, 0o2770)
        for path in ['node_modules/react/index.js', '.git/objects/pack/pack-1.pack', '.git/objects/pack/.tmp-1-pack-2.pack', 'storage/view.php']:
            os.chmod(self.workspace / path, 0o660)
        os.chmod(self.workspace / 'run.sh', 0o770)
        (self.workspace / 'storage/link').symlink_to(outside)
        (self.workspace / 'folder-link').symlink_to(outside_folder)
        log = self.folder / 'chown.log'
        done = self.run_pass(os.getuid(), CHOWN_LOG=str(log))
        self.assertEqual(done.returncode, 0, done.stderr)
        changes = sorted(log.read_text().splitlines())
        # Every entry, the workspace itself, its folders and files, through the entry the pass opened without following a link.
        entries = ['.', '.git', '.git/objects', '.git/objects/pack', '.git/objects/pack/.tmp-1-pack-2.pack', '.git/objects/pack/pack-1.pack',
                   'node_modules', 'node_modules/react', 'node_modules/react/index.js', 'run.sh', 'storage', 'storage/view.php']
        self.assertEqual(changes, sorted([f'fd {(self.workspace / entry).resolve()} 2750' for entry in entries]
                                         + [f'entry {self.workspace / "folder-link"} 2750', f'entry {self.workspace / "storage/link"} 2750']))
        # No change reached a link's target.
        self.assertFalse(any(str(outside) in line for line in changes))
        self.assertNotIn(str(outside), done.stdout)
        # The modes were already right: only the owners changed, and each changed entry is named.
        self.assertEqual(len(done.stdout.splitlines()), len(entries) + 2)
        # An entry another user owns, here `agent` itself, keeps its owner.
        log.unlink()
        self.assertEqual(self.run_pass(NOBODY, CHOWN_LOG=str(log)).stdout, '')
        self.assertFalse(log.exists())

    def test_laravel_runtime_trees_go_to_the_app_user_and_the_rest_to_agent(self):
        # A Laravel app at the root and one in a monorepo folder; `packages/lib` has a `storage` but no `artisan`.
        outside = self.folder / 'outside'
        outside.write_text('x')
        runtime = ['storage', 'storage/framework', 'storage/framework/views', 'storage/framework/views/a.php', 'bootstrap/cache',
                   'bootstrap/cache/packages.php', 'apps/site/storage', 'apps/site/storage/inertia-devtools', 'apps/site/bootstrap/cache']
        other = ['artisan', 'bootstrap', 'bootstrap/app.php', 'apps', 'apps/site', 'apps/site/artisan', 'apps/site/bootstrap',
                 'packages', 'packages/lib', 'packages/lib/storage', 'packages/lib/storage/file', 'node_modules/storage']
        for path in sorted(runtime + other, key=len):
            target = self.workspace / path
            if '.' in target.name or target.name in ('artisan', 'file'):
                target.write_text('x')
            elif not target.exists():
                target.mkdir()
        os.link(outside, self.workspace / 'storage/hard')
        (self.workspace / 'storage/link').symlink_to(outside)
        (self.workspace / 'apps/site/storage/folder-link').symlink_to(self.folder)
        program = self.workspace / 'storage/framework/views/program'
        program.write_text('x')
        os.chmod(program, 0o6770)
        log = self.folder / 'chown.log'
        # The test user stands for `agent`, as a deploy step wrote these: each runtime entry goes to the app user, nothing else changes owner.
        done = self.run_pass(NOBODY, os.getuid(), CHOWN_LOG=str(log))
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(sorted(log.read_text().splitlines()),
                         sorted([f'fd {(self.workspace / path).resolve()} {NOBODY}' for path in runtime + ['storage/framework/views/program']]
                                + [f'entry {self.workspace / "storage/link"} {NOBODY}',
                                   f'entry {self.workspace / "apps/site/storage/folder-link"} {NOBODY}']))
        # The hard link keeps its owner, a link's target is untouched, and a file given to the app user keeps its setuid and setgid bits.
        self.assertFalse(any('hard' in line or str(outside) in line for line in log.read_text().splitlines()))
        self.assertEqual(mode(program), 0o6770)
        # The group still gets back what the owner has, in the runtime trees too.
        self.assertEqual(mode(self.workspace / 'storage/framework/views'), stat.S_IMODE(os.lstat(self.workspace / 'storage').st_mode))
        self.assertEqual(mode(self.workspace / 'storage/framework/views/a.php') & 0o060, 0o060)
        # The test user as the app user: the runtime entries are already the app's, and everything else goes to `agent`.
        log.unlink()
        done = self.run_pass(os.getuid(), NOBODY, CHOWN_LOG=str(log))
        self.assertEqual(done.returncode, 0, done.stderr)
        given = {line.rsplit(' ', 1)[0].split(' ', 1)[1] for line in log.read_text().splitlines()}
        self.assertTrue({str((self.workspace / path).resolve()) for path in other} <= given)
        self.assertFalse({str((self.workspace / path).resolve()) for path in runtime} & given)
        self.assertEqual({line.rsplit(' ', 1)[1] for line in log.read_text().splitlines()}, {NOBODY})

    def test_a_runtime_entry_of_any_owner_goes_to_the_app_user_and_only_a_real_folder_starts_a_runtime_tree(self):
        # The test user stands for root, a third owner: neither the app user (NOBODY) nor `agent` (OTHER).
        (self.workspace / 'artisan').write_text('x')
        (self.workspace / 'storage/framework/views').mkdir(parents=True)
        (self.workspace / 'storage/framework/views/a.php').write_text('x')
        (self.workspace / 'bootstrap/cache').mkdir(parents=True)
        (self.workspace / 'bootstrap/cache/config.php').write_text('x')
        # In a monorepo app, a file named `storage` and a link at `bootstrap/cache` start no runtime tree.
        (self.workspace / 'apps/site/bootstrap').mkdir(parents=True)
        (self.workspace / 'apps/site/artisan').write_text('x')
        (self.workspace / 'apps/site/storage').write_text('x')
        (self.workspace / 'apps/site/cache').mkdir()
        (self.workspace / 'apps/site/bootstrap/cache').symlink_to(self.workspace / 'apps/site/cache')
        other, log = '4242425', self.folder / 'chown.log'
        done = self.run_pass(NOBODY, other, CHOWN_LOG=str(log))
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(sorted(log.read_text().splitlines()),
                         sorted(f'fd {(self.workspace / path).resolve()} {NOBODY}' for path in
                                ['storage', 'storage/framework', 'storage/framework/views', 'storage/framework/views/a.php', 'bootstrap/cache',
                                 'bootstrap/cache/config.php']))
        # The test user as the app user: the file and the link at the runtime names follow the workspace's rule and go to `agent`.
        log.unlink()
        done = self.run_pass(os.getuid(), other, CHOWN_LOG=str(log))
        self.assertEqual(done.returncode, 0, done.stderr)
        changes = log.read_text().splitlines()
        self.assertIn(f'fd {(self.workspace / "apps/site/storage").resolve()} {other}', changes)
        self.assertIn(f'entry {self.workspace / "apps/site/bootstrap/cache"} {other}', changes)
        self.assertFalse(any(line.endswith(f' {os.getuid()}') for line in changes), changes)

    def test_an_orbit_file_hard_linked_from_outside_keeps_its_owner(self):
        # A hard link in the workspace to a file outside it: giving the inode away would give away the outside name too.
        outside = self.folder / 'outside'
        outside.write_text('x')
        os.chmod(outside, 0o600)
        os.link(outside, self.workspace / 'hard')
        log = self.folder / 'chown.log'
        done = self.run_pass(os.getuid(), CHOWN_LOG=str(log))
        self.assertEqual(done.returncode, 0, done.stderr)
        changes = log.read_text().splitlines()
        self.assertFalse(any(line.endswith(f'/hard 2750') or str(outside) in line for line in changes), changes)
        # Other entries are still given away, and the mode follows the group rule as before.
        self.assertIn(f'fd {(self.workspace / "run.sh").resolve()} 2750', changes)
        self.assertEqual(mode(outside), 0o660)

    def test_a_given_file_loses_setuid_and_setgid_and_a_socket_gets_its_owner_without_being_opened(self):
        # `orbit` must never make a program that runs as `agent`; a socket of an `orbit` Process cannot be opened (ENXIO).
        program = self.workspace / 'program'
        program.write_text('x')
        os.chmod(program, 0o6750)
        listener = socket.socket(socket.AF_UNIX)
        self.addCleanup(listener.close)
        listener.bind(str(self.workspace / 'app.sock'))
        log = self.folder / 'chown.log'
        done = self.run_pass(os.getuid(), CHOWN_LOG=str(log))
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(mode(program), 0o770)
        changes = log.read_text().splitlines()
        self.assertIn(f'fd {program.resolve()} 2750', changes)
        self.assertIn(f'entry {self.workspace / "app.sock"} 2750', changes)
        # A file that stays its owner's keeps its bits.
        os.chmod(program, 0o4770)
        self.assertEqual(self.run_pass().returncode, 0)
        self.assertEqual(mode(program), 0o4770)

    def test_a_pass_that_changes_many_entries_prints_only_their_count(self):
        for index in range(25):
            (self.workspace / f'view-{index}.php').write_text('x')
            os.chmod(self.workspace / f'view-{index}.php', 0o640)
        done = self.run_pass()
        self.assertEqual(done.returncode, 0, done.stderr)
        # The 25 files, and the 10 entries of the fixture that lack the group bits.
        self.assertEqual(done.stdout, '35 entries changed\n')

    def test_entries_that_vanish_during_the_pass_are_skipped_and_the_rest_gets_the_group_bits(self):
        done = self.run_pass(VANISH='pack-1.pack,.tmp-1-pack-2.pack,react')
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(done.stderr, '')
        self.assertFalse((self.workspace / 'node_modules/react').exists())
        self.assertFalse((self.workspace / '.git/objects/pack/pack-1.pack').exists())
        self.assertEqual([mode(self.workspace / 'node_modules'), mode(self.workspace / '.git/objects/pack'), mode(self.workspace / 'run.sh')],
                         [0o775, 0o775, 0o774])
        # Nothing that still exists lacks the bits, and only the entries that changed are named.
        self.assertEqual(sorted(Path(line).relative_to(self.workspace).as_posix() for line in done.stdout.splitlines()),
                         ['.', '.git', '.git/objects', '.git/objects/pack', 'node_modules', 'run.sh'])
        self.assertEqual(self.run_pass().stdout, '')

    def test_a_mode_change_that_is_refused_still_fails_the_pass_and_names_the_path(self):
        locked = self.workspace / 'node_modules/react/index.js'
        done = self.run_pass(LOCKED=str(locked))
        self.assertEqual(done.returncode, 1)
        self.assertEqual(done.stderr.strip(), f'{locked}: Operation not permitted')
        self.assertEqual([mode(locked), mode(self.workspace / 'node_modules/react')], [0o644, 0o775])

    def test_a_folder_the_pass_cannot_read_fails_it(self):
        if os.geteuid() == 0:
            self.skipTest('root reads every folder')
        closed = self.workspace / 'node_modules/react'
        os.chmod(closed, 0)
        self.addCleanup(os.chmod, closed, 0o755)
        done = self.run_pass()
        self.assertEqual(done.returncode, 1)
        self.assertEqual(done.stderr.strip(), f'{closed}: Permission denied')

    def test_a_link_in_the_workspace_never_changes_what_it_points_at(self):
        outside = self.folder / 'outside'
        outside.write_text('x')
        os.chmod(outside, 0o600)
        (self.workspace / 'link').symlink_to(outside)
        (self.workspace / 'folder-link').symlink_to(self.folder)
        done = self.run_pass()
        self.assertEqual(done.returncode, 0, done.stderr)
        self.assertEqual(mode(outside), 0o600)
        self.assertNotIn(str(outside), done.stdout)

    def test_a_missing_workspace_fails_the_pass(self):
        subprocess.run(['rm', '-rf', str(self.workspace)], check=True)
        self.assertNotEqual(self.run_pass().returncode, 0)


class WorkspaceGitMaintenanceTest(unittest.TestCase):
    def git(self, cwd, *args, options=()):
        return subprocess.run(['git', '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', *options, *args], cwd=cwd,
                              check=True, capture_output=True, text=True, env={**os.environ, 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null'})

    def test_the_workspace_fetch_starts_no_automatic_maintenance(self):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(folder)])
        source, workspace = folder / 'source', folder / 'workspace'
        source.mkdir()
        self.git(source, 'init', '--quiet', '--initial-branch', 'main')
        self.git(source, 'commit', '--quiet', '--allow-empty', '-m', 'first')
        self.git(folder, 'clone', '--quiet', '--no-local', str(source), str(workspace))
        # A repository past every threshold, whose maintenance would run in the foreground: any maintenance the fetch starts is seen
        # when the fetch returns.
        for key, value in [('transfer.unpackLimit', '1'), ('gc.auto', '1'), ('gc.autoPackLimit', '1'), ('gc.autoDetach', 'false'), ('maintenance.autoDetach', 'false')]:
            self.git(workspace, 'config', key, value)
        packs = folder / 'workspace/.git/objects/pack'
        for index in range(3):
            self.git(source, 'commit', '--quiet', '--allow-empty', '-m', str(index))
            self.git(source, 'bundle', 'create', '--quiet', str(folder / f'{index}.bundle'), 'main')
            self.git(workspace, 'fetch', '--quiet', str(folder / f'{index}.bundle'), '+refs/heads/main:refs/remotes/origin/main',
                     options=GIT_OPTIONS)
        self.assertEqual(['maintenance.auto=false', 'gc.auto=0'], [option for option in GIT_OPTIONS if 'auto' in option])
        # Every fetched bundle stays its own pack: no repack ran.
        self.assertEqual(len(list(packs.glob('pack-*.pack'))), 4)

    def test_the_checkout_repack_keeps_the_pack_count_bounded_after_many_fetches(self):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(folder)])
        source, workspace = folder / 'source', folder / 'workspace'
        source.mkdir()
        self.git(source, 'init', '--quiet', '--initial-branch', 'main')
        self.git(folder, 'init', '--quiet', '--shared=group', '--initial-branch', 'main', str(workspace))
        packs = workspace / '.git/objects/pack'
        self.assertEqual(REPACK_ARGS, ['repack', '-d', '-q', '--geometric=2'])
        self.assertIn("as_owner + ['timeout', '30'] + in_workspace[in_workspace.index('git'):]", REPACK_ARGV)
        counts = []
        for index in range(40):
            # Each deployment's bundle adds files of its own, so each fetch adds one pack.
            (source / f'file-{index}').write_text(str(index) * (index + 1) * 100)
            self.git(source, 'add', '.')
            self.git(source, 'commit', '--quiet', '-m', str(index))
            self.git(source, 'bundle', 'create', '--quiet', str(folder / 'deploy.bundle'), 'main')
            self.git(workspace, 'fetch', '--quiet', str(folder / 'deploy.bundle'), '+refs/heads/main:refs/remotes/origin/main',
                     options=GIT_OPTIONS)
            self.git(workspace, *REPACK_ARGS, options=GIT_OPTIONS)
            counts.append(len(list(packs.glob('pack-*.pack'))))
        # Without the repack the workspace would hold 40 packs; with it the count stays near log2 of the history.
        self.assertLessEqual(max(counts), 3, counts)
        # Every object stays: the branch's whole history reads back, and the packs keep the shared group's read bit.
        self.git(workspace, 'fsck', '--connectivity-only', '--no-dangling')
        self.assertEqual(self.git(workspace, 'rev-list', '--count', 'refs/remotes/origin/main').stdout.strip(), '40')
        self.assertTrue(all(mode(pack) & 0o040 for pack in packs.glob('pack-*')))
        # With nothing to merge it rewrites nothing.
        before = sorted(path.name for path in packs.iterdir())
        self.git(workspace, *REPACK_ARGS, options=GIT_OPTIONS)
        self.assertEqual(sorted(path.name for path in packs.iterdir()), before)

    def test_a_repack_that_fails_or_times_out_is_reported_and_never_fails_the_checkout(self):
        self.assertIs(REPACK['failed_when'], False)
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(folder)])
        note = REPACK_NOTE['ansible.builtin.debug']['msg']
        results = {'passed': {'rc': 0, 'stderr': ''}, 'timed_out': {'rc': 124, 'stderr': ''},
                   'failed': {'rc': 128, 'stderr': 'fatal: unable to create temporary file\n'}}
        play = [{'hosts': 'localhost', 'gather_facts': False, 'connection': 'local', 'tasks': [
            {'name': f'Note {name}', 'when': REPACK_NOTE['when'].replace('workspace_repack', name),
             'vars': {name: result, 'word': note.replace('workspace_repack', name)},
             'ansible.builtin.copy': {'content': '{{ word }}', 'dest': str(folder / name), 'mode': '0600'}}
            for name, result in results.items()]}]
        (folder / 'play.yml').write_text(yaml.safe_dump(play))
        playbook = Path(sys.executable).parent / 'ansible-playbook'
        done = subprocess.run([str(playbook) if playbook.exists() else 'ansible-playbook', '-i', 'localhost,', str(folder / 'play.yml')],
                              check=False, capture_output=True, text=True, env={**os.environ, 'ANSIBLE_PYTHON_INTERPRETER': sys.executable})
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertFalse((folder / 'passed').exists())
        self.assertEqual((folder / 'timed_out').read_text(), 'orbit-workspace-repack=timed_out')
        self.assertEqual((folder / 'failed').read_text(), 'orbit-workspace-repack=failed: fatal: unable to create temporary file')


class CheckoutFailureDetailTest(unittest.TestCase):
    """The play's rescue (swamp/ansible/instance-container.yml) writes `detail` for a failed checkout; the Gateway shows it."""

    RESCUE = next(task for play in yaml.safe_load((PHASE.parents[1] / 'instance-container.yml').read_text()) for block in play['tasks']
                  for task in block.get('rescue', []) if task['name'] == 'Write the failure')['vars']

    def detail(self, phase, task, result):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(folder)])
        names = ['failure_phase', 'failure_code', 'failure_message', 'failure_inactive_units', 'failure_checkout_head', 'failure_checkout_stderr',
                 'failure_checkout_budget', 'failure_checkout_detail']
        play = [{'hosts': 'localhost', 'gather_facts': False, 'connection': 'local',
                 'vars': {'container_phase_running': phase, 'process_action': 'none', 'ansible_failed_task': {'name': task},
                          'ansible_failed_result': result, **{name: self.RESCUE[name] for name in names}},
                 'tasks': [{'ansible.builtin.copy': {'content': '{{ failure_checkout_detail | to_json }}', 'dest': str(folder / 'detail.json'),
                                                     'mode': '0600'}}]}]
        (folder / 'play.yml').write_text(yaml.safe_dump(play))
        playbook = Path(sys.executable).parent / 'ansible-playbook'
        done = subprocess.run([str(playbook) if playbook.exists() else 'ansible-playbook', '-i', 'localhost,', str(folder / 'play.yml')],
                              check=False, capture_output=True, text=True, env={**os.environ, 'ANSIBLE_PYTHON_INTERPRETER': sys.executable})
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        return yaml.safe_load((folder / 'detail.json').read_text())

    def test_a_failed_checkout_names_its_task_and_the_end_of_its_stderr_in_4096_characters(self):
        task = 'Keep the workspace writable for its group'
        self.assertEqual(self.detail('checkout', task, {'msg': 'non-zero return code', 'stderr': '/srv/workspace/locked: Permission denied\n'}),
                         {'detail': f'{task}: non-zero return code: /srv/workspace/locked: Permission denied'})
        long = self.detail('checkout', task, {'msg': 'non-zero return code', 'stderr': 'x' * 9000 + 'the end'})['detail']
        self.assertEqual([len(long), long.startswith(f'{task}: non-zero return code: x'), long.endswith('the end')], [4096, True, True])
        # Every length keeps the whole stderr up to the limit, and the end of a longer one.
        for size in [2000, 3000, 4000, 4030]:
            with self.subTest(size=size):
                stderr = ''.join(chr(ord('a') + index % 26) for index in range(size))
                detail = self.detail('checkout', task, {'msg': 'non-zero return code', 'stderr': stderr})['detail']
                head = f'{task}: non-zero return code: '
                self.assertEqual(detail, head + stderr[-(4096 - len(head)):])
        self.assertEqual(self.detail('checkout', task, {'msg': 'The task failed.'}), {'detail': f'{task}: The task failed.'})

    def test_a_refusal_and_other_phases_write_no_checkout_detail(self):
        self.assertEqual(self.detail('checkout', 'Refuse tracked changes', {'msg': 'deployment.checkout_dirty'}), {})
        self.assertEqual(self.detail('processes', 'Keep the workspace writable for its group', {'msg': 'non-zero return code', 'stderr': 'x'}), {})


if __name__ == '__main__':
    unittest.main()
