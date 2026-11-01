"""Compose bootstrap and tools in one native root; host commands use disposable seams."""

import base64
import hashlib
import json
import os
import shutil
import subprocess
import sys

import role_tools_native_test as tool_fixture
import yaml
from native_harness import ROOT, NativeTestCase


class EnrollmentPrerequisites(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        fixture = tool_fixture.RoleTools("runTest")
        fixture.setUp()
        self.addCleanup(fixture.doCleanups)
        for field in ("repo", "target", "tools", "intent", "native_env", "secrets"):
            setattr(self, field, getattr(fixture, field))
        bootstrap = self.repo / "extensions/behaviors/operations/node-bootstrap"
        for name in (
            "command_assets.ts",
            "probe_result.ts",
            "enrollment_intent.ts",
            "enrollment_settings.ts",
        ):
            shutil.copy(
                ROOT / "extensions/behaviors/operations/node-bootstrap" / name,
                bootstrap / name,
            )
        self.copy_behaviors("operations/node-role-converge-command")
        routes = self.repo / "extensions/behaviors/operations/routes"
        routes.mkdir()
        for name in ("firewall-program.ts", "firewall-schema.ts"):
            shutil.copy(
                ROOT / "extensions/behaviors/operations/routes" / name, routes / name
            )
        public_key = "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"
        fingerprint = "SHA256:" + base64.b64encode(
            hashlib.sha256(base64.b64decode(public_key.split()[1])).digest()
        ).decode().rstrip("=")
        self.intent.update(javascript=None, composer=None)
        self.enrollment = {
            "schema_version": 1,
            "node_id": 73,
            "mode": "linux",
            "candidate": {"node_id": 73, "user": "orbit", "wireguard_ip": "10.44.0.73"},
            "ssh_identity": {
                "control_node_id": 209,
                "identity_file": str(self.repo / "identity"),
                "public_key": public_key,
            },
            "bootstrap": {
                "node_id": 73,
                "platform": "linux",
                "expected_architecture": "x86_64",
                "target": {
                    "host": "fixture",
                    "port": 22,
                    "user": "root",
                    "fingerprint": fingerprint,
                },
                "managed_user": "orbit",
                "address": "10.44.0.73",
                "command": {
                    "user": "orbit",
                    "public_key": public_key,
                    "releases": ["resolute"],
                    "packages": ["curl"],
                    "dns_policy": "preserve",
                },
                "artifacts": {
                    "linux-x86_64": {
                        "url": "https://artifact.invalid/observer",
                        "sha256": "a" * 64,
                    }
                },
            },
            "tools": self.intent,
            "network": {"trust": {"desired": [], "remove": [], "enable": False}},
            "hub": None,
            "prior_ssh": None,
            "bootstrap_firewall": {"desired": [], "remove": [], "enable": True},
            "settings": {
                "settings": None,
                "storage": {
                    "root": "/srv/apps",
                    "user": "orbit",
                    "create_missing": False,
                    "protected_roots": [],
                    "managed_checkouts": [],
                },
            },
        }
        self.audit = self.repo / "bootstrap-calls.jsonl"
        (self.tools / "ssh").write_text(
            "#!"
            + sys.executable
            + "\n"
            + """
import ast,json,shlex,subprocess,sys
from pathlib import Path
args=sys.argv[1:]
if '-M' in args or '-O' in args: raise SystemExit(0)
argv=shlex.split(args[-1]);body=sys.stdin.read()
with Path(AUDIT).open('a') as stream:stream.write(json.dumps({'argv':argv,'body':body})+'\\n')
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv[:1]==['uname']:print('Darwin arm64' if Path(MAC).exists() else 'Linux x86_64')
elif argv==['cat','/etc/os-release']:print('ID=ubuntu\\nVERSION_CODENAME=resolute')
elif argv[:1]==['cat']:print(PUBLIC_KEY)
elif argv==['/usr/bin/id','-un']:print('orbit')
elif argv==['/usr/bin/printenv','HOME']:print('/Users/orbit')
elif argv==['/sbin/ifconfig']:print('utun2: flags=1\\n  inet 10.44.0.73 netmask 0xffffff00')
elif argv[:3]==['/usr/bin/shasum','-a','256']:print('a'*64+'  observer')
elif 'OBSERVER_SHA256' in body:
    assert argv==['/usr/bin/env','OBSERVER_URL=https://artifact.invalid/observer','OBSERVER_SHA256='+'a'*64,'/bin/sh','-s']
    print(json.dumps({'installed':True,'changed':False}))
elif 'observer_digest' in args[-1]:print(json.dumps({'observer_ready':True,'observer_digest':'b'*64}))
elif 'BOOTSTRAP_INPUT = json.loads(' in body:
    tree=ast.parse(body)
    assignment=next(n for n in tree.body if isinstance(n,ast.Assign) and isinstance(n.targets[0],ast.Name) and n.targets[0].id=='BOOTSTRAP_INPUT')
    data=json.loads(ast.literal_eval(assignment.value.args[0]))
    if 'Read the candidate account' in body:print(json.dumps({'bootstrap_verified':True,'account':{'user':data['user'],'group':'orbit','home':'/home/orbit'}}))
    else:print(json.dumps({'completed':True}))
elif 'orbit-firewall.lock' in body:print(json.dumps({'matches':True}))
elif 'Storage root must already exist' in body:print(json.dumps({'ready':True,'changed':False}))
else:
    result=subprocess.run(argv,input=body,text=True,capture_output=True)
    sys.stdout.write(result.stdout);sys.stderr.write(result.stderr);raise SystemExit(result.returncode)
""".replace("AUDIT", repr(str(self.audit)))
            .replace("PUBLIC_KEY", repr(public_key))
            .replace("MAC", repr(str(self.repo / "mac")))
        )
        program = """
import {enrollmentPrerequisiteSteps} from './extensions/behaviors/operations/node-bootstrap/enrollment_prerequisite_steps.ts';
const e=s=>'${{ '+s+' }}';
const source='steps.admitted.outputs.intent';
const steps=enrollmentPrerequisiteSteps('prerequisites',source,'"null"');
for(const step of steps){if(step.task.type==='behavior' && step.task.behaviorType==='@swamp/ssh'){
    step.task.globalArgs.sshBinary=SSH;step.task.globalArgs.scpBinary=SCP;
}}
const admitted={name:'admitted',target:'orbit-node-209',task:{type:'behavior',behaviorType:'@nckrtl/node-enrollment-intent',behaviorName:'admitted',methodName:'read',inputs:{intent:e('inputs.intent')}}};
console.log(JSON.stringify([admitted,...steps]));
""".replace("SSH", json.dumps(str(self.tools / "ssh"))).replace(
            "SCP", json.dumps(str(self.tools / "scp"))
        )
        generated = subprocess.check_output(
            [os.environ.get("DENO_BIN", "/tmp/lane4-deno-2.9.8/deno"), "eval", program],
            cwd=ROOT,
            text=True,
        )
        steps = json.loads(generated)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertNotIn("labels", step)
        workflow = {
            "id": "7e333ef3-f7d7-4e97-9425-df4eac4f3ff7",
            "name": "enrollment-prerequisites-fixture",
            "inputs": {"type": "object", "properties": {"intent": {"type": "object"}}},
            "jobs": [
                {"name": "admission", "steps": steps[:1]},
                {
                    "name": "effects",
                    "dependsOn": [
                        {"job": "admission", "condition": {"type": "succeeded"}}
                    ],
                    "steps": steps[1:],
                },
            ],
        }
        (self.repo / "workflows/workflow-prerequisites.yaml").write_text(
            yaml.safe_dump(workflow, sort_keys=False).replace(
                "/tmp/orbit-node-tools-", str(self.target / "stage-")
            )
        )

    def run_prerequisites(self, success=True):
        self.cli("workflow", "validate", "enrollment-prerequisites-fixture")
        return json.loads(
            self.cli(
                "workflow",
                "run",
                "enrollment-prerequisites-fixture",
                "--input",
                json.dumps({"intent": self.enrollment}),
                success=success,
            ).stdout
        )

    def test_enrollment_prefix_orders_account_tools_sudo_and_storage_in_one_root(self):
        run = self.run_prerequisites()
        self.assertEqual(run["status"], "succeeded")
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        apt = next(
            i
            for i, call in enumerate(calls)
            if any("install_apt.py" in arg for arg in call["argv"])
        )
        sudo = next(
            i
            for i, call in enumerate(calls)
            if "Validate and publish the managed account" in call["body"]
        )
        self.assertLess(apt, sudo)
        self.assertEqual(calls[apt]["argv"][0], "/usr/bin/python3")

    def test_failed_tools_block_sudo_observer_storage_and_firewall(self):
        (self.target / "fail-apt").touch()
        run = self.run_prerequisites(False)
        self.assertEqual(run["status"], "failed")
        calls = self.audit.read_text()
        self.assertNotIn("Storage root must already exist", calls)
        self.assertNotIn("orbit-firewall.lock", calls)

    def test_tld_only_has_no_bootstrap_tools_or_storage_action(self):
        self.enrollment.update(mode="tld_only", tools=None, network=None)
        run = self.run_prerequisites()
        self.assertEqual(run["status"], "succeeded")
        self.assertFalse(self.audit.exists())

    def test_macos_prefix_uses_the_existing_account_without_linux_tools_or_firewall(
        self,
    ):
        (self.repo / "mac").touch()
        self.enrollment.update(mode="macos", tools=None, network=None)
        self.enrollment["bootstrap"].update(
            platform="macos",
            expected_architecture="arm64",
            artifacts={
                "macos-aarch64": {
                    "url": "https://artifact.invalid/observer",
                    "sha256": "a" * 64,
                }
            },
        )
        self.enrollment["bootstrap"]["target"]["user"] = "orbit"
        run = self.run_prerequisites()
        self.assertEqual(run["status"], "succeeded")
        calls = [json.loads(line) for line in self.audit.read_text().splitlines()]
        self.assertTrue(any("OBSERVER_SHA256" in call["body"] for call in calls))
        self.assertFalse(any("BOOTSTRAP_INPUT" in call["body"] for call in calls))
        self.assertFalse(any("orbit-firewall.lock" in call["body"] for call in calls))
        self.assertFalse(
            any("install_apt.py" in " ".join(call["argv"]) for call in calls)
        )
