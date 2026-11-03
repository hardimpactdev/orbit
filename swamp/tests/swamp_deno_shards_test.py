"""Exercise the workflow's actual shard command and strict aggregate."""
import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DENO = os.environ.get("DENO_BIN", "deno")


class DenoShards(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        parsed = subprocess.run([DENO, "eval",
            "import {parse} from 'jsr:@std/yaml@1.0.12'; console.log(JSON.stringify(parse(Deno.readTextFileSync(Deno.args[0]))));",
            str(ROOT.parent / ".github/workflows/swamp-shape.yml")],
            capture_output=True, text=True, check=True)
        cls.workflow = json.loads(parsed.stdout)
        cls.jobs = cls.workflow["jobs"]

    def test_main_swamp_runs_do_not_share_the_pending_slot(self):
        self.assertEqual(self.workflow["concurrency"], {
            "group": "swamp-shape-${{ github.workflow }}-${{ github.ref == 'refs/heads/main' && github.sha || github.ref }}",
            "cancel-in-progress": "${{ github.event_name == 'pull_request' }}",
        })

    def test_account_gated_native_shards_cover_each_tracked_module_once(self):
        job = self.jobs["native-tests"]
        self.assertFalse(job["strategy"]["fail-fast"])
        self.assertNotIn("continue-on-error", job)
        command = next(step for step in job["steps"] if step.get("name") == "Run the native workflow tests")
        self.assertEqual(command["env"]["NATIVE_SHARD"], "${{ matrix.shard }}")
        with tempfile.TemporaryDirectory(prefix="native-shards-") as scratch:
            root = Path(scratch)
            subprocess.run(["git", "init", "--quiet", root], check=True)
            harness = root / "tests/_support/native_harness.ts"
            harness.parent.mkdir(parents=True)
            harness.write_text('console.log("unused-fixture-runtime");\n')
            modules = root / "extensions/behaviors/operations/fixture"
            modules.mkdir(parents=True)
            for index in range(16):
                name = "native_test.ts" if index == 0 else f"{index:02}_native_test.ts"
                (modules / name).write_text(
                    f'Deno.test("module {index}", () => console.log("COVERED_FILE_{index}"));\n')
            # Untracked native tests and ordinary tests must not enter this credentialed job.
            subprocess.run(["git", "add", "."], cwd=root, check=True)
            for name in ("untracked_native_test.ts", "ordinary_test.ts"):
                (modules / name).write_text('Deno.test("excluded", () => { throw new Error("ran"); });\n')

            def run(shard):
                return subprocess.run(["bash", "-e", "-o", "pipefail", "-c", command["run"]], cwd=root,
                                      env=dict(os.environ, NATIVE_SHARD=shard), capture_output=True, text=True, timeout=120)

            covered = []
            shards = job["strategy"]["matrix"]["shard"]
            self.assertEqual(shards, ["1/4", "2/4", "3/4", "4/4"])
            for shard in shards:
                result = run(shard)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                covered.extend(int(value) for value in re.findall(r"COVERED_FILE_(\d+)", result.stdout))
            self.assertCountEqual(covered, range(16))
            (modules / "native_test.ts").write_text('Deno.test("failure", () => { throw new Error("expected"); });\n')
            self.assertEqual(sum(run(shard).returncode != 0 for shard in shards), 1)

    def test_native_shards_cover_every_module_once_and_preserve_failure(self):
        job = self.jobs["test-shards"]
        self.assertFalse(job["strategy"]["fail-fast"])
        self.assertNotIn("continue-on-error", job)
        command = next(step for step in job["steps"] if step.get("name") == "Run Operations test shard")
        self.assertEqual(command["env"]["DENO_SHARD"], "${{ matrix.shard }}")
        self.assertEqual(command["env"]["DENO_TEST_PATH"], "${{ matrix.path }}")
        self.assertEqual(command["env"]["DENO_IGNORE"], "${{ matrix.ignore }}")
        with tempfile.TemporaryDirectory(prefix="deno-shards-") as scratch:
            root = Path(scratch)
            files = root / "extensions/behaviors/operations"
            files.mkdir(parents=True)
            groups = ((".", 9), ("database", 9), ("process-commands", 9))
            identifier = 0
            for path, count in groups:
                directory = files / path
                directory.mkdir(parents=True, exist_ok=True)
                for index in range(count):
                    (directory / f"{index:02}_test.ts").write_text(
                        f'Deno.test("module {identifier}", () => console.log("COVERED_FILE_{identifier}"));\n')
                    identifier += 1
            binary = root / "bin"
            binary.mkdir()
            (binary / "deno").symlink_to(shutil.which(DENO))

            def run(shard):
                env = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ["PATH"],
                           DENO_SHARD=shard["shard"], DENO_TEST_PATH=shard["path"],
                           DENO_IGNORE=shard.get("ignore", ""), DENO_JOBS=str(command["env"]["DENO_JOBS"]))
                # deno test --parallel can deadlock on its own worker socket; fail in minutes, not at the job limit.
                return subprocess.run(["bash", "-e", "-o", "pipefail", "-c", command["run"]], stdin=subprocess.DEVNULL,
                                      cwd=root, env=env, capture_output=True, text=True, timeout=120)

            covered = []
            shards = job["strategy"]["matrix"]["include"]
            for shard in shards:
                result = run(shard)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                covered.extend(int(value) for value in re.findall(r"COVERED_FILE_(\d+)", result.stdout))
            self.assertCountEqual(covered, range(identifier))
            # Native workflow tests need the account-gated runtime; the shards skip them.
            for path, _count in groups:
                (files / path / "zz_native_test.ts").write_text('Deno.test("native", () => { throw new Error("ran"); });\n')
                (files / path / "native_test.ts").write_text('Deno.test("native", () => { throw new Error("ran"); });\n')
            for shard in shards:
                result = run(shard)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            (files / "00_test.ts").write_text('Deno.test("real failure", () => { throw new Error("expected"); });\n')
            self.assertNotEqual(run(shards[0]).returncode, 0)

    def test_aggregate_requires_success_from_the_entire_matrix(self):
        aggregate = self.jobs["tests"]
        self.assertEqual(aggregate["name"], "Swamp Deno tests")
        self.assertEqual(aggregate["needs"], ["test-shards", "native-tests"])
        self.assertEqual(aggregate["if"], "always()")
        self.assertNotIn("continue-on-error", aggregate)
        step = aggregate["steps"][0]
        self.assertEqual(step["env"]["SHARDS_RESULT"], "${{ needs.test-shards.result }}")
        self.assertEqual(step["env"]["NATIVE_RESULT"], "${{ needs.native-tests.result }}")
        for shards in ("success", "failure", "cancelled", "skipped", "", "unknown"):
            for native in ("success", "failure", "skipped", ""):
                result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", step["run"]],
                                        env=dict(os.environ, SHARDS_RESULT=shards, NATIVE_RESULT=native),
                                        capture_output=True, text=True)
                self.assertEqual(result.returncode == 0, shards == native == "success", (shards, native))


if __name__ == "__main__":
    unittest.main()
