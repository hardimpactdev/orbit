"""The container phase's group mode pass and its Git calls in a live workspace (swamp/ansible/tasks/instance-container-phase.yml).

On 2026-11-14 the checkout of a release refresh failed: the fetch of the new commit started Git's automatic maintenance in the
background, the repack removed pack files while `find … -exec chmod g+rwX {} +` ran over the workspace, and chmod's "No such file or
directory" failed the phase. These cases run the shipped pass and the shipped Git options on a scratch repository.
"""

import os
import re
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

PHASE = Path(__file__).resolve().parents[1] / 'ansible/tasks/instance-container-phase.yml'
TASKS = yaml.safe_load(PHASE.read_text())
PROGRAM = next(task for task in TASKS if task.get('name') == 'Keep the workspace writable for its group')['vars']['workspace_modes_program']
# The Git options of every Orbit Git call in the workspace: the quoted words after 'git' in `in_workspace`.
IN_WORKSPACE = next(task for task in TASKS if task.get('name') == 'Name the container commands')['ansible.builtin.set_fact']['in_workspace']
GIT_OPTIONS = re.findall(r"'([^']*)'", IN_WORKSPACE.split("'git',", 1)[1])

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
        for path in ['node_modules', 'node_modules/react', '.git/objects/pack']:
            os.chmod(self.workspace / path, 0o755)
        for path in ['node_modules/react/index.js', '.git/objects/pack/pack-1.pack', '.git/objects/pack/.tmp-1-pack-2.pack']:
            os.chmod(self.workspace / path, 0o644)
        os.chmod(self.workspace / 'run.sh', 0o744)

    def run_pass(self, **environment):
        return subprocess.run([sys.executable, '-I', '-c', INJECT + PROGRAM, str(self.workspace)], check=False, capture_output=True, text=True,
                              env={'PATH': os.environ['PATH'], **environment})

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


class CheckoutFailureDetailTest(unittest.TestCase):
    """The play's rescue (swamp/ansible/instance-container.yml) writes `detail` for a failed checkout; the Gateway shows it."""

    RESCUE = next(task for play in yaml.safe_load((PHASE.parents[1] / 'instance-container.yml').read_text()) for block in play['tasks']
                  for task in block.get('rescue', []) if task['name'] == 'Write the failure')['vars']

    def detail(self, phase, task, result):
        folder = Path(tempfile.mkdtemp())
        self.addCleanup(subprocess.run, ['rm', '-rf', str(folder)])
        names = ['failure_phase', 'failure_code', 'failure_message', 'failure_checkout_head', 'failure_checkout_stderr', 'failure_checkout_detail']
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
        self.assertEqual(self.detail('checkout', task, {'msg': 'The task failed.'}), {'detail': f'{task}: The task failed.'})

    def test_a_refusal_and_other_phases_write_no_checkout_detail(self):
        self.assertEqual(self.detail('checkout', 'Refuse tracked changes', {'msg': 'deployment.checkout_dirty'}), {})
        self.assertEqual(self.detail('processes', 'Keep the workspace writable for its group', {'msg': 'non-zero return code', 'stderr': 'x'}), {})


if __name__ == '__main__':
    unittest.main()
