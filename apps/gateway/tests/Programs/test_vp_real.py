"""Real vp CLI contract test. Run with VP_TEST_HOME pointing at a disposable, owner-prepared vp 0.3.3 store.
The store must contain Node 24.22.0 and npm 11.12.0. No live store is accepted: its path must be under /tmp.
"""
import fcntl
import hashlib
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

from harness import result


def snapshot(home):
    return {str(path.relative_to(home)): (path.lstat().st_mtime_ns, hashlib.sha256(path.read_bytes()).hexdigest() if path.is_file() and not path.is_symlink() else os.readlink(path) if path.is_symlink() else None)
            for path in home.rglob('*')}


@unittest.skipUnless(os.environ.get('VP_TEST_HOME'), 'Set VP_TEST_HOME to a disposable real vp 0.3.3 store')
class RealVpTest(unittest.TestCase):
    def test_env_exec_flags_as_unprivileged_app_user_do_not_write_store(self):
        home = Path(os.environ['VP_TEST_HOME']).resolve()
        self.assertTrue(home.is_relative_to('/tmp'))
        binary = home / 'bin' / 'vp'
        before = snapshot(home)
        with tempfile.TemporaryDirectory(dir='/tmp', prefix='orbit-vp-app-') as directory:
            root = Path(directory)
            root.chmod(0o777)
            (root / 'package.json').write_text(json.dumps({'name': 'vp-read-only-contract', 'version': '1.0.0', 'packageManager': 'npm@11.12.0', 'scripts': {'build': 'node -e "console.log(42)"'}}))
            (root / 'package-lock.json').write_text(json.dumps({'name': 'vp-read-only-contract', 'version': '1.0.0', 'lockfileVersion': 3, 'packages': {'': {'name': 'vp-read-only-contract', 'version': '1.0.0'}}}))
            for path in root.iterdir():
                path.chmod(0o666)
            subprocess.run(['sudo', '-n', 'chown', '-R', 'nobody', str(root)], check=True)
            command = ['sudo', '-n', '-u', 'nobody', 'env', f'VP_HOME={home}', f'HOME={root}', f'PATH={home}/bin:' + os.environ['PATH'], str(binary),
                       'env', 'exec', '--node', '24.22.0', '--package-manager', 'npm@11.12.0',
                       'bash', '-eu', '-c', 'trap \'chmod -R a+rwX "$HOME"\' EXIT; node --version; npm --version; test ! -w "$VP_HOME"; vp install --frozen-lockfile; vp run build']
            try:
                completed = subprocess.run(command, cwd=root, capture_output=True, text=True, timeout=30, check=False)
            finally:
                subprocess.run(['sudo', '-n', 'chown', '-R', str(os.getuid()), str(root)], check=True)
            self.assertEqual(completed.returncode, 0, completed.stderr)
            self.assertTrue(completed.stdout.startswith('v24.22.0\n11.12.0\n'), completed.stdout)
            self.assertIn('42', completed.stdout)
        self.assertEqual(before, snapshot(home), 'Application execution changed the shared store')

    def test_adopts_recipe_pnpm_without_taking_the_install_lock_or_writing(self):
        home = Path(os.environ['VP_TEST_HOME']).resolve()
        self.assertTrue(home.is_relative_to('/tmp'))
        manifests = list((home / 'package_manager/pnpm').glob('*/pnpm/package.json'))
        self.assertTrue(manifests, 'Prepare recipe pnpm in this disposable store')
        version = json.loads(manifests[0].read_text())['version']
        before = snapshot(home)
        configuration = {'store': str(home), 'vp_version': '0.3.3', 'node_version': '24.22.0', 'package_manager': 'pnpm', 'package_manager_version': version, 'lock_timeout': 0}
        with (home / '.orbit-store.lock').open('r') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX)
            outcome, data = result('tools.install-vp-environment', configuration)
        self.assertEqual((outcome, data), ('installed', {'verified': {}}))
        completed = subprocess.run(['sudo', '-n', '-u', 'nobody', 'env', f'VP_HOME={home}', str(home / 'bin/vp'), 'env', 'exec', '--node', '24.22.0', '--package-manager', 'pnpm@' + version, 'pnpm', '--version'], cwd='/tmp', capture_output=True, text=True, timeout=30, check=False)
        self.assertEqual(completed.returncode, 0, completed.stderr)
        self.assertEqual(completed.stdout.strip(), version)
        self.assertEqual(before, snapshot(home), 'Recipe pnpm adoption changed the shared store')
