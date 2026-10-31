"""Actual official SSH file, service, firewall and HTTP service-readiness composition."""

import hashlib
import http.server
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
from pathlib import Path

import yaml
from native_harness import ROOT, NativeTestCase


class RoleMetricsServiceNative(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-metrics-command", "operations/node-role-converge-command")
        policy = self.repo / "extensions/behaviors/operations/integrations"
        policy.mkdir()
        for source in ("metrics_runtime_spec.ts", "metrics_exporter_spec.ts", "metrics_policy.ts", "metrics_service_spec.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/integrations" / source, policy)
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        temporary = tempfile.TemporaryDirectory(prefix="service-target-", dir=os.environ.get("TMPDIR"))
        self.addCleanup(temporary.cleanup)
        self.target = Path(temporary.name)
        self.state_path = self.target / "state.json"
        self.state = {"active": True, "enabled": True, "rules": [], "calls": []}
        self.save()
        self.directory = self.target / "etc/orbit/service-metrics"
        self.unit = self.target / "etc/systemd/system/orbit-fpm-exporter.service"
        self.binary = self.target / "usr/local/bin/orbit-fpm-exporter"
        marker = "# Managed by Orbit: service-metrics\n"
        self.previous = marker + "server:\n  listen_addr: '127.0.0.1:9114'\n# previous\n"
        self.directory.mkdir(parents=True, mode=0o700)
        (self.directory / ".orbit-owner").write_text(marker)
        (self.directory / "fpm.yml").write_text(self.previous)
        self.unit.parent.mkdir(parents=True)
        self.unit.write_text(marker + "# previous unit\n")
        self.binary.parent.mkdir(parents=True)
        self.binary.write_bytes(b"fixture pinned service binary")
        self.binary.chmod(0o755)
        source = self.target / "artifact"
        source.write_bytes(self.binary.read_bytes())
        spec = policy / "metrics_service_spec.ts"
        spec.write_text(spec.read_text().replace("/etc/orbit/service-metrics", str(self.directory))
                        .replace("/etc/systemd/system/orbit-fpm-exporter.service", str(self.unit))
                        .replace("/usr/local/bin/orbit-fpm-exporter", str(self.binary))
                        .replace("570d2b3d3284179c47acff8efd8f73ea650e42a2c6bd08c0a8e95cb4b0e3ca98", hashlib.sha256(source.read_bytes()).hexdigest())
                        .replace("https://github.com/cboxdk/fpm-exporter/releases/download/v3.1.1/fpm-exporter-linux-amd64", source.as_uri()))
        self.healthy = True
        owner = self

        class Metrics(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                self.send_response(200 if owner.healthy else 503)
                self.end_headers()
                self.wfile.write(b"# HELP fixture actual metrics\n")

            def log_message(self, *_):
                pass

        server = http.server.HTTPServer(("127.0.0.1", 0), Metrics)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        assets = self.repo / "extensions/behaviors/operations/node-role-metrics-command/assets.ts"
        assets.write_text(assets.read_text().replace('"http://" + listener(args.config) + "/metrics"', json.dumps(f"http://127.0.0.1:{server.server_port}/metrics")))
        tools = self.target / "tools"
        tools.write_text("#!" + sys.executable + "\n" + '''
import json,shlex,sys
from pathlib import Path
path=Path(STATE);state=json.loads(path.read_text());program,*argv=sys.argv[1:]
state['calls'].append([program,*argv]);code=0;out=''
if program.endswith('systemctl'):
    method=argv[0]
    if method=='is-active':out='active' if state['active'] else 'inactive';code=0 if state['active'] else 3
    elif method=='is-enabled':out='enabled' if state['enabled'] else 'disabled';code=0 if state['enabled'] else 1
    elif method=='restart':state['active']=True
    elif method=='stop':state['active']=False
    elif method=='enable':state['enabled']=True
    elif method=='disable':
        state['enabled']=False
        if '--now' in argv:state['active']=False
    elif method!='daemon-reload':raise SystemExit(3)
elif program.endswith('ufw'):
    if argv[0]=='status':out='Status: active'
    elif argv[:2]==['show','added']:out='\\n'.join('ufw '+' '.join(rule) for rule in state['rules'])
    elif argv[:2]==['--force','delete']:state['rules'].remove(argv[2:])
    elif argv[:2]==['insert','1']:state['rules'].insert(0,argv[2:])
    else:raise SystemExit(3)
else:raise SystemExit(3)
path.write_text(json.dumps(state));print(out);raise SystemExit(code)
'''.replace("STATE", repr(str(self.state_path))))
        tools.chmod(0o700)
        observer = self.repo / "extensions/behaviors/operations/node-role-metrics-command/scripts/service_observe.py"
        observer.write_text(observer.read_text().replace('["/usr/bin/systemctl", method, data["service"]]', f'[{str(tools)!r}, "/usr/bin/systemctl", method, data["service"]]'))
        directory_script = self.repo / "extensions/behaviors/operations/node-role-metrics-command/scripts/service_directory.py"
        directory_script.write_text(directory_script.read_text().replace("os.chown(path, 0, 0)", "os.chown(path, os.getuid(), os.getgid())"))
        scripts = self.repo / "extensions/behaviors/operations/node-role-converge-command/scripts"
        for name in ("file.py", "file_remove.py", "file_snapshot.py", "file_restore.py", "file_snapshot_cleanup.py"):
            path = scripts / name
            body = path.read_text().replace('path = Path(data["path"])', f'path = Path({str(self.target)!r}) / data["path"].lstrip("/")')
            body = body.replace('SNAPSHOTS = Path("/var/lib/orbit/role-file-snapshots")', f'SNAPSHOTS = Path({str(self.target / "snapshots")!r})')
            body = body.replace('mode = int(data["mode"], 8)', 'uid, gid = os.getuid(), os.getgid()\n    mode = int(data["mode"], 8)')
            path.write_text(body)
        ssh = self.repo / "fixture-ssh"
        ssh.write_text("#!" + sys.executable + "\n" + '''
import shlex,subprocess,sys
argv=shlex.split(sys.argv[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv and argv[0] in ['/usr/bin/systemctl','/usr/sbin/ufw']:argv=[TOOLS,*argv]
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr);raise SystemExit(result.returncode)
'''.replace("TOOLS", repr(str(tools))))
        ssh.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused identity")
        globals_ = {"name": "service-fixture", "sshBinary": str(ssh), "transport": {"kind": "ssh", "user": "orbit", "identityFile": str(identity),
                    "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]}, "hosts": [{"name": "target", "address": "dev.invalid"}]}
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        source = (ROOT / "extensions/behaviors/operations/node-role-metrics-command/service_steps.ts").as_uri()
        program = "import {roleMetricsServiceSteps} from " + json.dumps(source) + ";console.log(JSON.stringify(roleMetricsServiceSteps('service','inputs.desired'," + json.dumps(globals_) + ")));"
        steps = json.loads(subprocess.run([deno, "eval", program], capture_output=True, text=True, check=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        workflow = {"id": "5cd3e6c0-aaf0-4dc1-8fdd-c8fc0c2b2943", "name": "service-role-native-fixture", "inputs": {"type": "object", "properties": {"desired": {"type": "object"}}},
                    "jobs": [{"name": "service", "steps": steps}]}
        (self.repo / "workflows/workflow-service-role-native-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "service-role-native-fixture")
        self.desired = {"config": marker + "server:\n  listen_addr: '127.0.0.1:9114'\n# desired\n", "unit": marker + "# desired unit\n", "active": True, "enabled": True, "binary": True,
                        "rules": [[action, "in", "on", "orbit", "proto", "tcp", "from", "10.44.0.9" if action == "allow" else "any", "to", "10.44.0.2", "port", "9114", "comment", "orbit:metrics-service-fpm-" + action] for action in ("allow", "deny")]}

    def save(self):
        self.state_path.write_text(json.dumps(self.state))

    def run_service(self, success=True):
        path = self.repo / "intent.json"
        path.write_text(json.dumps({"desired": self.desired}))
        run = json.loads(self.cli("workflow", "run", "service-role-native-fixture", "--input-file", str(path), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.state = json.loads(self.state_path.read_text())
        return history, {step["name"]: step for step in history["jobs"][0]["steps"]}

    def test_service_proves_real_health_files_flags_and_canonical_rule_order(self):
        history, steps = self.run_service()
        self.assertEqual(history["status"], "succeeded")
        self.assertTrue(steps["service_ready"]["outputs"]["ready"])
        self.assertEqual(self.state["rules"], self.desired["rules"])
        self.assertEqual((self.directory / "fpm.yml").read_text(), self.desired["config"])

    def test_unready_health_restores_previous_files_flags_and_firewall(self):
        self.healthy = False
        history, steps = self.run_service(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["service_health"]["status"], "failed")
        self.assertTrue(steps["service_restored_ready"]["outputs"]["ready"])
        self.assertEqual((self.directory / "fpm.yml").read_text(), self.previous)
        self.assertEqual(self.state["rules"], [])
        self.assertTrue(self.state["active"])

    def test_initial_service_installs_verified_binary_and_publishes_the_managed_directory(self):
        shutil.rmtree(self.directory)
        self.unit.unlink()
        self.binary.unlink()
        self.state.update(active=False, enabled=False)
        self.save()
        history, steps = self.run_service()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(self.binary.read_bytes(), b"fixture pinned service binary")
        self.assertEqual(self.binary.stat().st_mode & 0o777, 0o755)
        self.assertTrue(steps["service_binary"]["outputs"]["ready"])
        self.assertEqual(self.directory.stat().st_mode & 0o777, 0o700)
