"""Gate acceptance, ratchet and relocation tests in private disposable Git trees."""
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DENO = os.environ.get("DENO_BIN", "deno")


class ShapeGate(unittest.TestCase):
    def test_python_secret_flow_is_from_environment_not_internal_argument_names(self):
        def scan(source):
            p = subprocess.run(["python3", str(ROOT / "bin/swamp-shape-python.py")], input=source, text=True, capture_output=True, check=True)
            return json.loads(p.stdout)
        self.assertEqual(scan("def install(WorkerCredentials):\n    subprocess.run(['tool', WorkerCredentials])\n"), [])
        self.assertEqual(scan("import os, subprocess\nvalue=os.environ['ORBIT_SECRET']\nsubprocess.run(['tool',value])\n")[0]["rule"], "secret-argv")
        self.assertEqual(scan("import os, subprocess\nvalue=os.environ['ORBIT_SECRET']\nsubprocess.run(['tool'], input=value)\n"), [])

    def test_gate_preserves_ratchet_and_metrics_when_run_from_swamp_subtree(self):
        with tempfile.TemporaryDirectory(prefix="shape-gate-") as scratch:
            root = Path(scratch)
            def git(*args):
                return subprocess.run(["git", *args], cwd=root, text=True, capture_output=True, check=True).stdout.strip()
            git("init", "--quiet")
            git("config", "user.email", "shape@example.invalid")
            git("config", "user.name", "Shape fixture")
            swamp = root / "swamp"
            (swamp / "config").mkdir(parents=True)
            (swamp / "extensions/behaviors").mkdir(parents=True)
            (swamp / "workflows").mkdir()
            source = swamp / "extensions/behaviors/old.ts"
            source.write_text("export const behavior={type:'@example/old'};\nawait delay(10);\n")
            (swamp / "config/agent-command-kinds.json").write_text('{"agent.status":"observer","cli.install":"action"}')
            (swamp / "workflows/clean.yaml").write_text("name: clean\njobs:\n- steps:\n  - task: {type: behavior, behaviorType: command/shell, inputs: {run: 'true'}}\n")
            git("add", ".")
            git("commit", "--quiet", "-m", "baseline source")
            def gate(*extra):
                return subprocess.run([DENO, "run", "-A", str(ROOT / "bin/check-swamp-shape.ts"), "--base", "HEAD", "--python-analyzer", str(ROOT / "bin/swamp-shape-python.py"), *extra], cwd=swamp, text=True, capture_output=True)
            p = gate("--write-baseline")
            self.assertEqual(p.returncode, 0, p.stderr)
            metrics = json.loads(p.stdout.splitlines()[0])
            self.assertEqual(metrics["workflows"]["clean"], 1)
            git("add", ".")
            git("commit", "--quiet", "-m", "record explicit baseline")
            self.assertEqual(gate().returncode, 0)
            source.write_text(source.read_text() + "await delay(20);\n")
            self.assertNotEqual(gate().returncode, 0, "Growing existing debt must fail")
            source.write_text("export const behavior={type:'@example/old'};\n")
            self.assertNotEqual(gate().returncode, 0, "Resolved debt must remove its allowance")
            self.assertEqual(gate("--write-baseline").returncode, 0)
            # Renaming cannot move the old file's allowance to a new path.
            source.rename(source.with_name("renamed.ts"))
            source.with_name("renamed.ts").write_text("await delay(10);\n")
            git("add", ".")
            self.assertNotEqual(gate().returncode, 0)

    def test_initial_import_uses_preserved_source_history_and_matching_repo_identity(self):
        with tempfile.TemporaryDirectory(prefix="shape-import-") as scratch:
            root = Path(scratch)
            def git(*args):
                return subprocess.run(["git", *args], cwd=root, text=True, capture_output=True, check=True).stdout.strip()
            git("init", "--quiet")
            git("config", "user.email", "shape@example.invalid")
            git("config", "user.name", "Shape fixture")
            (root / "config").mkdir()
            (root / "extensions/behaviors").mkdir(parents=True)
            (root / ".swamp.yaml").write_text("repoId: fixture-repository\n")
            (root / "config/agent-command-kinds.json").write_text('{"agent.status":"observer"}')
            (root / "config/swamp-shape-baseline.json").write_text('{"schema":1,"entries":[]}')
            (root / "extensions/behaviors/read.ts").write_text("export const value=1;\n")
            git("add", ".")
            git("commit", "--quiet", "-m", "original Swamp tree")
            source = git("rev-parse", "HEAD")
            swamp = root / "swamp"
            swamp.mkdir()
            for name in ["config", "extensions", ".swamp.yaml"]:
                (root / name).rename(swamp / name)
            git("add", ".")
            git("commit", "--quiet", "-m", "preserve source under swamp")
            command = [DENO,"run","-A",str(ROOT / "bin/check-swamp-shape.ts"),"--base",source,"--python-analyzer",str(ROOT / "bin/swamp-shape-python.py")]
            for event in ("pull_request", "push"):
                # The import PR and first main push both compare to a pre-import Orbit tree.
                result = subprocess.run(command, cwd=swamp, capture_output=True, text=True, env={**os.environ, "GITHUB_EVENT_NAME": event})
                self.assertEqual(result.returncode, 0, f"{event}: {result.stderr}")
            (swamp / ".swamp.yaml").write_text("repoId: unrelated-repository\n")
            result = subprocess.run(command, cwd=swamp, capture_output=True, text=True)
            self.assertNotEqual(result.returncode, 0)


if __name__ == "__main__":
    unittest.main()
