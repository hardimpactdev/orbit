"""Real workspace proof; independent file with the shared, test-free harness."""
import copy
import hashlib
import json
import runpy
import shutil
import unittest
from pathlib import Path

SUPPORT = runpy.run_path(str(Path(__file__).parent / "_support/task_instance_workspace.py"))


class TaskInstanceWorkspaceTest(SUPPORT["WorkspaceHarness"]):
    def test_export_refuses_a_branch_move_during_bundle_creation(self):
        self.export_candidate()
        candidate = self.intent['head']
        (self.checkout / 'base.txt').write_text('new unreviewed commit\n')
        self.git('add', 'base.txt')
        self.git('commit', '--quiet', '-m', 'Later commit')
        later = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/heads/' + self.intent['branch'], candidate)
        output = self.phase('export', ok=False, move_to=later)
        self.assertIn('tasks.workspace_snapshot_mismatch', output)
        self.assertFalse((self.scratch / 'export.bundle').exists())
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        self.assertFalse(any(call[2:4] == ['file', 'pull'] for call in calls))

    def test_export_removes_node_scratch_when_guest_cleanup_fails(self):
        self.export_candidate()
        self.assertIn('tasks.workspace_phase_failed', self.phase('export', ok=False, cleanup_fail=True))
        transfers = [json.loads(line) for line in self.calls.read_text().splitlines()
                     if json.loads(line)[2:4] == ['file', 'pull']]
        self.assertEqual(len(transfers), 1)
        self.assertFalse(Path(transfers[0][-1]).parent.exists(), 'Node export scratch leaked on cleanup failure')

    def test_exact_branch_export_and_corrupt_transfer_cleanup(self):
        self.git('add', '--all')
        self.git('commit', '--quiet', '-m', 'Candidate')
        self.intent['head'] = self.git('rev-parse', 'HEAD')
        value, _ = self.phase('preflight')
        self.intent['checkout_fingerprint'] = value['checkout_fingerprint']
        self.intent['candidate'] = {'head': self.intent['head'], 'tree': self.git('rev-parse', 'HEAD^{tree}')}
        exported, _ = self.phase('export')
        node = Path(exported['node_scratch'])
        self.addCleanup(shutil.rmtree, node, True)
        bundle = Path(exported['bundle'])
        self.assertEqual(hashlib.sha256(bundle.read_bytes()).hexdigest(), exported['sha256'])
        imported = self.root / 'imported'
        imported.mkdir()
        self.git('init', '--quiet', '--bare', cwd=imported)
        self.git('fetch', '--quiet', str(bundle), 'refs/heads/proof-branch:refs/heads/proof-branch', cwd=imported)
        self.assertEqual(self.git('rev-parse', 'proof-branch', cwd=imported), self.intent['head'])
        self.assertEqual(self.git('rev-parse', 'proof-branch^{tree}', cwd=imported), self.intent['candidate']['tree'])
        self.assertFalse((self.scratch / 'export.bundle').exists())
        self.assertIn('tasks.workspace_phase_failed', self.phase('export', ok=False, corrupt=True))
        self.assertFalse((self.scratch / 'export.bundle').exists())
        transfers = [json.loads(line) for line in self.calls.read_text().splitlines() if json.loads(line)[2:4] == ['file', 'pull']]
        self.assertFalse(Path(transfers[-1][-1]).parent.exists(), 'partial Node scratch leaked')
        bad = copy.deepcopy(self.intent)
        bad['candidate']['tree'] = '0' * 40
        self.assertIn('tasks.workspace_snapshot_mismatch', self.phase('export', bad, ok=False))


if __name__ == "__main__":
    unittest.main()
