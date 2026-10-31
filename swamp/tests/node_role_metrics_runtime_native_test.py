"""Same-root Metrics actions through unmodified official SSH and a disposable engine fixture."""

import base64
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


class RoleMetricsRuntimeNative(NativeTestCase):
    native_timeout = 300

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/node-role-metrics-command", "operations/node-role-converge-command")
        policy = self.repo / "extensions/behaviors/operations/integrations"
        policy.mkdir()
        for source in ("metrics_runtime_spec.ts", "metrics_exporter_spec.ts", "metrics_policy.ts", "metrics_service_spec.ts"):
            shutil.copy(ROOT / "extensions/behaviors/operations/integrations" / source, policy)
        self.cli("extension", "pull", "@swamp/ssh@2026.10.23.1")
        temporary = tempfile.TemporaryDirectory(prefix="metrics-target-", dir=os.environ.get("TMPDIR"))
        self.addCleanup(temporary.cleanup)
        self.target = Path(temporary.name)
        self.state_path = self.target / "engine.json"
        self.secret = "synthetic-metrics-" + os.urandom(16).hex()
        self.secrets = (self.secret,)
        self.authorized = True
        owner = self

        class Grafana(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                expected = "Basic " + base64.b64encode(("admin:" + owner.secret).encode()).decode()
                self.send_response(200 if owner.authorized and self.headers.get("Authorization") == expected else 403)
                self.end_headers()

            def log_message(self, *_):
                pass

        server = http.server.HTTPServer(("127.0.0.1", 0), Grafana)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.configuration = {}
        paths = ["/etc/orbit/metrics/prometheus.yml", "/etc/orbit/metrics/grafana/grafana.ini",
                 "/etc/orbit/metrics/grafana/provisioning/datasources/prometheus.yml",
                 "/etc/orbit/metrics/grafana/provisioning/dashboards/provider.yml",
                 "/etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json",
                 "/etc/orbit/metrics/grafana/dashboards/orbit-caddy.json",
                 "/etc/orbit/metrics/grafana/dashboards/orbit-fpm.json"]
        for path in paths:
            self.configuration[path] = "public fixture configuration " + path
        self.cli("vault", "create", "local_encryption", "role-metrics-fixture")
        self.cli("vault", "put", "role-metrics-fixture", "runtime", input=json.dumps({
            "configuration": {**self.configuration, "/etc/orbit/metrics/grafana/admin-password": self.secret},
            "password": self.secret,
        }))
        metrics_scripts = self.repo / "extensions/behaviors/operations/node-role-metrics-command/scripts"
        credential = metrics_scripts / "credential.py"
        credential.write_text(credential.read_text().replace('":3000/api/user"', f'":{server.server_port}/api/user"'))
        owner_script = metrics_scripts / "owner.py"
        owner_script.write_text(owner_script.read_text()
                                .replace('ROOT = Path("/etc/orbit/metrics")', f'ROOT = Path({str(self.target / "etc/orbit/metrics")!r})')
                                .replace('path = Path(value)', f'path = Path({str(self.target)!r}) / value.lstrip("/")'))
        scripts = self.repo / "extensions/behaviors/operations/node-role-converge-command/scripts"
        for name in ("file.py", "file_snapshot.py", "file_restore.py", "file_snapshot_cleanup.py"):
            path = scripts / name
            body = path.read_text().replace('path = Path(data["path"])', f'path = Path({str(self.target)!r}) / data["path"].lstrip("/")')
            body = body.replace('SNAPSHOTS = Path("/var/lib/orbit/role-file-snapshots")', f'SNAPSHOTS = Path({str(self.target / "snapshots")!r})')
            body = body.replace('["configuration"][str(path)]', '["configuration"][data["path"]]')
            body = body.replace('mode = int(data["mode"], 8)', 'uid, gid = os.getuid(), os.getgid()\n    mode = int(data["mode"], 8)')
            path.write_text(body)
        engine = self.target / "docker-fixture"
        engine.write_text("#!" + sys.executable + "\n" + '''
import json,sys
from pathlib import Path
path=Path(STATE);state=json.loads(path.read_text());argv=sys.argv[1:]
if argv[:2]==['--host','unix:///var/run/docker.sock']:argv=argv[2:]
code=0;output=''
kind,action=argv[:2]
def save():path.write_text(json.dumps(state))
def labels():
    return {argv[i+1].split('=',1)[0]:argv[i+1].split('=',1)[1] for i,arg in enumerate(argv) if arg=='--label'}
name=argv[-1]
if action=='ls':
    exact=next(arg[5:] for arg in argv if arg.startswith('name='));exact=exact.removeprefix('^/').removeprefix('^').removesuffix('$')
    output=exact if exact in state['containers' if kind=='container' else 'volumes'] else ''
elif action=='inspect':
    if kind=='image':code=0 if name in state['images'] else 1
    elif kind=='volume':output=json.dumps(state['volumes'][name])
    else:
        data=state['containers'][name];output=json.dumps(data['labels'])+'\\n'+json.dumps({'Status':data['status'],'Health':{'Status':data['health']}})
elif kind=='image' and action=='pull':state['images'].append(name)
elif kind=='volume' and action=='create':state['volumes'][name]=labels();output=name
elif kind=='volume' and action=='rm':state['volumes'].pop(name)
elif kind=='container' and action=='run':
    if '--entrypoint' in argv:
        if 'invalid configuration' in sys.stdin.read():code=1
    else:
        name=argv[argv.index('--name')+1]
        if state.get('fail_create')==name:code=1
        else:
            if name in state['containers']:raise SystemExit(1)
            state['containers'][name]={'labels':labels(),'status':'running','health':'starting' if state.get('unready') else 'healthy'}
            output='d'*64
elif kind=='container' and action=='stop':state['containers'][name]['status']='exited'
elif kind=='container' and action=='rename':
    previous,name=argv[-2:]
    if name in state['containers']:raise SystemExit(1)
    state['containers'][name]=state['containers'].pop(previous)
elif kind=='container' and action=='start':state['containers'][name]['status']='running'
elif kind=='container' and action=='rm':
    if state.get('fail_cleanup')==name:code=1
    else:state['containers'].pop(name)
else:raise SystemExit(3)
save();print(output);raise SystemExit(code)
'''.replace("STATE", repr(str(self.state_path))))
        engine.chmod(0o700)
        inspect_script = metrics_scripts / "inspect.py"
        inspect_script.write_text(inspect_script.read_text().replace('DOCKER = ["/usr/bin/docker", "--host", "unix:///var/run/docker.sock"]', f'DOCKER = [{str(engine)!r}]'))
        binary = self.repo / "fixture-ssh"
        binary.write_text("#!" + sys.executable + "\n" + '''
import shlex,subprocess,sys
argv=shlex.split(sys.argv[-1])
if argv[:3]==['sudo','-n','--']:argv=argv[3:]
if argv[:1]==['/usr/bin/docker']:argv=[ENGINE,*argv[1:]]
result=subprocess.run(argv,input=sys.stdin.buffer.read(),capture_output=True)
sys.stdout.buffer.write(result.stdout);sys.stderr.buffer.write(result.stderr)
raise SystemExit(result.returncode)
'''.replace("ENGINE", repr(str(engine))))
        binary.chmod(0o700)
        identity = self.repo / "identity"
        identity.write_text("synthetic unused SSH identity")
        globals_ = {"name": "metrics-fixture", "sshBinary": str(binary),
                    "transport": {"kind": "ssh", "user": "orbit", "identityFile": str(identity),
                                  "hostKeys": ["ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIGaNG9xwGkyymXqkaSEKl6+u5VLBHPbi0ga0ya7sdCtG"]},
                    "hosts": [{"name": "target", "address": "dev.invalid"}]}
        deno = os.environ.get("DENO_BIN", shutil.which("deno") or "deno")
        source = (ROOT / "extensions/behaviors/operations/node-role-metrics-command/steps.ts").as_uri()
        program = "import {roleMetricsRuntimeSteps} from " + json.dumps(source) + ";console.log(JSON.stringify(roleMetricsRuntimeSteps('inputs.intent.runtime'," + json.dumps(globals_) + ",\"vault.get('role-metrics-fixture','runtime')\")));"
        steps = json.loads(subprocess.run([deno, "eval", program], capture_output=True, text=True, check=True).stdout)
        for step in steps:
            self.assertEqual(step.pop("target"), "orbit-node-209")
            self.assertEqual(step.pop("labels"), {"node": "209"})
        workflow = {"id": "4476832c-9ddb-49b0-b408-56c4ac2d459e", "name": "metrics-role-native-fixture",
                    "inputs": {"type": "object", "properties": {"intent": {"type": "object"}}},
                    "vaults": ["role-metrics-fixture"], "jobs": [{"name": "runtime", "steps": steps}]}
        (self.repo / "workflows/workflow-metrics-role-native-fixture.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.cli("workflow", "validate", "metrics-role-native-fixture")
        self.state = {"containers": {}, "volumes": {}, "images": []}
        for service in ("prometheus", "grafana"):
            self.state["containers"]["orbit-metrics-" + service] = {
                "labels": {"com.orbit.managed": "metrics", "com.orbit.metrics.service": service, "com.orbit.metrics.spec-hash": "b" * 64},
                "status": "running", "health": "healthy",
            }
            self.state["volumes"]["orbit-metrics-" + service + "-data"] = {"com.orbit.managed": "metrics", "com.orbit.metrics.volume": service}
        self.save()
        directory = self.target / "etc/orbit/metrics"
        directory.mkdir(parents=True)
        (directory / ".orbit-owner").write_text("metrics\n")
        self.previous = directory / "prometheus.yml"
        self.previous.write_text("previous public configuration")
        self.previous.chmod(0o640)

    def save(self):
        self.state_path.write_text(json.dumps(self.state))

    def run_runtime(self, success=True):
        path = self.repo / "intent.json"
        path.write_text(json.dumps({"intent": {"runtime": {"role": "metrics", "assignment_id": 31, "address": "127.0.0.1", "public_configuration": self.configuration}}}))
        run = json.loads(self.cli("workflow", "run", "metrics-role-native-fixture", "--input-file", str(path), success=success).stdout)
        history = json.loads(self.cli("workflow", "history", "get", run["id"]).stdout)
        self.state = json.loads(self.state_path.read_text())
        return history, {step["name"]: step for step in history["jobs"][0]["steps"]}

    def test_runtime_proves_both_services_and_existing_credentials_before_cleanup(self):
        history, steps = self.run_runtime()
        self.assertEqual(history["status"], "succeeded")
        self.assertEqual(steps["ready"]["outputs"], {"ready": True, "runtime_completed": True})
        self.assertEqual(sorted(self.state["containers"]), ["orbit-metrics-grafana", "orbit-metrics-prometheus"])
        self.assertEqual(self.previous.read_text(), self.configuration["/etc/orbit/metrics/prometheus.yml"])
        self.assert_private_absent(json.dumps(history))
        self.assertEqual(list((self.target / "snapshots").rglob("*.json")), [])

    def test_unready_actual_health_fails_and_restores_the_previous_runtime_and_file(self):
        self.state["unready"] = True
        before = json.loads(json.dumps(self.state["containers"]))
        self.save()
        history, steps = self.run_runtime(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["healthy_prometheus"]["status"], "failed")
        self.assertEqual(steps["verify_prometheus"]["outputs"]["health"], "starting")
        self.assertNotEqual(steps["ready"]["status"], "succeeded")
        self.assertEqual(self.state["containers"], before)
        self.assertEqual(self.previous.read_text(), "previous public configuration")
        self.assertEqual(self.previous.stat().st_mode & 0o777, 0o640)
        self.assert_private_absent(json.dumps(history))

    def test_cleanup_failure_keeps_completed_runtime_contribution_and_does_not_restore_old_files(self):
        self.state["fail_cleanup"] = "orbit-metrics-prometheus-orbit-rollback"
        self.save()
        history, steps = self.run_runtime(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertTrue(steps["ready"]["outputs"]["runtime_completed"])
        self.assertEqual(self.previous.read_text(), self.configuration["/etc/orbit/metrics/prometheus.yml"])
        self.assertEqual(steps["restore_name_prometheus"]["status"], "skipped")
        self.assertIn("orbit-metrics-prometheus-orbit-rollback", self.state["containers"])

    def test_invalid_configuration_fails_before_mutation_without_failed_recovery_guards(self):
        self.configuration["/etc/orbit/metrics/prometheus.yml"] = "invalid configuration"
        before = json.loads(json.dumps(self.state))
        history, steps = self.run_runtime(success=False)
        self.assertEqual(history["status"], "failed")
        self.assertEqual(steps["validate_configuration"]["status"], "failed")
        self.assertEqual(self.state["containers"], before["containers"])
        self.assertEqual(self.previous.read_text(), "previous public configuration")
        failed = [name for name, step in steps.items() if step["status"] == "failed"]
        self.assertEqual(failed, ["validate_configuration"])
