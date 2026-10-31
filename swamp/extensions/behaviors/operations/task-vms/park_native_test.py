"""Validate the owning review-park DAG with the canonical native harness."""
from pathlib import Path
import shutil
import sys
import unittest

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class TaskParkNativeTest(NativeTestCase):
    def test_review_park_has_one_exact_node_root(self):
        self.copy_behaviors("automatic-deploy", "operation-runner", "operations/task-vms", "operations/_lib")
        workflow = ROOT / "workflows/workflow-operation-task-park.yaml"
        shutil.copy(workflow, self.repo / "workflows" / workflow.name)
        self.cli("workflow", "validate", "operation-task-park")
        source = workflow.read_text()
        self.assertIn('target: \'${{ "orbit-node-" + string(inputs.node_id) }}\'', source)
        self.assertIn('labels: { node: "${{ string(inputs.node_id) }}" }', source)
        self.assertNotIn("trigger:", source)
        self.assertNotIn("queueTimeout:", source)


if __name__ == "__main__":
    unittest.main()
