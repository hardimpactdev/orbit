"""Run the workflow's actual inline filesystem actions against disposable trees."""

import ast
import json
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

import yaml

WORKFLOW = Path(__file__).parents[1] / "workflows/workflow-operation-metrics-fleet.yaml"


def script(name):
    workflow = yaml.safe_load(WORKFLOW.read_text())
    job = next(j for j in workflow["jobs"] if j["name"] == name)
    run = job["steps"][0]["task"]["inputs"]["run"]
    if "<<'PYTHON'" in run:
        return run.split("<<'PYTHON'\n", 1)[1].rsplit("\nPYTHON", 1)[0]
    return (
        WORKFLOW.parents[1]
        / "extensions/behaviors/operations/metrics/scripts"
        / (name + ".py")
    ).read_text()


@unittest.skipUnless(
    os.geteuid() == 0,
    "Root custody fixtures: run sudo -n /usr/bin/python3 -m unittest discover -s tests -p metrics_command_steps_test.py",
)
class ExporterFiles(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory()
        self.root = Path(self.temporary.name)
        self.addCleanup(self.temporary.cleanup)
        self.intent = {
            "path": "/etc/systemd/system/orbit-cadvisor.service",
            "marker": "# Managed by Orbit: metrics-cadvisor\n",
            "configuration": "# Managed by Orbit: metrics-cadvisor\n[Service]\n",
            "selected": True,
        }

    def run_action(self, name, environment=None):
        tree = ast.parse(script(name))
        replacements = 0
        for node in tree.body:
            if isinstance(node, ast.Assign) and any(
                isinstance(t, ast.Name) and t.id == "root" for t in node.targets
            ):
                call = node.value
                if (
                    isinstance(call, ast.Call)
                    and isinstance(call.func, ast.Attribute)
                    and call.func.attr == "Path"
                    and len(call.args) == 1
                    and isinstance(call.args[0], ast.Constant)
                    and call.args[0].value == "/"
                ):
                    call.args[0] = ast.Constant(str(self.root))
                    replacements += 1
        self.assertEqual(
            replacements,
            1,
            "Refuse to execute an action without its disposable filesystem root",
        )
        program = ast.unparse(ast.fix_missing_locations(tree))
        return subprocess.run(
            ["/usr/bin/python3", "-c", program],
            text=True,
            capture_output=True,
            env={
                **os.environ,
                "ORBIT_EXPORTER_INTENT": json.dumps(self.intent),
                **(environment or {}),
            },
        )

    def test_publish_and_inspect_actual_file(self):
        result = self.run_action("publish-exporter-configuration")
        self.assertEqual(result.returncode, 0, result.stderr)
        target = self.root / self.intent["path"].lstrip("/")
        self.assertEqual(target.read_text(), self.intent["configuration"])
        self.assertEqual(target.stat().st_mode & 0o777, 0o644)
        before = self.run_action("inspect-exporter-files")
        self.assertEqual(
            json.loads(before.stdout)["configuration"], self.intent["configuration"]
        )

    def test_refuse_foreign_file_without_writing(self):
        target = self.root / self.intent["path"].lstrip("/")
        target.parent.mkdir(parents=True)
        target.write_text("foreign\n")
        result = self.run_action("publish-exporter-configuration")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(target.read_text(), "foreign\n")

    def test_refuse_symlink_parent_and_keep_referent(self):
        foreign = self.root / "foreign"
        foreign.mkdir()
        (self.root / "etc").mkdir()
        (self.root / "etc/systemd").symlink_to(foreign, target_is_directory=True)
        result = self.run_action("publish-exporter-configuration")
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(list(foreign.iterdir()), [])

    def test_remove_only_owned_unit(self):
        self.assertEqual(
            self.run_action("publish-exporter-configuration").returncode, 0
        )
        self.intent["selected"] = False
        self.assertEqual(
            self.run_action("publish-exporter-configuration").returncode, 0
        )
        self.assertFalse((self.root / self.intent["path"].lstrip("/")).exists())

    def test_refuse_writable_parent_before_mutation(self):
        parent = self.root / "etc/systemd/system"
        parent.mkdir(parents=True)
        parent.chmod(0o777)
        result = self.run_action("publish-exporter-configuration")
        self.assertNotEqual(result.returncode, 0)
        self.assertFalse((parent / "orbit-cadvisor.service").exists())

    def test_public_runtime_refuses_foreign_tree_without_marker(self):
        base = self.root / "etc/orbit/metrics"
        base.mkdir(parents=True)
        sentinel = base / "foreign"
        sentinel.write_text("preserve")
        result = self.run_action(
            "publish-runtime-files",
            {
                "ORBIT_RUNTIME_FILE": json.dumps(
                    {"path": "/etc/orbit/metrics/prometheus.yml", "content": "public"}
                )
            },
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(sentinel.read_text(), "preserve")
        self.assertFalse((base / "prometheus.yml").exists())

    def test_secret_is_published_only_as_a_protected_owner_file(self):
        base = self.root / "etc/orbit/metrics"
        (base / "grafana").mkdir(parents=True)
        (base / ".orbit-owner").write_text("metrics\n")
        sentinel = "disposable-grafana-test-value"
        result = self.run_action(
            "publish-grafana-password", {"ORBIT_GRAFANA_PASSWORD": sentinel}
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        path = base / "grafana/admin-password"
        self.assertEqual(path.read_text(), sentinel)
        self.assertEqual(path.stat().st_uid, 472)
        self.assertEqual(path.stat().st_mode & 0o777, 0o400)
        self.assertNotIn(sentinel, result.stdout + result.stderr)
        self.assertEqual(list((base / "grafana").glob(".orbit-password-*")), [])

    def test_secret_target_symlink_does_not_write_referent(self):
        base = self.root / "etc/orbit/metrics"
        (base / "grafana").mkdir(parents=True)
        (base / ".orbit-owner").write_text("metrics\n")
        foreign = self.root / "foreign"
        foreign.write_text("preserve")
        (base / "grafana/admin-password").symlink_to(foreign)
        result = self.run_action(
            "publish-grafana-password", {"ORBIT_GRAFANA_PASSWORD": "disposable"}
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(foreign.read_text(), "preserve")

    def test_runtime_publication_refuses_writable_owned_marker_directory(self):
        base = self.root / "etc/orbit/metrics"
        base.mkdir(parents=True)
        (base / ".orbit-owner").write_text("metrics\n")
        target = base / "prometheus.yml"
        target.write_text("preserve")
        base.chmod(0o777)
        result = self.run_action(
            "publish-runtime-files",
            {
                "ORBIT_RUNTIME_FILE": json.dumps(
                    {
                        "path": "/etc/orbit/metrics/prometheus.yml",
                        "content": "replacement",
                    }
                )
            },
        )
        self.assertNotEqual(result.returncode, 0)
        self.assertEqual(target.read_text(), "preserve")

    def test_binary_publication_refuses_existing_foreign_artifact(self):
        for name in ["cadvisor", "fpm-exporter"]:
            with self.subTest(name=name):
                target = self.root / ("usr/local/bin/orbit-" + name)
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_text("foreign artifact")
                (self.root / "run").mkdir(exist_ok=True)
                stage = "cadvisor" if name == "cadvisor" else "fpm"
                (self.root / ("run/orbit-metrics-" + stage + "-6080")).write_text(
                    "download placeholder"
                )
                result = self.run_action(
                    "publish-" + name + "-binary", {"ORBIT_OPERATION_ID": "6080"}
                )
                self.assertNotEqual(result.returncode, 0)
                self.assertIn("binary_ownership_drift", result.stderr)
                self.assertEqual(target.read_text(), "foreign artifact")


class NativeBindings(unittest.TestCase):
    def test_inspection_never_reads_its_own_output_before_execution(self):
        workflow = yaml.safe_load(WORKFLOW.read_text())
        job = next(j for j in workflow["jobs"] if j["name"] == "inspect-exporter-files")
        env = job["steps"][0]["task"]["inputs"]["env"]
        self.assertEqual(set(env), {"ORBIT_EXPORTER_INTENT", "ORBIT_ACTION_SCRIPT"})

    def test_sudo_preserves_each_declared_environment_binding_by_name(self):
        workflow = yaml.safe_load(WORKFLOW.read_text())
        for job in workflow["jobs"]:
            for step in job["steps"]:
                inputs = step.get("task", {}).get("inputs", {})
                run = inputs.get("run", "")
                if "sudo -n" not in run or not inputs.get("env"):
                    continue
                line = run.splitlines()[1]
                for name in inputs["env"]:
                    if name != "ORBIT_ACTION_SCRIPT":
                        self.assertIn(name, line, (job["name"], name))
                self.assertNotIn("--preserve-env=ALL", line)

    def test_runtime_preflight_is_an_ancestor_of_each_runtime_mutation(self):
        workflow = yaml.safe_load(WORKFLOW.read_text())
        graph = {
            j["name"]: [d["job"] for d in j.get("dependsOn", [])]
            for j in workflow["jobs"]
        }

        def ancestors(name):
            result = set()
            queue = list(graph[name])
            while queue:
                parent = queue.pop()
                if parent not in result:
                    result.add(parent)
                    queue.extend(graph[parent])
            return result

        for mutation in [
            "publish-runtime-files",
            "publish-grafana-password",
            "create-runtime-volume",
        ]:
            self.assertTrue(
                {
                    "inspect-runtime-container",
                    "check-runtime-recovery",
                    "inspect-runtime-files",
                }
                <= ancestors(mutation),
                mutation,
            )


class DockerHealthEvents(unittest.TestCase):
    def observe(self, status, event=None):
        import contextlib
        import io
        import runpy
        from types import SimpleNamespace
        from unittest.mock import Mock, patch

        state = {
            "Running": True,
            "StartedAt": "2026-10-31T13:00:00Z",
            "Health": {"Status": status},
        }
        events = Mock()
        events.stdout = io.StringIO(
            json.dumps(
                event
                or {
                    "Action": "health_status: healthy",
                    "Actor": {"Attributes": {"name": "orbit-metrics-grafana"}},
                }
            )
            + "\n"
        )
        path = (
            WORKFLOW.parents[1]
            / "extensions/behaviors/operations/metrics/scripts/verify-runtime-health.py"
        )
        output = io.StringIO()
        with (
            patch.dict(
                os.environ,
                {
                    "ORBIT_CONTAINER_INTENT": json.dumps(
                        {"name": "orbit-metrics-grafana"}
                    )
                },
            ),
            patch(
                "subprocess.run",
                return_value=SimpleNamespace(stdout=json.dumps([{"State": state}])),
            ),
            patch("subprocess.Popen", return_value=events) as subscribe,
            contextlib.redirect_stdout(output),
        ):
            runpy.run_path(str(path), run_name="__main__")
        return output.getvalue(), subscribe, events

    def test_healthy_container_needs_no_subscription(self):
        output, subscribe, _ = self.observe("healthy")
        self.assertEqual(json.loads(output), {"healthy": True})
        subscribe.assert_not_called()

    def test_starting_container_waits_for_docker_health_event_without_polling(self):
        output, subscribe, events = self.observe("starting")
        self.assertEqual(json.loads(output), {"healthy": True})
        args = subscribe.call_args.args[0]
        self.assertIn("event=health_status: healthy", args)
        self.assertEqual(args[args.index("--since") + 1], "2026-10-31T13:00:00Z")
        events.terminate.assert_called_once()
        events.wait.assert_called_once()

    def test_unhealthy_container_is_a_failure(self):
        with self.assertRaisesRegex(SystemExit, "metrics.runtime_not_healthy"):
            self.observe("unhealthy")

    def test_foreign_event_cannot_prove_readiness(self):
        with self.assertRaisesRegex(SystemExit, "metrics.runtime_not_healthy"):
            self.observe(
                "starting",
                {
                    "Action": "health_status: healthy",
                    "Actor": {"Attributes": {"name": "foreign"}},
                },
            )


if __name__ == "__main__":
    unittest.main()
