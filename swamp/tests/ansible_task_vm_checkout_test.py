"""The warm checkout of a new base VM holds the files of the commit, also when the commit is the tip of the branch it clones."""
import grp
import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ANSIBLE = Path(__file__).resolve().parents[1] / 'ansible'
CHECKOUT = ANSIBLE / 'tasks/task-vm-checkout.yml'
COMMAND = shutil.which('ansible-playbook') or str(Path(sys.executable).parent / 'ansible-playbook')
NEEDS_ANSIBLE = unittest.skipUnless(shutil.which('ansible-playbook') or (Path(sys.executable).parent / 'ansible-playbook').exists(),
                                    'ansible-playbook is not installed')
USER = os.environ.get('USER') or os.environ.get('LOGNAME') or 'orbit'


def has_group(name):
    try:
        grp.getgrnam(name)
    except KeyError:
        return False
    return True


# The tasks give the checkout folder the group of the user's own name, as on a task VM.
NEEDS_USER_GROUP = unittest.skipUnless(has_group(USER), f'no group named {USER}')


def git(*argv, cwd):
    return subprocess.run(['git', *argv], cwd=cwd, check=True, capture_output=True, text=True,
                          env={**os.environ, 'GIT_AUTHOR_NAME': 't', 'GIT_AUTHOR_EMAIL': 't@t', 'GIT_COMMITTER_NAME': 't', 'GIT_COMMITTER_EMAIL': 't@t'}).stdout.strip()


@NEEDS_ANSIBLE
@NEEDS_USER_GROUP
class TaskVmCheckoutTest(unittest.TestCase):
    def converge(self, folder, origin, commit):
        """Run the checkout tasks on this machine as the converge of a base VM runs them; returns the exit code and the output."""
        checkout = folder / 'apps/orbit/workspace'
        (folder / 'play.yml').write_text(json.dumps([{'hosts': 'vm', 'gather_facts': False, 'vars': {
            'orbit_user': USER, 'orbit_home': str(folder), 'converge_stamps': {}, 'converge_stamps_dir': str(folder / 'stamps'),
            # One tracked lock file, of a manager the tasks do not install, so only the checkout itself runs.
            'checkout_projects': [{'dir': '.', 'manager': 'none', 'object': 'HEAD:lock.json', 'stamp': 'lock'}],
            'intent': {'checkout': {'path': str(checkout), 'repository': str(origin), 'commit': commit}}},
            'tasks': [{'ansible.builtin.import_tasks': str(CHECKOUT)}]}]))
        (folder / 'inventory.yml').write_text(json.dumps({'all': {'hosts': {'vm': {
            'ansible_connection': 'local', 'ansible_python_interpreter': sys.executable, 'ansible_become': False}}}}))
        result = subprocess.run([COMMAND, '-i', str(folder / 'inventory.yml'), str(folder / 'play.yml')], check=False, capture_output=True, text=True,
                                timeout=120, env={**os.environ, 'ANSIBLE_CONFIG': str(ANSIBLE / 'ansible.cfg'), 'ANSIBLE_FORCE_COLOR': '0'})
        return result.returncode, result.stdout + result.stderr, checkout

    def test_a_fresh_clone_at_the_tip_of_main_has_the_files_of_the_commit(self):
        with tempfile.TemporaryDirectory() as folder:
            folder = Path(folder)
            origin = folder / 'origin'
            origin.mkdir()
            git('init', '--quiet', '--initial-branch=main', cwd=origin)
            (origin / 'lock.json').write_text('{}\n')
            git('add', 'lock.json', cwd=origin)
            git('commit', '--quiet', '-m', 'lock', cwd=origin)
            # A refresh at a release converges to the commit that release merged: the tip of main, which the clone's HEAD already names.
            (folder / 'vm').mkdir()
            code, output, checkout = self.converge(folder / 'vm', origin, git('rev-parse', 'HEAD', cwd=origin))

            self.assertEqual(code, 0, output)
            self.assertTrue((checkout / 'lock.json').is_file(), 'the clone at the tip of main has no files: ' + output)
            self.assertEqual(git('status', '--porcelain', cwd=checkout), '')


if __name__ == '__main__':
    unittest.main()
