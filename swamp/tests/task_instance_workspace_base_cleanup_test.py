"""A failed or cancelled task:sync of a copy leaves no credential file on the Node and no bundle on the Node or in the copy."""
import hashlib
import runpy
import socket
import threading
import unittest
from pathlib import Path

HARNESS = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace_base.py'))
CREDENTIAL = HARNESS['CREDENTIAL']


class TaskInstanceWorkspaceBaseCleanupTest(HARNESS['BaseHarness']):
    def test_a_failed_fetch_leaves_no_credential_and_no_bundle(self):
        before = self.state()
        intent = {**self.intent, 'repository': (self.root / 'missing.git').as_uri()}
        output = self.phase('base', intent, ok=False)
        # The playbook's rescue turns this into tasks.workspace_phase_failed; here the failed task names it.
        self.assertIn('fatal: [localhost]: FAILED!', output.split('TASK [Fetch the default branch into the mirror]')[1].split('TASK [')[0])
        self.assertEqual(self.pushes(), [])
        self.assertEqual(self.git('rev-parse', 'refs/remotes/origin/main'), self.seed)
        self.assertEqual(self.state(), before)
        self.assert_nothing_left()

    def test_a_sync_cancelled_during_the_fetch_leaves_nothing_after_base_cleanup(self):
        # An HTTPS origin that accepts and never answers holds the fetch while the credential file exists.
        server = socket.socket()
        server.bind(('127.0.0.1', 0))
        server.listen(1)
        self.addCleanup(server.close)
        accepted = []
        threading.Thread(target=lambda: accepted.append(server.accept()[0]), daemon=True).start()
        self.addCleanup(lambda: [connection.close() for connection in accepted])
        intent = {**self.intent, 'repository': f'https://127.0.0.1:{server.getsockname()[1]}/orbit.git'}
        self.cancel(self.start('base', intent), lambda: bool(accepted))
        credential = self.node / 'credential'
        self.assertTrue(credential.is_file(), 'the cancel point is the fetch with its credential')
        self.assertEqual(credential.stat().st_mode & 0o777, 0o600)
        self.assertEqual(hashlib.sha256(credential.read_bytes()).hexdigest(), hashlib.sha256(CREDENTIAL.encode()).hexdigest())
        self.assertTrue(self.guest.is_dir())
        self.assertEqual(self.phase('base-cleanup', intent), {'cleaned': True, 'changed': False})
        self.assertEqual(self.evidence('base-cleanup'), {'cleaned': True, 'changed': False})
        self.assert_nothing_left()
        self.assert_no_credential_in_copy()

    def test_a_sync_cancelled_after_the_push_leaves_no_bundle_after_base_cleanup(self):
        before = self.state()
        self.cancel(self.start('base', hold_at='base-import'), self.mark.exists)
        self.assertTrue((self.node / 'base.bundle').is_file())
        self.assertTrue((self.guest / 'base.bundle').is_file())
        self.assertFalse((self.node / 'credential').exists(), 'the fetch removed its credential before the bundle')
        self.phase('base-cleanup')
        self.assert_nothing_left()
        self.assertEqual(self.git('rev-parse', 'refs/remotes/origin/main'), self.seed)
        self.assertEqual(self.state(), before)


if __name__ == '__main__':
    unittest.main()
