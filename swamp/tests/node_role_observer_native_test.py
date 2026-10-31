"""Existing observer identity and files through official SSH, with no worker actions."""

import hashlib
import json
import os
import pwd
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class RoleObserverNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-converge-command")
        self.copy_behaviors("operations/node-role-command")
        self.copy_behaviors("operations/node-bootstrap")
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        temporary = tempfile.TemporaryDirectory(prefix="observer-target-", dir=os.environ.get("TMPDIR"))
        self.addCleanup(temporary.cleanup)
        self.target = Path(temporary.name)
        self.audit = self.repo / "service-calls.jsonl"
        secret = "existing-observer-" + os.urandom(16).hex()
        self.digest = hashlib.sha256(secret.encode()).hexdigest()
        self.secrets = (secret, self.digest)
        self.identity = self.target / "existing-secret"
        self.identity.write_text(secret)
        self.identity.chmod(0o600)
        self.cli("vault", "create", "local_encryption", "role-observer-fixture")
        self.cli("vault", "put", "role-observer-fixture", "hash", input=self.digest)
        scripts = self.repo / "extensions/behaviors/operations/node-role-converge-command/scripts"
        identity = scripts / "observer_identity.py"
        identity.write_text(identity.read_text().replace('SECRET = Path("/etc/orbit/agent/secret")', f"SECRET = Path({str(self.identity)!r})"))
        file = scripts / "file.py"
        file.write_text(file.read_text().replace('path = Path(data["path"])', f'path = Path({str(self.target)!r}) / data["path"].lstrip("/")')
                        .replace('mode = int(data["mode"], 8)', 'uid, gid = os.getuid(), os.getgid()\n    mode = int(data["mode"], 8)'))
        observer_binary = self.target / "orbit-agent"
        observer_binary.write_text("synthetic pinned observer never executed")
        self.observer_pin = hashlib.sha256(observer_binary.read_bytes()).hexdigest()
        observer = self.repo / "extensions/behaviors/operations/node-bootstrap/scripts/bootstrap_observer.py"
        observer.write_text(observer.read_text().replace("TARGET = Path('/usr/local/bin/orbit-agent')", f"TARGET = Path({str(observer_binary)!r})"))
        binary = self.repo / "fixture-ssh"
        binary.write_text("#!" + sys.executable + "\n" + '''
import json,shlex,subprocess,sys
from pathlib import Path
argv=shlex.split(sys.argv[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv[:1]==['/usr/bin/systemctl']:
    if not Path(UNIT).is_file():raise SystemExit(1)
    with Path(AUDIT).open('a') as stream:stream.write(json.dumps(argv)+'\\n')
    raise SystemExit(0)
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
'''.replace("UNIT", repr(str(self.target / "etc/systemd/system/orbit-agent.service"))).replace("AUDIT", repr(str(self.audit))))
        binary.chmod(0o700)
        identity_file = self.repo / "identity"
        identity_file.write_text("synthetic unused transport identity")
        globals_ = {
            "name": "role-observer-fixture", "sshBinary": str(binary),
            "transport": {"kind": "ssh", "user": "orbit", "identityFile": str(identity_file),
                          "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]},
            "hosts": [{"name": "target", "address": "dev.invalid"}],
        }
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/observer_steps.ts").as_uri()
        accounts = (ROOT / "extensions/behaviors/operations/node-role-command/steps.ts").as_uri()
        program = (
            "import {roleObserverSteps,roleObserverInstallSteps} from " + json.dumps(source) + ";"
            "import {roleActionSteps} from " + json.dumps(accounts) + ";const globals=" + json.dumps(globals_) + ";"
            "const account=roleActionSteps('account','account'," + json.dumps(json.dumps({"user": pwd.getpwuid(os.getuid()).pw_name})) + ",globals);"
            "const install=roleObserverInstallSteps('install','inputs.intent.artifact',globals,'account');"
            "const steps=roleObserverSteps('observer','inputs.intent',globals,'account',\"vault.get('role-observer-fixture','hash')\",'steps.install.outputs.changed','install');"
            "console.log(JSON.stringify([...account,...install,...steps]));"
        )
        steps = json.loads(subprocess.run([deno, "eval", program], capture_output=True, text=True, check=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        parent = {
            "id": "d4358b92-ad26-42e7-bb6b-420f9508d84c", "name": "role-observer-fixture",
            "inputs": {"type": "object", "properties": {"intent": {"type": "object"}}},
            "vaults": ["role-observer-fixture"], "jobs": [{"name": "observer", "steps": steps}],
        }
        (self.repo / "workflows/workflow-role-observer-fixture.yaml").write_text(yaml.safe_dump(parent, sort_keys=False))
        self.cli("workflow", "validate", "role-observer-fixture")

    def run_observer(self, checkouts=(), success=True):
        path = self.repo / "intent.json"
        path.write_text(json.dumps({"intent": {"artifact": {"url": "https://artifact.invalid/observer", "sha256": self.observer_pin}, "footprint": {"payloads": {"agent": {
            "configuration": 'gateway_url = "https://gateway.orbit"\n', "certificate": "public-test-CA",
            "unit": "# Managed by Orbit: agent\n[Service]\nProtectHome=yes\n",
            "apps_root": None, "checkouts": list(checkouts),
        }}}}}))
        run = json.loads(self.cli("workflow", "run", "role-observer-fixture", "--input-file", str(path), success=success).stdout)
        return json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def calls(self):
        return [json.loads(line) for line in self.audit.read_text().splitlines()] if self.audit.exists() else []

    def test_existing_identity_files_checkout_and_service_without_worker_or_token_effects(self):
        checkout = self.target / "checkout"
        checkout.mkdir()
        environment = checkout / ".env"
        environment.write_text("SYNTHETIC_FIXTURE=private")
        environment.chmod(0o644)
        secret_before = self.identity.read_bytes()
        history = self.run_observer([str(checkout)])
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(environment.stat().st_mode & 0o777, 0o640)
        self.assertEqual(self.identity.read_bytes(), secret_before)
        unit = self.target / "etc/systemd/system/orbit-agent.service"
        self.assertIn("BindReadOnlyPaths=-" + pwd.getpwuid(os.getuid()).pw_dir + "/apps", unit.read_text())
        self.assertEqual([args[1] for args in self.calls()], ["daemon-reload", "enable", "restart", "is-active"])
        self.assertNotIn("swamp", json.dumps(self.calls()))
        self.assert_private_absent(json.dumps(history))

    def test_empty_checkout_fanout_preserves_file_order_and_current_files_skip_restart(self):
        self.assertEqual(self.run_observer()["status"], "succeeded")
        self.audit.unlink()
        self.assertEqual(self.run_observer()["status"], "succeeded")
        self.assertEqual([args[1] for args in self.calls()], ["daemon-reload", "enable", "is-active"])

    def test_wrong_existing_identity_blocks_all_files_and_service_effects(self):
        self.identity.write_text("unrecognized-local-identity")
        history = self.run_observer(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(self.calls(), [])
        self.assertFalse((self.target / "etc").exists())
        self.assert_private_absent(json.dumps(history))
