"""Canonical firewall policy through official SSH; all host commands are disposable."""
import json
import os
import shutil
import subprocess
import sys

import yaml
from native_harness import ROOT, NativeTestCase


class RoleFirewallNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-converge-command")
        routes = self.repo / "extensions/behaviors/operations/routes"
        routes.mkdir()
        for name in ("firewall-schema.ts", "firewall-program.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/routes" / name, routes)
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        self.calls = self.repo / "calls.jsonl"
        self.state = self.repo / "state.json"
        self.state.write_text(json.dumps({"active": True, "address": None}))
        tool = self.repo / "ufw"
        tool.write_text("#!" + sys.executable + "\n" + '''
import json,sys
from pathlib import Path
state=Path(STATE); data=json.loads(state.read_text()); args=sys.argv[1:]
with Path(CALLS).open('a') as stream: stream.write(json.dumps(args)+'\\n')
if args == ['status','numbered']:
    print('Status: '+('active' if data['active'] else 'inactive'))
    if data['address']: print('[1] '+data['address']+' on orbit ALLOW IN Anywhere # orbit:wireguard-members')
elif args[:2] == ['allow','in']:
    data['address']=args[args.index('to')+1]; state.write_text(json.dumps(data))
else: raise SystemExit(23)
'''.replace("STATE", repr(str(self.state))).replace("CALLS", repr(str(self.calls))))
        tool.chmod(0o700)
        ssh = self.repo / "ssh"
        ssh.write_text("#!" + sys.executable + "\n" + '''
import os,shlex,subprocess,sys
argv=shlex.split(sys.argv[-1]); body=sys.stdin.buffer.read().decode()
if argv[:3]==['sudo','-n','--']: argv=argv[3:]
body=body.replace("Path('/run/lock/orbit-firewall.lock')", 'Path('+repr(FIXTURE_LOCK)+')')
body=body.replace('LOCK_UID = 0', 'LOCK_UID = '+str(os.getuid()))
body=body.replace("'/usr/sbin/ufw'", repr(FIXTURE_TOOL))
result=subprocess.run(argv,input=body,text=True,capture_output=True)
sys.stdout.write(result.stdout);sys.stderr.write(result.stderr)
raise SystemExit(result.returncode)
'''.replace("FIXTURE_LOCK", repr(str(self.repo / "firewall.lock"))).replace("FIXTURE_TOOL", repr(str(tool))))
        ssh.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused fixture identity")
        globals_ = {"name": "firewall-fixture", "sshBinary": str(ssh),
                    "transport": {"kind": "ssh", "user": "orbit", "identityFile": str(identity),
                                  "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]},
                    "hosts": [{"name": "target", "address": "target.invalid"}]}
        source = (ROOT / "extensions/behaviors/operations/node-role-converge-command/firewall_steps.ts").as_uri()
        program = "import {roleFirewallSteps} from " + json.dumps(source) + ";console.log(JSON.stringify(roleFirewallSteps('firewall','inputs.rules'," + json.dumps(globals_) + ")));"
        steps = json.loads(subprocess.run([os.environ.get("DENO_BIN", shutil.which("deno") or "deno"), "eval", program], check=True, capture_output=True, text=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", step)
        workflow = {"id": "c243f488-bd46-432d-aa42-9e517f3694e2", "name": "role-firewall-fixture",
                    "inputs": {"type": "object", "properties": {"rules": {"type": "object"}}},
                    "jobs": [{"name": "firewall", "steps": steps}]}
        (self.repo / "workflows/workflow-role-firewall-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "role-firewall-fixture")

    def run_firewall(self, success=True):
        shape = {"comment": "orbit:wireguard-members", "action": "allow", "direction": "in",
                 "source": "any", "destination": "10.44.0.31", "port": "any", "protocol": "any",
                 "in_interface": "orbit", "out_interface": None, "family": "v4"}
        run = json.loads(self.cli("workflow", "run", "role-firewall-fixture", "--input", json.dumps({
            "rules": {"desired": [{"shape": shape, "replace_port": False}], "remove": [], "enable": False}}), success=success).stdout)
        return json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)

    def test_destination_and_interface_are_applied_verified_and_replay_is_current(self):
        for _ in range(2):
            history = self.run_firewall()
            self.assertEqual(history["status"], "succeeded")
            self.assertEqual(history["jobs"][0]["steps"][-1]["outputs"], {"ready": True})
        calls = [json.loads(line) for line in self.calls.read_text().splitlines()]
        writes = [args for args in calls if args != ["status", "numbered"]]
        self.assertEqual(writes, [["allow", "in", "on", "orbit", "from", "0.0.0.0/0", "to", "10.44.0.31", "comment", "orbit:wireguard-members"]])

    def test_inactive_backend_refuses_without_enabling_or_reporting_ready(self):
        self.state.write_text(json.dumps({"active": False, "address": None}))
        history = self.run_firewall(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(history["jobs"][0]["steps"][-1]["status"], "failed")
        self.assertEqual(self.calls.read_text().splitlines(), [json.dumps(["status", "numbered"])])
