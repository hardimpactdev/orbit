"""Exercise the real inline classifier, evidence reader and native fan-out."""

import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml
from native_harness import NativeTestCase

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / "workflows/workflow-automatic-production-smoke.yaml"


class AutomaticProductionSmokeTest(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.workflow = yaml.safe_load(WORKFLOW.read_text())
        self.steps = {
            step["name"]: step for job in self.workflow["jobs"] for step in job["steps"]
        }

    def script(self, name, values):
        return subprocess.run(
            ["/bin/sh", "-eu", "-c", self.steps[name]["task"]["inputs"]["run"]],
            env={**os.environ, **values},
            text=True,
            capture_output=True,
            check=True,
        ).stdout

    def evidence(self, xml, exit_code="0"):
        with tempfile.TemporaryDirectory() as directory:
            report = Path(directory) / "storage/framework/testing/smoke.xml"
            report.parent.mkdir(parents=True)
            if xml is not None:
                report.write_text(xml)
            return json.loads(
                self.script(
                    "evidence-${{ self.site.instance_id }}",
                    {
                        "CLIENT": directory,
                        "INSTANCE_ID": "757",
                        "SMOKE_COMMIT": "a" * 40,
                        "SMOKE_BASE_URL": "https://nckrtl.com",
                        "SMOKE_EXIT_CODE": exit_code,
                    },
                )
            )

    def test_only_positive_clean_non_skipped_success_proves_pass(self):
        result = self.evidence('<testsuite><testcase name="mount"/></testsuite>')
        self.assertEqual((result["state"], result["tests"]), ("passed", 1))
        self.assertEqual(result["instance_id"], 757)
        self.assertEqual(result["commit"], "a" * 40)

    def test_verified_junit_failure_is_distinct_from_transport_or_setup_failure(self):
        for child in ("failure", "error"):
            with self.subTest(child=child):
                result = self.evidence(
                    f"<testsuite><testcase><{child}/></testcase></testsuite>", "1"
                )
                self.assertEqual(result["state"], "failed")
        for report, exit_code in [
            (None, "1"),
            ("invalid XML", "0"),
            ("<testsuite/>", "0"),
            ("<testsuite><testcase><skipped/></testcase></testsuite>", "0"),
            ("<testsuite><testcase/></testsuite>", "1"),
            (
                '<!DOCTYPE x [<!ENTITY unsafe "x">]><testsuite><testcase/></testsuite>',
                "0",
            ),
        ]:
            with self.subTest(report=report, exit_code=exit_code):
                self.assertEqual(self.evidence(report, exit_code)["state"], "unknown")

    def test_inline_shell_is_clean(self):
        for name, step in self.steps.items():
            if step["task"]["behaviorType"] != "command/shell":
                continue
            with self.subTest(step=name):
                subprocess.run(
                    ["shellcheck", "--shell=sh", "-"],
                    input=step["task"]["inputs"]["run"],
                    text=True,
                    capture_output=True,
                    check=True,
                )

    def test_native_foreach_reports_each_site_even_when_one_test_fails(self):
        # Real native CEL expansion and command/shell execution; package tools are
        # replaced by harmless fixtures. This is not a remote worker canary.
        root = self.repo
        workflow = self.workflow
        self.copy_behaviors("operations/production-smoke")
        fixture_tools = root / "tools"
        fixture_tools.mkdir()
        sudo = fixture_tools / "sudo"
        sudo.write_text('#!/bin/sh\nset -eu\nshift 3\nexec "$@"\n')
        sudo.chmod(0o755)
        composer = fixture_tools / "composer"
        composer.write_text("""#!/bin/sh
set -eu
client=${1#--working-dir=}
case "$2" in
install) exit 0 ;;
test:smoke)
  mkdir -p "$client/storage/framework/testing"
  case "$SMOKE_BASE_URL" in
https://fail.example) printf '<testsuite><testcase><failure/></testcase></testsuite>' > "$client/storage/framework/testing/smoke.xml"; exit 1 ;;
*) printf '<testsuite><testcase/></testsuite>' > "$client/storage/framework/testing/smoke.xml" ;;
  esac ;;
esac
""")
        composer.chmod(0o755)
        for tool in ("npm", "npx"):
            target = fixture_tools / tool
            target.write_text("#!/bin/sh\nexit 0\n")
            target.chmod(0o755)
        mirror = root / "mirror"
        subprocess.run(
            ["timeout", "60", "git", "init", "-q", "-b", "main", str(mirror)],
            check=True,
        )
        (mirror / ".env.example").write_text("APP_ENV=testing\n")
        for args in (
            ["add", ".env.example"],
            [
                "-c",
                "user.name=Fixture",
                "-c",
                "user.email=fixture@example.invalid",
                "commit",
                "-qm",
                "fixture",
            ],
        ):
            subprocess.run(
                ["timeout", "60", "git", "-C", str(mirror), *args], check=True
            )
        (mirror / "harmless-proof.txt").write_text("harmless loaded change\n")
        subprocess.run(
            [
                "timeout",
                "60",
                "git",
                "-C",
                str(mirror),
                "add",
                "harmless-proof.txt",
            ],
            check=True,
        )
        subprocess.run(
            [
                "timeout",
                "60",
                "git",
                "-C",
                str(mirror),
                "-c",
                "user.name=Fixture",
                "-c",
                "user.email=fixture@example.invalid",
                "commit",
                "-qm",
                "harmless change",
            ],
            check=True,
        )
        commit = subprocess.check_output(
            ["timeout", "60", "git", "-C", str(mirror), "rev-parse", "HEAD"],
            text=True,
        ).strip()
        for job in workflow["jobs"]:
            for step in job["steps"]:
                step.pop("target", None)
                step.pop("labels", None)
                if step["task"]["behaviorType"] != "command/shell":
                    continue
                values = step["task"]["inputs"].setdefault("env", {})
                values["PATH"] = str(fixture_tools) + os.pathsep + os.environ["PATH"]
                if "RECOVERY_CLIENT" in values:
                    values["RECOVERY_CLIENT"] = str(root / "revert")
                if "CLIENT" in values:
                    values["CLIENT"] = (
                        '${{ "'
                        + str(root)
                        + '/clients/" + string(self.site.instance_id) }}'
                    )
        (root / "workflows/workflow-automatic-production-smoke.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False)
        )
        sites = [
            {
                "instance_id": number,
                "project_id": number,
                "worker_node_id": 9,
                "mirror_path": str(mirror),
                "commit": commit,
                "deployment_id": number,
                "base_url": origin,
            }
            for number, origin in [
                (1, "https://pass.example"),
                (2, "https://fail.example"),
            ]
        ]
        inputs = {
            "operation_id": 1,
            "kind": "production.smoke",
            "execution_secrets": {},
            "node_id": 209,
            "intent": {
                "change": {
                    "source": "swamp_load",
                    "before_sha": "a" * 40,
                    "after_sha": commit,
                    "source_mirror_path": str(mirror),
                    "merge_mainline": 0,
                    "orbit_sha": "b" * 40,
                    "swamp_tree_sha": "c" * 40,
                    "changed_paths": ["swamp/config/test.json"],
                    "previous_release_id": "",
                },
                "sites": sites,
            },
        }
        self.cli(
            "workflow",
            "run",
            "automatic-production-smoke",
            "--stdin",
            input=json.dumps(inputs),
            success=False,
        )
        history = self.cli("workflow", "history", "get", "automatic-production-smoke")
        result = json.loads(history.stdout)
        self.assertTrue(
            all(
                "outputs" in step
                for job in result["jobs"]
                for step in job["steps"]
                if job["name"] == "result" and step["name"] == "result"
            ),
            json.dumps(result)[-10000:],
        )
        output = next(
            step["outputs"]["stdout"]
            for job in result["jobs"]
            for step in job["steps"]
            if job["name"] == "result" and step["name"] == "result"
        )
        summary = json.loads(output)
        self.assertFalse((root / "revert/harmless-proof.txt").exists())
        self.assertTrue((mirror / "harmless-proof.txt").exists())
        self.assertEqual(summary["state"], "failed")
        self.assertEqual(
            [site["state"] for site in summary["sites"]], ["passed", "failed"]
        )


if __name__ == "__main__":
    unittest.main()
