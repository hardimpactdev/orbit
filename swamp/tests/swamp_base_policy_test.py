"""Frozen real policy must reject new debt even if HEAD changes rules and baseline."""
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DENO = os.environ.get("DENO_BIN", "deno")


class BasePolicy(unittest.TestCase):
    def test_real_frozen_policy_owns_limits_and_dependencies(self):
        with tempfile.TemporaryDirectory(prefix="shape-base-fixture-") as scratch:
            repo = Path(scratch)
            def git(*args):
                return subprocess.run(["git", *args], cwd=repo, capture_output=True, text=True, check=True).stdout.strip()
            git("init", "--quiet")
            git("config", "user.name", "Shape fixture")
            git("config", "user.email", "shape@example.invalid")
            swamp = repo / "swamp"
            for directory in ("bin", "config", "extensions/behaviors"):
                (swamp / directory).mkdir(parents=True, exist_ok=True)
            checker = swamp / "bin/check-swamp-shape.ts"
            checker.write_text("import {proof} from './policy.ts'; if(proof !== 1) throw new Error('Untrusted policy dependency');\n" + (ROOT / "bin/check-swamp-shape.ts").read_text())
            (swamp / "bin/policy.ts").write_text("export const proof=1;\n")
            (swamp / "bin/swamp-shape-relocations.ts").write_bytes((ROOT / "bin/swamp-shape-relocations.ts").read_bytes())
            (swamp / "bin/workflow-placement.ts").write_bytes((ROOT / "bin/workflow-placement.ts").read_bytes())
            (swamp / "bin/swamp-shape-python.py").write_bytes((ROOT / "bin/swamp-shape-python.py").read_bytes())
            (swamp / "bin/swamp_shape_host_tools.py").write_bytes((ROOT / "bin/swamp_shape_host_tools.py").read_bytes())
            (swamp / "bin/bounded-phase-scripts.ts").write_bytes((ROOT / "bin/bounded-phase-scripts.ts").read_bytes())
            (swamp / "bin/swamp_shape_polling.py").write_bytes((ROOT / "bin/swamp_shape_polling.py").read_bytes())
            (swamp / "config/agent-command-kinds.json").write_text('{"agent.status":"observer"}')
            source = swamp / "extensions/behaviors/old.ts"
            source.write_text("export const behavior={type:'@fixture/old'};\nawait delay(10);\n")
            def commit(message):
                git("add", ".")
                git("commit", "--quiet", "-m", message)
                return git("rev-parse", "HEAD")
            original = commit("old source")
            init = subprocess.run([DENO, "run", "-A", str(checker), "--base", original, "--write-baseline"], cwd=swamp, capture_output=True, text=True)
            self.assertEqual(init.returncode, 0, init.stderr)
            base = commit("frozen rules and debt allowance")
            (swamp / "config/swamp-shape-baseline.json").write_text(json.dumps({"schema": 1, "entries": []}))
            checker.write_text("Deno.exit(0);\n")
            (swamp / "bin/policy.ts").write_text("export const proof=0;\n")
            commit("new rules classify original source differently")
            def check():
                before = git("status", "--porcelain")
                p = subprocess.run(["python3", str(ROOT / "bin/check-swamp-base-policy.py"), "--base", base], cwd=swamp, env={**os.environ, "DENO_BIN": DENO}, capture_output=True, text=True)
                self.assertEqual(git("status", "--porcelain"), before, "Base-policy check must leave the caller untouched")
                self.assertEqual(len(git("worktree", "list", "--porcelain").split("worktree ")), 2)
                return p
            self.assertEqual(check().returncode, 0)
            source.write_text(source.read_text() + "await delay(20);\n")
            commit("new debt cannot hide behind changed rules or baseline")
            p = check()
            self.assertNotEqual(p.returncode, 0)
            self.assertIn("custom-control 2 exceeds 1", p.stderr)
            source.write_text("export const behavior={type:'@fixture/old'};\nawait delay(10);\n")
            source.rename(source.with_name("renamed.ts"))
            commit("renaming cannot move a frozen allowance")
            self.assertNotEqual(check().returncode, 0)
            source.with_name("renamed.ts").write_text("export const behavior={type:'@fixture/old'};\n")
            commit("actual debt removal has no stale allowance failure")
            self.assertEqual(check().returncode, 0)


if __name__ == "__main__":
    unittest.main()
