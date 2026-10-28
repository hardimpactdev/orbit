"""Exercise native Swamp recover/resume in a disposable repository; no live Gateway."""
import json
import os
from pathlib import Path
import shutil
import signal
import subprocess
import tempfile
import time
import unittest

ROOT = Path(__file__).resolve().parents[3]
SWAMP = shutil.which("swamp") or "swamp"


class ObservationNativeTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="observation-native-")
        self.addCleanup(self.temp.cleanup)
        self.repo = Path(self.temp.name)
        shutil.copy(ROOT / ".swamp.yaml", self.repo)
        for family in ("observation", "automatic-deploy", "operation-runner"):
            shutil.copytree(ROOT / "extensions/behaviors" / family,
                            self.repo / "extensions/behaviors" / family)
        entry = self.repo / "extensions/behaviors/observation/mod.ts"
        entry.write_text('import { fixtureHttp } from "./native_fixture.ts";\n' +
                         entry.read_text().replace("...observation()", "...observation(() => fixtureHttp)"))
        (self.repo / "workflows").mkdir()
        for path in ROOT.glob("workflows/workflow-observation-*.yaml"):
            shutil.copy(path, self.repo / "workflows" / path.name)
        self.state = self.repo / "native-observation.json"
        self.state.write_text(json.dumps({"calls": [], "reads": [], "receipts": {}, "interrupt": False}))
        self.env = {**os.environ, "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1"}

    def swamp(self, *args):
        result = subprocess.run([SWAMP, *args, "--json"], cwd=self.repo,
                                text=True, capture_output=True, timeout=45, env=self.env)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        return result.stdout

    def test_native_collection_reads_nodes_then_groups_then_signals(self):
        self.swamp("workflow", "validate", "observation-collection")
        self.swamp("workflow", "run", "observation-collection")
        state = json.loads(self.state.read_text())
        self.assertEqual([path.rsplit("/", 2)[-2:] for path in state["calls"]][1:],
                         [["nodes", "2"], ["probes", "0"], [next(iter(state["receipts"])).split("/")[5], "signals"]])

    def test_killed_process_recovers_and_resumes_the_original_read_receipts(self):
        state = json.loads(self.state.read_text())
        state["interrupt"] = True
        self.state.write_text(json.dumps(state))
        with tempfile.TemporaryFile() as output:
            process = subprocess.Popen([SWAMP, "workflow", "run", "observation-collection", "--json"],
                                       cwd=self.repo, stdout=output, stderr=output,
                                       env=self.env, start_new_session=True)
            try:
                deadline = time.monotonic() + 30
                while not (self.repo / "native-observation-boundary").exists():
                    if process.poll() is not None or time.monotonic() > deadline:
                        output.seek(0)
                        self.fail(output.read().decode())
                    time.sleep(0.05)
            finally:
                if process.poll() is None:
                    os.killpg(process.pid, signal.SIGKILL)
                process.wait(timeout=5)
        self.swamp("run", "doctor", "--fix")
        self.swamp("workflow", "recover", "observation-collection", "--acknowledge-unknown")
        self.swamp("workflow", "resume", "observation-collection")
        state = json.loads(self.state.read_text())
        self.assertEqual(sum(path.endswith("/nodes/2") for path in state["calls"]), 2)
        self.assertEqual(sum(path.endswith("/nodes/2") for path in state["reads"]), 1)
        self.assertEqual(sum(path.endswith("/plan") for path in state["reads"]), 1)
        self.assertEqual(sum(path.endswith("/signals") for path in state["reads"]), 1)


if __name__ == "__main__":
    unittest.main()
