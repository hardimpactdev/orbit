"""Complete retarget graph through official SSH; harmless local SSH/tool seams only."""

import json
import os
import re
import sys
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class RetargetNative(NativeTestCase):
    native_timeout = 120

    def test_role_owner_verification_and_roleless_peer_compensation(self):
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.copy_behaviors(
            "operations/node-hub-command", "operations/node-peer-command"
        )
        fixture = self.repo / "fixture"
        fixture.mkdir()
        key = fixture / "identity"
        key.write_text("synthetic SSH fixture identity")
        ssh = fixture / "ssh"
        ssh.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import shlex,subprocess,sys
body=sys.stdin.buffer.read()
argv=shlex.split(sys.argv[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv[:1]==['/usr/bin/systemctl']:argv=[sys.executable,TOOL,*argv]
result=subprocess.run(argv,input=body,capture_output=True)
sys.stdout.buffer.write(result.stdout)
sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
""".replace("TOOL", repr(str(fixture / "tool.py")))
        )
        ssh.chmod(0o755)
        tool = fixture / "tool.py"
        tool.write_text("""import pathlib,sys
root=pathlib.Path(__file__).parent
args=sys.argv[1:]
if args[:2]==['/usr/bin/systemctl','restart'] and (root/'fail').exists():
    (root/'fail').unlink()
    raise SystemExit(1)
if args[:2]==['/usr/bin/systemctl','is-active']:print('active')
elif args[:2]==['/usr/bin/systemctl','is-enabled']:print('enabled')
elif args[:2]==['/usr/bin/wg','pubkey']:print('A'*43+'=')
elif args[:2]==['/usr/bin/wg','genkey']:print('P'*43+'=')
elif args[:1]==['/usr/bin/wg-quick']:print(pathlib.Path(args[-1]).read_text())
elif args[:2]==['/usr/bin/wg','show']:print('orbit')
""")
        peer = fixture / "peer"
        hub = fixture / "hub"
        peer.mkdir(mode=0o700)
        hub.mkdir(mode=0o700)
        private = "P" * 43 + "="
        public = "A" * 43 + "="
        prior = f"[Interface]\nPrivateKey = {private}\nAddress = 10.44.0.2/24\n"
        (peer / "orbit.key").write_text(private + "\n")
        (peer / "orbit.conf").write_text(prior)
        (hub / "orbit.conf").write_text(
            f"[Interface]\nPrivateKey = {private}\nAddress = 10.44.0.1/24\nListenPort = 51820\n\n[Peer]\nPublicKey = {public}\nAllowedIPs = 10.44.0.2/32\n"
        )
        (hub / "orbit.conf").chmod(0o600)
        for name, directory in [("node-peer-command", peer), ("node-hub-command", hub)]:
            script = (
                self.repo
                / f"extensions/behaviors/operations/{name}/scripts"
                / ("common.py" if name == "node-peer-command" else "hub_peer.py")
            )
            source = (
                script.read_text()
                .replace("Path('/etc/wireguard')", f"Path({str(directory)!r})")
                .replace('Path("/etc/wireguard")', f"Path({str(directory)!r})")
            )
            source = source.replace("UID = 0", f"UID = {os.getuid()}")
            source = source.replace('dir="/run"', f"dir={str(fixture)!r}").replace(
                'Path("/run")', f"Path({str(fixture)!r})"
            )
            source = source.replace(
                'Path("/run/lock/orbit-wireguard-peer.lock")',
                f"Path({str(fixture / 'hub.lock')!r})",
            )
            source = "import sys\n" + source
            source = re.sub(
                r"(subprocess.run\(\s*\[)",
                r"\1sys.executable, " + repr(str(tool)) + ", ",
                source,
            )
            if name == "node-peer-command":
                source = re.sub(
                    r"subprocess\.run\(\s*argv\s*,",
                    "subprocess.run([sys.executable, " + repr(str(tool)) + ", *argv],",
                    source,
                )
            script.write_text(source)
        snapshot_script = (
            self.repo
            / "extensions/behaviors/operations/node-peer-command/scripts/snapshot.py"
        )
        snapshot_script.write_text(
            snapshot_script.read_text().replace('dir="/run"', f"dir={str(fixture)!r}")
        )
        model_path = Path("models/@swamp/ssh/wireguard-hub-1.yaml")
        model = yaml.safe_load((ROOT / model_path).read_text())
        model["globalArguments"]["transport"]["identityFile"] = str(key)
        (self.repo / model_path).parent.mkdir(parents=True)
        (self.repo / model_path).write_text(yaml.safe_dump(model))
        for workflow_file in (
            "workflow-operation-node-retarget.yaml",
            "workflow-operation-node-wireguard-key.yaml",
        ):
            workflow = yaml.safe_load((ROOT / "workflows" / workflow_file).read_text())
            for job in workflow["jobs"]:
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertEqual(step.pop("labels"), {"node": "209"})
                    task = step.get("task", {})
                    if task.get("behaviorType") == "@swamp/ssh":
                        globals_ = task["globalArgs"]
                        globals_["sshBinary"] = str(ssh)
                        if isinstance(globals_["transport"], dict):
                            globals_["transport"].pop("identityContent", None)
                            globals_["transport"]["identityFile"] = str(key)
            (self.repo / "workflows" / workflow_file).write_text(
                yaml.safe_dump(workflow, sort_keys=False)
            )
            self.cli(
                "workflow",
                "validate",
                workflow_file.removeprefix("workflow-").removesuffix(".yaml"),
            )
        target = {
            "host": "192.0.2.10",
            "port": 22,
            "user": "orbit",
            "host_keys": model["globalArguments"]["transport"]["hostKeys"],
        }
        entry = {
            "node_id": 2,
            "name": "peer",
            "address": "10.44.0.2",
            "public_key": public,
        }
        intent = {
            "node_id": 2,
            "has_roles": True,
            "target": target,
            "identity_file": str(key),
        }

        def run(current, success=True):
            result = json.loads(
                self.cli(
                    "workflow",
                    "run",
                    "operation-node-retarget",
                    "--input",
                    "operation_id=1",
                    "--input",
                    "node_id=14",
                    "--input",
                    "kind=node.retarget",
                    "--input",
                    "intent:json=" + json.dumps(current),
                    "--input",
                    "execution_secrets:json={}",
                    success=success,
                ).stdout
            )
            history = json.loads(
                self.cli("workflow", "history", "get", result["id"]).stdout
            )
            self.assertNotIn(private, json.dumps(history))
            return history

        existing = run(intent)
        self.assertEqual(existing["status"], "succeeded")
        terminal = next(
            step for step in existing["jobs"][0]["steps"] if step["name"] == "terminal"
        )
        self.assertTrue(json.loads(terminal["outputs"]["stdout"])["retargeted"])
        intent.update(
            has_roles=False,
            hub={
                "schema_version": 1,
                "hub_id": 1,
                "hub_address": "10.44.0.1/24",
                "port": 51820,
                "context_node_id": 2,
                "inventory": [entry],
                "before": entry,
                "desired": entry,
            },
            network={
                "existing_key": public,
                "peer": {
                    "server_public_key": "H" * 43 + "=",
                    "endpoint": "192.0.2.1:51820",
                    "address": "10.44.0.2/24",
                    "subnet": "10.44.0.0/24",
                    "dns_server": "10.44.0.1",
                    "domains": ["orbit"],
                    "operator_dns": "10.44.0.1",
                    "retain": True,
                },
            },
        )
        completed = run(intent)
        self.assertEqual(completed["status"], "succeeded")
        self.assertIn("192.0.2.1:51820", (peer / "orbit.conf").read_text())
        (peer / "orbit.conf").write_text(prior)
        (fixture / "fail").touch()
        failed = run(intent, success=False)
        terminal = next(
            step for step in failed["jobs"][0]["steps"] if step["name"] == "terminal"
        )
        self.assertTrue(json.loads(terminal["outputs"]["stdout"])["restored"])
        self.assertEqual((peer / "orbit.conf").read_text(), prior)
        self.assertEqual(list(fixture.glob("orbit-peer-backup-*")), [])

        changed_key = "B" * 43 + "="
        key_input = {
            "hub": intent["hub"],
            "prepared_peer": {"node_id": 2, "public_key": changed_key},
        }
        key_run = json.loads(
            self.cli(
                "workflow",
                "run",
                "operation-node-wireguard-key",
                "--input",
                "operation_id=2",
                "--input",
                "node_id=2",
                "--input",
                "kind=node.wireguard_key",
                "--input",
                "intent:json=" + json.dumps(key_input),
                "--input",
                "execution_secrets:json={}",
            ).stdout
        )
        key_history = json.loads(
            self.cli("workflow", "history", "get", key_run["id"]).stdout
        )
        self.assertEqual(key_history["status"], "succeeded")
        self.assertNotIn(private, json.dumps(key_history))
        key_terminal = next(
            step
            for step in key_history["jobs"][0]["steps"]
            if step["name"] == "terminal"
        )
        self.assertTrue(json.loads(key_terminal["outputs"]["stdout"])["projected"])
        self.assertIn(changed_key, (hub / "orbit.conf").read_text())
        self.assertNotIn(public, (hub / "orbit.conf").read_text())
