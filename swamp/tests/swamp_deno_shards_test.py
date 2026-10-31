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
        cls.jobs = json.loads(parsed.stdout)["jobs"]

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
            for group, directory in enumerate((files, files / "database", files / "process-commands")):
                directory.mkdir(exist_ok=True)
                for index in range(9):
                    identifier = group * 9 + index
                    name = f"{index:02}_test.ts"
                    if group == 1 and index < 5:
                        name = ("local_sql_effect_test.ts", "read_effect_test.ts", "server-create-effects/mysql_test.ts",
                                "create_effect_test.ts", "write_effect_test.ts")[index]
                    (directory / name).parent.mkdir(exist_ok=True)
                    (directory / name).write_text(
                        f'Deno.test("module {identifier}", () => console.log("COVERED_FILE_{identifier}"));\n')
            for identifier, driver in enumerate(("pgsql", "redis"), start=27):
                (files / "database/server-create-effects" / f"{driver}_test.ts").write_text(
                    f'Deno.test("module {identifier}", () => console.log("COVERED_FILE_{identifier}"));\n')
            binary = root / "bin"
            binary.mkdir()
            (binary / "deno").symlink_to(shutil.which(DENO))

            def run(shard):
                env = dict(os.environ, PATH=str(binary) + os.pathsep + os.environ["PATH"],
                           DENO_SHARD=shard["shard"], DENO_TEST_PATH=shard["path"],
                           DENO_IGNORE=shard.get("ignore", ""), DENO_JOBS=str(command["env"]["DENO_JOBS"]))
                return subprocess.run(["bash", "-e", "-o", "pipefail", "-c", command["run"]],
                                      cwd=root, env=env, capture_output=True, text=True)

            covered = []
            shards = job["strategy"]["matrix"]["include"]
            for shard in shards:
                result = run(shard)
                self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
                covered.extend(int(value) for value in re.findall(r"COVERED_FILE_(\d+)", result.stdout))
            self.assertCountEqual(covered, range(29))
            (files / "00_test.ts").write_text('Deno.test("real failure", () => { throw new Error("expected"); });\n')
            self.assertNotEqual(run(shards[0]).returncode, 0)

    def test_aggregate_requires_success_from_the_entire_matrix(self):
        aggregate = self.jobs["tests"]
        self.assertEqual(aggregate["name"], "Swamp Deno tests")
        self.assertEqual(aggregate["needs"], ["test-shards"])
        self.assertEqual(aggregate["if"], "always()")
        self.assertNotIn("continue-on-error", aggregate)
        step = aggregate["steps"][0]
        self.assertEqual(step["env"]["SHARDS_RESULT"], "${{ needs.test-shards.result }}")
        for state in ("success", "failure", "cancelled", "skipped", "", "unknown"):
            result = subprocess.run(["bash", "-e", "-o", "pipefail", "-c", step["run"]],
                                    env=dict(os.environ, SHARDS_RESULT=state), capture_output=True, text=True)
            self.assertEqual(result.returncode == 0, state == "success", state)


if __name__ == "__main__":
    unittest.main()
