"""Official SSH exporter effects and recovery with disposable service/firewall tools."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class RoleMetricsExporterNative(NativeTestCase):
    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-metrics-command", "operations/node-role-converge-command")
        policy = self.repo / "extensions/behaviors/operations/integrations"
        policy.mkdir()
        for source in ("metrics_runtime_spec.ts", "metrics_exporter_spec.ts", "metrics_policy.ts", "metrics_service_spec.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/integrations" / source, policy)
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        temporary = tempfile.TemporaryDirectory(prefix="exporter-target-", dir=os.environ.get("TMPDIR"))
        self.addCleanup(temporary.cleanup)
        self.target = Path(temporary.name)
        self.state_path = self.target / "state.json"
        self.state = {"active": True, "source": "10.44.0.8", "calls": []}
        self.save()
        self.configuration = self.target / "etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf"
        self.configuration.parent.mkdir(parents=True)
        self.previous = "# Managed by Orbit: metrics\n[Service]\n# previous\n"
        self.configuration.write_text(self.previous)
        tools = self.target / "tools"
        tools.write_text("#!" + sys.executable + "\n" + '''
import json,sys
from pathlib import Path
path=Path(STATE);state=json.loads(path.read_text());program,*argv=sys.argv[1:]
state['calls'].append([program,*argv]);code=0;out=''
if program.endswith('systemctl'):
    if argv[0]=='is-active':out='active' if state['active'] else 'inactive';code=0 if state['active'] else 3
    elif argv[0]=='restart':
        if state.get('fail_restart'):
            state['fail_restart']=False;code=1
        else:state['active']=True
    elif argv[0]=='enable':state['active']=True
    elif argv[0]=='disable':state['active']=False
    elif argv[0]!='daemon-reload':raise SystemExit(3)
elif program.endswith('ufw'):
    if argv[0]=='status':
        out='Status: active\\n'
        if state['source']:out+='[ 3] 10.44.0.2 9100/tcp on orbit ALLOW IN '+state['source']+' # orbit:metrics-node-exporter'
    elif argv[:2]==['--force','delete']:state['source']=None
    elif argv[0]=='allow':
        if state.get('fail_rule'):
            state['fail_rule']=False;code=1
        else:state['source']=argv[argv.index('from')+1]
    else:raise SystemExit(3)
elif not program.endswith('apt-get'):raise SystemExit(3)
path.write_text(json.dumps(state));print(out);raise SystemExit(code)
'''.replace("STATE", repr(str(self.state_path))))
        tools.chmod(0o700)
        scripts = self.repo / "extensions/behaviors/operations/node-role-converge-command/scripts"
        for name in ("file.py", "file_remove.py", "file_snapshot.py", "file_restore.py", "file_snapshot_cleanup.py"):
            path = scripts / name
            source = path.read_text().replace('path = Path(data["path"])', f'path = Path({str(self.target)!r}) / data["path"].lstrip("/")')
            source = source.replace('SNAPSHOTS = Path("/var/lib/orbit/role-file-snapshots")', f'SNAPSHOTS = Path({str(self.target / "snapshots")!r})')
            source = source.replace('mode = int(data["mode"], 8)', 'uid, gid = os.getuid(), os.getgid()\n    mode = int(data["mode"], 8)')
            path.write_text(source)
        observer = self.repo / "extensions/behaviors/operations/node-role-metrics-command/scripts/exporter_observe.py"
        observer.write_text(observer.read_text().replace('path = Path(data["path"])', f'path = Path({str(self.target)!r}) / data["path"].lstrip("/")')
                            .replace('["/usr/bin/systemctl", "is-active", data["service"]]', f'[{str(tools)!r}, "/usr/bin/systemctl", "is-active", data["service"]]'))
        ssh = self.repo / "fixture-ssh"
        ssh.write_text("#!" + sys.executable + "\n" + '''
import shlex,subprocess,sys
argv=shlex.split(sys.argv[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv and argv[0] in ['/usr/bin/systemctl','/usr/sbin/ufw','/usr/bin/apt-get']:argv=[TOOLS,*argv]
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr);raise SystemExit(result.returncode)
'''.replace("TOOLS", repr(str(tools))))
        ssh.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused identity")
        globals_ = {"name": "exporter-fixture", "sshBinary": str(ssh),
                    "transport": {"kind": "ssh", "user": "orbit", "identityFile": str(identity),
                                  "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]},
                    "hosts": [{"name": "target", "address": "dev.invalid"}]}
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        source = (ROOT / "extensions/behaviors/operations/node-role-metrics-command/exporter_steps.ts").as_uri()
        program = "import {roleExporterSteps} from " + json.dumps(source) + ";console.log(JSON.stringify(roleExporterSteps('node','node_exporter','inputs.member','inputs.metrics_address'," + json.dumps(globals_) + ")));"
        steps = json.loads(subprocess.run([deno, "eval", program], capture_output=True, text=True, check=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        workflow = {"id": "a12b6012-4ffc-4fce-9bce-8c0d4243c16d", "name": "exporter-role-native-fixture",
                    "inputs": {"type": "object", "properties": {"member": {"type": "object"}, "metrics_address": {"type": "string"}}},
                    "jobs": [{"name": "exporter", "steps": steps}]}
        (self.repo / "workflows/workflow-exporter-role-native-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "exporter-role-native-fixture")

    def save(self):
        self.state_path.write_text(json.dumps(self.state))

    def run_exporter(self, selected=True, success=True):
        path = self.repo / "intent.json"
        path.write_text(json.dumps({"member": {"address": "10.44.0.2", "selected": selected}, "metrics_address": "10.44.0.9"}))
        run = json.loads(self.cli("workflow", "run", "exporter-role-native-fixture", "--input-file", str(path), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.state = json.loads(self.state_path.read_text())
        return history, {step["name"]: step for step in history["jobs"][0]["steps"]}

    def test_selected_exporter_publishes_canonical_config_service_and_exact_source(self):
        history, steps = self.run_exporter()
        self.assertEqual(history["status"], "succeeded")
        self.assertTrue(steps["node_ready"]["outputs"]["ready"])
        self.assertEqual(self.state["source"], "10.44.0.9")
        self.assertTrue(self.state["active"])
        self.assertIn("--web.listen-address=10.44.0.2:9100", self.configuration.read_text())

    def test_failed_new_firewall_rule_restores_old_config_and_source(self):
        self.state["fail_rule"] = True
        self.save()
        history, steps = self.run_exporter(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["node_add_rule"]["status"], "failed")
        self.assertTrue(steps["node_restored_ready"]["outputs"]["ready"])
        self.assertEqual(self.configuration.read_text(), self.previous)
        self.assertEqual(self.state["source"], "10.44.0.8")
        self.assertTrue(self.state["active"])

    def test_unselected_exporter_withdraws_owned_configuration_and_rule_without_package_removal(self):
        history, steps = self.run_exporter(selected=False)
        self.assertEqual(history["status"], "succeeded")
        self.assertFalse(self.configuration.exists())
        self.assertFalse(self.state["active"])
        self.assertIsNone(self.state["source"])
        self.assertEqual(steps["node_install"]["status"], "skipped")

    def test_foreign_configuration_refuses_before_effects(self):
        self.configuration.write_text("operator-owned\n")
        history, steps = self.run_exporter(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["node_before_command"]["status"], "failed")
        self.assertEqual(self.configuration.read_text(), "operator-owned\n")
        self.assertEqual(self.state["source"], "10.44.0.8")
        self.assertTrue(all(call[1] in ("is-active", "status") for call in self.state["calls"]))
