"""Production Process-definition parent with official cfgmgmt/SSH child DAGs."""

import copy
import hashlib
import json
from pathlib import Path
import sys
import unittest

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "extensions/behaviors/operations/process-commands"))
from lifecycle_native_test import ProcessLifecycleNative


class RuntimeMembers(ProcessLifecycleNative):
    # Parent tests reuse the host fixture, not the direct-kind test cases.
    def setUp(self):
        super().setUp()
        self.environment_paths = []
        workflow = yaml.safe_load((ROOT / "workflows/workflow-operation-process-install-instance-definitions.yaml").read_text())
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
        (self.repo / "workflows/workflow-operation-process-install-instance-definitions.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        # systemd serializes host requests. Serialize the fake's shared state too.
        systemctl = self.repo / "cfgmgmt-tools/systemctl"
        systemctl.write_text(systemctl.read_text().replace("import json,sys", "import json,sys,fcntl\nlock=open(" + repr(str(self.host / "systemd.lock")) + ", 'a')\nfcntl.flock(lock,fcntl.LOCK_EX)"))
        self.parent = {"operation_id": 17, "node_id": 9, "kind": "process.install_instance_definitions", "intent": {"instance_id": 4, "members": []}, "execution_secrets": {}}

    def add_member(self, process_id, desired="running", environment=None):
        intent = copy.deepcopy(self.inputs["intent"])
        control = intent["control"]
        control.update(process_id=process_id, name=f"orbit-process-{process_id}-proof.service")
        installation = intent["installation"]
        content = self.content.replace("Process-ID=17", f"Process-ID={process_id}")
        installation.update(process_id=process_id, name=control["name"], desired=desired, has_environment=environment is not None, environment_path=f"/etc/orbit/processes/{process_id}/" + "a" * 64 + ".env")
        if environment is not None:
            digest = hashlib.sha256(environment.encode()).hexdigest()
            path = f"/etc/orbit/processes/{process_id}/{digest}.env"
            content += "EnvironmentFile=" + path + "\n"
            installation["environment_path"] = path
            self.environment_paths.append(self.host_root / f"processes/{process_id}/{digest}.env")
            key = f"process-instance-operation-17-member-{process_id}"
            self.cli("vault", "put", "orbit-control-inputs", key, input=environment)
            self.parent["execution_secrets"][f"member_{process_id}"] = {"vault": "orbit-control-inputs", "key": key}
        installation.update(unit=content, content_hash=hashlib.sha256(content.encode()).hexdigest())
        self.parent["intent"]["members"].append({"id": process_id, "intent": intent})
        return self.host / "units" / control["name"]

    def run_parent(self, success=True):
        reply = self.cli("workflow", "run", "operation-process-install-instance-definitions", "--stdin", input=json.dumps(self.parent), success=success)
        self.run = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]
        self.assertEqual(self.run["status"], "succeeded" if success else "failed")

    def assert_private_absent(self, *outputs):
        for output in outputs:
            self.assertFalse(any(value in output for value in self.secrets))
        for path in self.repo.rglob("*"):
            if not path.is_file() or path.is_symlink() or path in getattr(self, "environment_paths", []):
                continue
            self.assertFalse(any(value.encode() in path.read_bytes() for value in self.secrets), "Private bytes outside EnvironmentFile: " + str(path))

    def test_two_definitions_keep_distinct_environment_copies_and_native_child_scopes(self):
        first = "# Orbit Process 7\nPASSWORD=first-private-fixture\n"
        second = "# Orbit Process 11\nPASSWORD=second-private-fixture\n"
        units = [self.add_member(7, environment=first), self.add_member(11, environment=second)]
        self.secrets = ("first-private-fixture", "second-private-fixture")
        self.run_parent()
        self.assertEqual([p.read_text() for p in self.environment_paths], [first, second])
        self.assertTrue(all(p.stat().st_mode & 0o777 == 0o600 for p in self.environment_paths))
        state = json.loads(self.state.read_text())
        self.assertTrue(all(p.exists() and state["units"][p.name]["active"] for p in units))
        self.assert_private_absent(self.cli("workflow", "history", "get", self.run["id"]).stdout)

    def test_definition_parent_preserves_stopped_intent_and_empty_selection(self):
        unit = self.add_member(7, desired="stopped")
        self.run_parent()
        state = json.loads(self.state.read_text())["units"][unit.name]
        self.assertTrue(unit.exists())
        self.assertFalse(state["active"])
        self.assertFalse(state["enabled"])
        self.parent["intent"]["members"] = []
        self.run_parent()

    def test_failed_child_retains_unit_for_normal_forward_convergence(self):
        unit = self.add_member(7)
        self.save_state(units={}, calls=[], failures=["start"])
        self.run_parent(success=False)
        self.assertTrue(unit.exists())
        self.run_parent()
        self.assertTrue(json.loads(self.state.read_text())["units"][unit.name]["active"])


def load_tests(loader, tests, pattern):
    return unittest.TestSuite(RuntimeMembers(name) for name in RuntimeMembers.__dict__ if name.startswith("test_"))
