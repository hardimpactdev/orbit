"""A failed or cancelled task:sync of a copy leaves no credential anywhere and no bundle in the copy; the next base removes what an
earlier one left on the Node."""
import runpy
import socket
import threading
import unittest
from pathlib import Path

HARNESS = runpy.run_path(str(Path(__file__).parent / '_support/task_instance_workspace_base.py'))


class TaskInstanceWorkspaceBaseCleanupTest(HARNESS['BaseHarness']):
    def silent_origin(self):
        """An HTTPS origin that accepts and never answers: it holds the fetch, with the credential in its environment."""
        server = socket.socket()
        server.bind(('127.0.0.1', 0))
        server.listen(1)
        self.addCleanup(server.close)
        accepted = []
        threading.Thread(target=lambda: accepted.append(server.accept()[0]), daemon=True).start()
        self.addCleanup(lambda: [connection.close() for connection in accepted])
        return f'https://127.0.0.1:{server.getsockname()[1]}/orbit.git', accepted

    def test_a_failed_fetch_leaves_no_credential_and_no_bundle(self):
        before = self.state()
        intent = {**self.intent, 'repository': self.server.url.replace('origin.git', 'missing.git')}
        output = self.phase('base', intent, ok=False)
        # The playbook's rescue turns this into tasks.workspace_phase_failed; here the failed check names it.
        self.assertIn('tasks.workspace_phase_failed', output)
        self.assertEqual(self.pushes(), [])
        self.assertEqual(self.git('rev-parse', 'refs/remotes/origin/main'), self.seed)
        self.assertEqual(self.state(), before)
        self.assert_nothing_left()
        self.assert_no_credential_anywhere()

    def test_a_sync_cancelled_during_the_fetch_leaves_no_credential_and_no_bundle(self):
        url, accepted = self.silent_origin()
        intent = {**self.intent, 'repository': url}
        self.cancel(self.start('base', intent), lambda: bool(accepted))
        self.assert_no_credential_anywhere()
        self.assertFalse(self.guest.exists(), 'a bundle folder in the copy before the bundle was made')
        self.assertEqual(self.phase('base-cleanup', intent), {'cleaned': True, 'changed': False})
        self.assertEqual(self.evidence('base-cleanup'), {'cleaned': True, 'changed': False})
        self.assert_nothing_left()

    def test_a_sync_cancelled_after_the_push_leaves_no_credential_and_base_cleanup_removes_the_bundle(self):
        before = self.state()
        self.cancel(self.start('base', hold_at='base-import'), self.mark.exists)
        self.assertTrue((self.node / 'base.bundle').is_file())
        self.assertTrue((self.guest / 'base.bundle').is_file())
        self.assert_no_credential_anywhere()
        self.phase('base-cleanup')
        self.assert_nothing_left()
        self.assertEqual(self.git('rev-parse', 'refs/remotes/origin/main'), self.seed)
        self.assertEqual(self.state(), before)

    def test_the_next_base_removes_what_an_earlier_sync_of_the_copy_left(self):
        # An earlier sync of this copy was cancelled and its cleanup job never ran.
        for folder in (self.node, self.guest):
            folder.mkdir(mode=0o700)
            (folder / 'base.bundle').write_bytes(b'stale bundle')
        result = self.phase('base')
        self.assertEqual((result['default_commit'], result['bundled']), (self.head, True))
        self.assert_nothing_left()
        self.assert_no_credential_anywhere()


if __name__ == '__main__':
    unittest.main()
