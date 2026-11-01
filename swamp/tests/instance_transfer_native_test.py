"""Native transfer graph with real disposable source/archive effects and no fleet publication."""
import json
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class InstanceTransferNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors("operations/instance-source-command", "operations/instance-lifecycle", "operations/tools",
                            "operations/routes/mutation-command", "operations/routes/publication-command", "operations/routes/update-command")
        fake = self.repo / "ssh"
        fake.write_text("#!" + sys.executable + "\n" + '''import shlex,subprocess,sys
result=subprocess.run(shlex.split(sys.argv[-1]),input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
''')
        fake.chmod(0o755)
        scp = self.repo / "scp"
        scp.write_text("#!" + sys.executable + "\n" + '''import shlex,shutil,sys
from pathlib import Path
paths=[]
for value in sys.argv[-2:]:
    if '@' in value and ':' in value:value=shlex.split(value.split(':',1)[1])[0]
    paths.append(value)
source,destination=map(Path,paths)
if source.is_dir():shutil.copytree(source,destination/source.name,dirs_exist_ok=True)
else:shutil.copyfile(source,destination)
''')
        scp.chmod(0o755)
        self.source = self.repo / "source"
        self.source.mkdir()
        self.git(self.source, "init", "-q")
        self.git(self.source, "config", "user.name", "Fixture")
        self.git(self.source, "config", "user.email", "fixture@example.test")
        self.git(self.source, "remote", "add", "origin", "https://github.com/example/project.git")
        (self.source / "tracked").write_text("committed\n")
        self.git(self.source, "add", "tracked")
        self.git(self.source, "commit", "-qm", "fixture")
        (self.source / ".env").write_text("PRIVATE_SENTINEL=retained\n")
        self.destination = self.repo / "destination"
        self.child = yaml.safe_load((ROOT / "workflows/workflow-operation-instance-transfer.yaml").read_text())
        for job in self.child["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
                task = step["task"]
                if task.get("behaviorType") == "@swamp/ssh":
                    task["globalArgs"].update(sshBinary=str(fake), scpBinary=str(scp))
        for mode in ("forward", "restore"):
            child = yaml.safe_load((ROOT / f"workflows/workflow-route-mutation-{mode}.yaml").read_text())
            self.assertEqual(child.pop("target"), "orbit-node-209")
            (self.repo / f"workflows/workflow-route-mutation-{mode}.yaml").write_text(yaml.safe_dump(child, sort_keys=False))
        self.workflow_path = self.repo / "workflows/workflow-operation-instance-transfer.yaml"
        self.workflow_path.write_text(yaml.safe_dump(self.child, sort_keys=False))
        def ssh(host):
            return {"identity_file": "/fixture/identity", "target": {"host": host, "port": 22, "user": "orbit", "host_keys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]}}
        stage = {"publications": [], "prepare_certificates": [], "certificates": [], "cleanup": [], "lan": [], "retire_lan": [], "url_writes": [], "routes": [], "ingress": [], "retire_ingress": []}
        self.intent = {"instance_id": 8, "transfer_id": "15427c8e-0a63-431a-84c7-835bf2730818", "source_node_id": 9, "destination_node_id": 13,
                       "source": {"source": str(self.source), "repository_identity": "github.com/example/project", "ssh_execution": ssh("192.0.2.9")},
                       "destination": {"destination": str(self.destination), "root": str(self.repo), "application": str(self.destination), "environment": str(self.destination / ".env"), "ssh_execution": ssh("192.0.2.13")},
                       "processes": {"source": [], "destination": []}, "workloads": [], "route_mutations": {"forward": stage, "restore": stage}}

    def git(self, path, *args):
        return subprocess.run(["git", "-C", str(path), *args], capture_output=True, check=True).stdout.decode().strip()

    def run_transfer(self, success=True):
        argv = ["workflow", "run", "operation-instance-transfer"]
        for key, value in {"operation_id": 7003, "node_id": 9, "kind": "instance.transfer", "intent": self.intent, "execution_secrets": {}}.items():
            argv += ["--input", key + "=" + (value if isinstance(value, str) else json.dumps(value))]
        run = json.loads(self.cli(*argv, success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        return run, history

    def test_transfer_keeps_private_archive_bytes_out_of_results_and_retires_only_source(self):
        run, history = self.run_transfer()
        self.assertEqual(run["status"], "succeeded")
        self.assertFalse(self.source.exists())
        self.assertTrue((self.destination / ".git").is_dir())
        self.assertEqual((self.destination / ".env").read_text(), "PRIVATE_SENTINEL=retained\n")
        self.assertEqual((self.destination / ".env").stat().st_mode & 0o777, 0o640)
        self.assertNotIn("PRIVATE_SENTINEL", json.dumps(history))
        result = json.loads(next(step["outputs"]["stdout"] for step in history["jobs"][0]["steps"] if step["name"] == "result"))
        self.assertTrue(result["cutover"])
        self.assertFalse(result["restored"])

    def test_retirement_failure_retains_physical_cutover_result_and_source_residue(self):
        for step in self.child["jobs"][0]["steps"]:
            if step["name"] == "retire-source":
                step["task"]["methodName"] = "exec"
                step["task"]["inputs"] = {"hosts": "name:target", "argv": ["/usr/bin/false"]}
        self.workflow_path.write_text(yaml.safe_dump(self.child, sort_keys=False))
        run, history = self.run_transfer(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertTrue(self.source.exists())
        self.assertTrue(self.destination.exists())
        result = json.loads(next(step["outputs"]["stdout"] for step in history["jobs"][0]["steps"] if step["name"] == "result"))
        self.assertTrue(result["cutover"])

    def test_activation_failure_restores_source_and_discards_only_proven_destination(self):
        self.intent["processes"]["destination"] = [{}]
        for step in self.child["jobs"][0]["steps"]:
            if step["name"] in ("activate", "discard-destination-runtime"):
                step["task"] = {"type": "behavior", "behaviorType": "command/shell",
                    "behaviorName": '${{ "fixture-' + step["name"] + '-" + run.id + "-" + string(self._index) }}',
                    "methodName": "execute", "inputs": {"run": "false" if step["name"] == "activate" else "true"}}
        self.workflow_path.write_text(yaml.safe_dump(self.child, sort_keys=False))
        run, history = self.run_transfer(success=False)
        self.assertEqual(run["status"], "failed")
        self.assertTrue(self.source.exists())
        self.assertFalse(self.destination.exists(), json.dumps([{k: step[k] for k in ("name", "status", "error", "outputs") if k in step} for step in history["jobs"][0]["steps"] if step["name"] in ("forward", "restore", "discard-assets", "discard", "activate-0", "discard-destination-runtime-0", "result")]))
        result = json.loads(next(step["outputs"]["stdout"] for step in history["jobs"][0]["steps"] if step["name"] == "result"))
        self.assertFalse(result["cutover"])
        self.assertTrue(result["restored"], json.dumps([{k:step[k] for k in ("name","status","error","outputs") if k in step} for step in history["jobs"][0]["steps"] if step["name"] in ("forward","restore","discard-assets","discard","result") or step["status"] == "failed"]))
