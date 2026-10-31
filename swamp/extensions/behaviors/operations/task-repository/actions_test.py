"""Exercise real repository actions and report rules without a Gateway or worker in the VM."""

import hashlib
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from typing import ClassVar

SCRIPTS = Path(__file__).with_name("scripts")
sys.path.insert(0, str(SCRIPTS))
spec = importlib.util.spec_from_file_location(
    "analysis_result", SCRIPTS / "analysis_result.py"
)
report_module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(report_module)


class RepositoryFixture(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(prefix="task-repository-")
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name)
        self.git("init", "-qb", "task-1")
        self.git("config", "user.email", "test@example.test")
        self.git("config", "user.name", "Test")
        self.git("remote", "add", "origin", "https://github.com/acme/test.git")
        self.input = {
            "group_id": 1,
            "instance_id": 2,
            "node_id": 3,
            "subtask": 4,
            "checkout_path": str(self.root),
            "script": "scripts/run",
            "args": [],
            "ssr_port": 4177,
            "operation_id": 8,
        }

    def git(self, *args):
        return (
            subprocess.check_output(
                ["git", "-C", str(self.root), *args], stderr=subprocess.DEVNULL
            )
            .decode()
            .strip()
        )

    def install(self, source, path="scripts/run"):
        target = self.root / path
        target.parent.mkdir(parents=True, exist_ok=True)
        target.write_text(source)
        target.chmod(0o755)
        self.git("add", ".")
        self.git("commit", "-qm", "fixture")
        return target

    def action(self, name, *args, env=None):
        result = subprocess.run(
            [
                sys.executable,
                "-B",
                str(SCRIPTS / name),
                *[json.dumps(arg) for arg in args],
            ],
            input=json.dumps(self.input),
            text=True,
            capture_output=True,
            env={**os.environ, **(env or {})},
            check=True,
        )
        self.assertEqual(result.stderr, "")
        return json.loads(result.stdout)

    def run_script(self):
        prepared = self.action("inspect.py")
        before = self.action("snapshot.py")
        execution = self.action("execute_script.py", prepared)
        after = self.action("snapshot.py")
        return self.action(
            "script_result.py",
            env={
                "ORBIT_BEFORE": json.dumps(before),
                "ORBIT_AFTER": json.dumps(after),
                "ORBIT_EXECUTION": json.dumps(execution),
            },
        )


class ScriptActions(RepositoryFixture):
    def test_literal_arguments_snapshots_and_real_index_preservation(self):
        self.install(
            '#!/usr/bin/python3\nimport json,pathlib,sys\npathlib.Path("args").write_text(json.dumps(sys.argv[1:]))\nprint("done")\n'
        )
        index = (self.root / ".git/index").read_bytes()
        self.input["args"] = ["$(touch never)", "a; echo nope", "", " spaces "]
        result = self.run_script()
        self.assertEqual(
            json.loads((self.root / "args").read_text()), self.input["args"]
        )
        self.assertFalse((self.root / "never").exists())
        self.assertEqual((self.root / ".git/index").read_bytes(), index)
        self.assertEqual(result["outcome"], "passed")
        self.assertTrue(result["changed"])

    def test_changed_untracked_nonexecutable_and_symlinked_entrypoints_refuse(self):
        target = self.install("#!/bin/sh\nexit 0\n")
        for mode in ("changed", "untracked", "mode", "symlink"):
            with self.subTest(mode=mode):
                if target.is_symlink():
                    target.unlink()
                target.write_text("#!/bin/sh\nexit 0\n")
                target.chmod(0o755)
                self.input["script"] = "scripts/run"
                if mode == "changed":
                    target.write_text("#!/bin/sh\necho changed\n")
                elif mode == "untracked":
                    self.input["script"] = "untracked"
                elif mode == "mode":
                    target.chmod(0o644)
                else:
                    target.unlink()
                    target.symlink_to(self.root / "missing")
                self.assertEqual(
                    self.action("inspect.py"),
                    {"error_code": "tasks.script_unavailable"},
                )

    def test_entrypoint_changed_between_steps_never_executes(self):
        target = self.install("#!/bin/sh\ntouch invoked\n")
        prepared = self.action("inspect.py")
        target.write_text("#!/bin/sh\ntouch changed\n")
        self.assertEqual(
            self.action("execute_script.py", prepared),
            {"error_code": "tasks.script_unavailable"},
        )
        self.assertFalse((self.root / "changed").exists())
        self.assertFalse((self.root / "invoked").exists())

    def test_nonzero_is_business_failure_with_clean_temporary_directory_and_closed_environment(
        self,
    ):
        self.install(
            '#!/usr/bin/python3\nimport os,pathlib,sys\nassert "ORBIT_SWAMP_RUN_TOKEN" not in os.environ\nassert "COMPOSER_AUTH" not in os.environ\nassert os.environ["INERTIA_SSR_PORT"] == "4177"\nprint(os.environ["TMPDIR"])\npathlib.Path(os.environ["TMPDIR"], "scratch").write_text("tmp")\nsys.exit(23)\n'
        )
        result = self.run_script()
        self.assertEqual(result["exit_code"], 23)
        self.assertEqual(result["outcome"], "failed")
        self.assertFalse(Path(result["output"].strip()).exists())

    def test_output_is_bounded_and_redacted_before_native_persistence(self):
        self.install(
            '#!/usr/bin/python3\nprint("line\\n" * 40000)\nprint("Authorization: Bearer hidden-value")\nprint("DB_PASSWORD=hidden-password")\nprint("API_KEY=hidden-key")\n'
        )
        result = self.run_script()
        self.assertLessEqual(len(result["output"].encode()), 16384)
        self.assertTrue(result["output_truncated"])
        self.assertNotIn("hidden-", json.dumps(result))


class AnalysisActions(RepositoryFixture):
    def analyzer(self, suffix=""):
        self.install(
            '#!/usr/bin/python3\nimport json,sys\nv=json.load(sys.stdin)\nr={k:v[k] for k in ("execution_id","plan_sha256","base","head","tree")}\nr.update(schema=1,status="ready",diagnostics=[],report={"schema":"test.v1","data":{"complete":"é" * 6000}})\nprint(json.dumps(r,ensure_ascii=False))\n'
            + suffix,
            "bin/task-analyze",
        )
        self.input.update(
            plan={"id": "test", "revision": 1},
            plan_sha256="b" * 64,
            base=self.git("rev-parse", "HEAD"),
            head=self.git("rev-parse", "HEAD"),
            tree=self.git("rev-parse", "HEAD^{tree}"),
            branch="task-1",
            repository="https://github.com/acme/test.git",
        )

    def run_analysis(self):
        prepared = self.action("inspect.py")
        before = self.action("snapshot.py")
        execution = self.action("execute_analysis.py", prepared, before)
        after = self.action("snapshot.py")
        return self.action(
            "analysis_result.py",
            env={
                "ORBIT_INPUT": json.dumps(self.input),
                "ORBIT_PREPARED": json.dumps(prepared),
                "ORBIT_BEFORE": json.dumps(before),
                "ORBIT_AFTER": json.dumps(after),
                "ORBIT_EXECUTION": json.dumps(execution),
            },
        )

    def test_complete_utf8_evidence_and_native_provenance(self):
        self.analyzer()
        result = self.run_analysis()
        self.assertEqual(result["outcome"], "ready")
        raw = result["artifact"]["content"].encode()
        self.assertEqual(result["artifact"]["sha256"], hashlib.sha256(raw).hexdigest())
        self.assertEqual(result["artifact"]["bytes"], len(raw))
        self.assertEqual(json.loads(raw)["report"]["data"]["complete"], "é" * 6000)
        self.assertEqual(result["provenance"]["mechanical_llm_calls"], 0)

    def test_stale_snapshot_never_runs_the_analyzer(self):
        self.analyzer()
        before = self.action("snapshot.py")
        prepared = self.action("inspect.py")
        self.input["head"] = "f" * 40
        self.assertEqual(
            self.action("execute_analysis.py", prepared, before),
            {"error_code": "tasks.analysis_stale"},
        )

    def test_analyzer_mutation_is_changed(self):
        self.analyzer('\nfrom pathlib import Path\nPath("changed").write_text("yes")\n')
        self.assertEqual(self.run_analysis()["outcome"], "changed")


class AnalysisReports(unittest.TestCase):
    identity: ClassVar[dict[str, str]] = {
        "execution_id": "a" * 32,
        "plan_sha256": "b" * 64,
        "base": "c" * 40,
        "head": "d" * 40,
        "tree": "e" * 40,
    }

    def envelope(self):
        return json.dumps(
            {
                "schema": 1,
                **self.identity,
                "status": "ready",
                "diagnostics": [],
                "report": {"schema": "test.v1", "data": {}},
            },
            separators=(",", ":"),
        )

    def test_strict_report_rejects_duplicate_trailing_nonfinite_deep_and_private_data(
        self,
    ):
        raw = self.envelope()
        for value in (
            raw.replace('"schema":1', '"schema":2,"schema":1'),
            raw + " trailing",
            raw.replace('"data":{}', '"data":{"x":1e999}'),
            raw.replace('"data":{}', '"data":{"x":NaN}'),
            raw.replace('"data":{}', '"data":{"x":' + "[" * 25 + "1" + "]" * 25 + "}"),
            raw.replace('"data":{}', '"data":{"x":1,"x":2}'),
            raw.replace(self.identity["head"], "f" * 40),
            raw.replace('"data":{}', '"data":{"API_KEY":"sensitive-value"}'),
        ):
            with (
                self.subTest(value=value[:25]),
                self.assertRaises((ValueError, TypeError)),
            ):
                report_module.report(value.encode(), self.identity, 0)

    def test_exact_size_boundary_and_closed_failure_metadata(self):
        value = json.loads(self.envelope())
        value["report"]["data"]["text"] = ""
        value["report"]["data"]["text"] = "x" * (65536 - len(json.dumps(value)))
        raw = json.dumps(value).encode()
        self.assertEqual(len(raw), 65536)
        self.assertEqual(report_module.report(raw, self.identity, 0)["status"], "ready")
        self.assertEqual(
            report_module.report(raw + b" ", self.identity, 0)["error_code"],
            "tasks.analysis_report_too_large",
        )
        for size in (None, True, "70000", 70000.0, -1, 65536, 9007199254740992):
            with self.subTest(size=size), self.assertRaises(ValueError):
                report_module.report(
                    json.dumps(
                        {
                            "schema": 1,
                            "error": {
                                "code": "tasks.analysis_report_too_large",
                                "required_bytes": size,
                            },
                        }
                    ).encode(),
                    self.identity,
                    1,
                )


if __name__ == "__main__":
    unittest.main()
