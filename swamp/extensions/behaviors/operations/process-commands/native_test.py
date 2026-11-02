"""Production Process DAGs on pinned Swamp and unmodified official behaviors."""

import grp
import hashlib
import json
import os
import pwd
import shutil
import sys
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "tests"))
from native_harness import NativeTestCase


class ProcessFixture(NativeTestCase):
    native_timeout = 120

    def setUp(self):
        super().setUp()
        self.copy_behaviors("operations/process-commands")
        contracts = self.repo / "extensions/behaviors/operations/processes-schedules"
        contracts.mkdir()
        for filename in [
            "process-input.ts",
            "installation-input.ts",
            "required-mount-paths.ts",
            "container-input.ts",
        ]:
            shutil.copy(
                ROOT / "extensions/behaviors/operations/processes-schedules" / filename,
                contracts / filename,
            )
        for package in ["ssh", "cfgmgmt"]:
            source = ROOT / ".swamp/pulled-extensions/@swamp" / package
            shutil.copytree(
                source, self.repo / ".swamp/pulled-extensions/@swamp" / package
            )
        inventory = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )
        dest = self.repo / "extensions/models/upstream_extensions.json"
        dest.parent.mkdir(parents=True, exist_ok=True)
        dest.write_text(
            json.dumps(
                {key: inventory[key] for key in ["@swamp/ssh", "@swamp/cfgmgmt"]}
            )
        )
        self.host = self.repo / "host"
        self.host.mkdir()
        (self.host / "units").mkdir()
        self.state = self.host / "state.json"
        self.save(units={}, calls=[], failures=[])
        commands = self.repo / "tools"
        commands.mkdir()
        self.native_env["PATH"] = str(commands) + ":" + os.environ["PATH"]
        scripts = {
            "chown": "pass\n",
            "chgrp": "pass\n",
            "ssh": """import subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv: sys.exit(0)
command=sys.argv[-1].replace('/etc/systemd/system',UNITS).replace('/etc/orbit',ORBIT)
sys.exit(subprocess.call(['/bin/sh','-c',command]))
""".replace("UNITS", repr(str(self.host / "units"))).replace(
                "ORBIT", repr(str(self.host / "orbit"))
            ),
            "scp": """import shutil,sys
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
""",
            "sudo": """import subprocess,sys
args=sys.argv[1:];args=args[args.index('--')+1:]
sys.exit(subprocess.call(args))
""",
            "systemctl": """import json,sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());a=sys.argv[1:];action=a[0]
s['calls'].append(a);unit=a[1] if len(a)>1 else ''
u=s['units'].setdefault(unit,{'active':'inactive','enabled':False})
failed=bool(s['failures'] and s['failures'][0]==action)
if failed:s['failures'].pop(0)
elif action in ['enable','disable']:u['enabled']=action=='enable'
elif action in ['start','restart','stop']:u['active']='inactive' if action=='stop' else 'active'
p.write_text(json.dumps(s))
if failed:sys.exit(1)
if action=='show':
 print('LoadState='+('loaded' if (Path(UNITS)/unit).exists() else 'not-found'))
 print('ActiveState='+u['active']);print('UnitFileState='+('enabled' if u['enabled'] else 'disabled'))
""".replace("STATE", repr(str(self.state))).replace(
                "UNITS", repr(str(self.host / "units"))
            ),
        }
        for name, source in scripts.items():
            f = commands / name
            f.write_text("#!" + sys.executable + "\n" + source)
            f.chmod(0o700)
        self.workflow_names = {}
        for kind in ["create", "control", "start", "stop", "destroy"]:
            path = ROOT / f"workflows/workflow-operation-process-{kind}.yaml"
            w = yaml.safe_load(path.read_text())
            self.workflow_names[kind] = w["name"]
            for job in w["jobs"]:
                self.assertEqual(job.pop("labels"), {"role": "swamp"})
                for step in job["steps"]:
                    self.assertEqual(step.pop("labels"), {"role": "swamp"})
                    t = step["task"]
                    if t["type"] != "behavior":
                        continue
                    if t["behaviorType"] == "@swamp/ssh":
                        t["globalArgs"].update(
                            sshBinary=str(commands / "ssh"),
                            scpBinary=str(commands / "scp"),
                        )
                        argv = t["inputs"].get("argv")
                        if isinstance(argv, list) and "install" in argv:
                            argv[argv.index("-o") + 1] = pwd.getpwuid(
                                os.getuid()
                            ).pw_name
                            argv[argv.index("-g") + 1] = grp.getgrgid(
                                os.getgid()
                            ).gr_name
                    elif (
                        t["behaviorType"].startswith("@swamp/cfgmgmt/")
                        and "owner" in t["globalArgs"]
                    ):
                        t["globalArgs"].update(
                            owner=pwd.getpwuid(os.getuid()).pw_name,
                            group=grp.getgrgid(os.getgid()).gr_name,
                        )
            (self.repo / path.relative_to(ROOT)).write_text(
                yaml.safe_dump(w, sort_keys=False)
            )
        self.unit = self.host / "units/orbit-process-17-worker.service"
        self.content = (
            "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/sleep 300\n"
        )
        self.inputs = {
            "operation_id": 17,
            "node_id": 9,
            "kind": "process.create",
            "execution_secrets": {},
            "intent": {
                "control": {
                    "process_id": 17,
                    "runtime": "systemd",
                    "name": self.unit.name,
                    "on_demand": False,
                    "vite": None,
                },
                "installation": {
                    "process_id": 17,
                    "runtime": "systemd",
                    "name": self.unit.name,
                    "desired": "running",
                    "on_demand": False,
                    "unit": self.content,
                    "content_hash": hashlib.sha256(self.content.encode()).hexdigest(),
                    "activation_pending": False,
                    "has_environment": False,
                    "environment_path": "/etc/orbit/processes/17/" + "a" * 64 + ".env",
                    "retired": None,
                },
                "mounts": [{"path": "/", "index": 0}],
                "ssh_execution": {
                    "identity_file": str(self.repo / "key"),
                    "target": {
                        "host": "fixture",
                        "user": pwd.getpwuid(os.getuid()).pw_name,
                        "port": 22,
                        "host_keys": [
                            "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIJ111111111111111111111111111111111111111111"
                        ],
                    },
                },
            },
        }
        (self.repo / "key").write_text("fixture key never consumed")

    def save(self, **changes):
        state = json.loads(self.state.read_text()) if self.state.exists() else {}
        state.update(changes)
        self.state.write_text(json.dumps(state))

    def run_kind(self, kind, success=True):
        self.inputs["kind"] = "process." + kind
        workflow = (
            "create" if kind == "update" else "control" if kind == "restart" else kind
        )
        self.cli("workflow", "validate", self.workflow_names[workflow])
        result = self.cli(
            "workflow",
            "run",
            self.workflow_names[workflow],
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )

        if not result.stdout.strip():
            raise AssertionError(result.stderr)
        self.run = json.JSONDecoder().raw_decode(result.stdout.lstrip())[0]
        return self.run

    def current(self):
        return json.loads(self.state.read_text())["units"][self.unit.name]


class ProcessNative(ProcessFixture):
    def test_all_six_kinds_and_safe_reruns(self):
        self.run_kind("create")
        self.assertEqual(self.unit.read_text(), self.content)
        self.assertEqual(self.current(), {"active": "active", "enabled": True})
        self.inputs["intent"].pop("installation")
        self.inputs["intent"].pop("mounts")
        for kind in ["stop", "start", "restart"]:
            self.run_kind(kind)
            self.assertEqual(
                self.current()["active"], "inactive" if kind == "stop" else "active"
            )
        self.inputs["intent"]["installation"] = {
            "process_id": 17,
            "runtime": "systemd",
            "name": self.unit.name,
            "desired": "running",
            "on_demand": False,
            "unit": self.content.replace("300", "400"),
            "content_hash": hashlib.sha256(
                self.content.replace("300", "400").encode()
            ).hexdigest(),
            "activation_pending": True,
            "has_environment": False,
            "environment_path": "/etc/orbit/processes/17/" + "a" * 64 + ".env",
            "retired": None,
        }
        self.run_kind("update")
        self.assertIn("400", self.unit.read_text())
        self.inputs["intent"]["installation"]["activation_pending"] = False
        self.save(calls=[])
        self.run_kind("update")
        self.assertNotIn(
            "restart", [x[0] for x in json.loads(self.state.read_text())["calls"]]
        )
        self.inputs["intent"].pop("installation")
        self.run_kind("destroy")
        self.run_kind("destroy")
        self.assertFalse(self.unit.exists())
        self.assertEqual(self.current(), {"active": "inactive", "enabled": False})

    def test_start_failure_retains_effects_then_rerun_converges(self):
        self.save(failures=["start"])
        self.run_kind("create", success=False)
        self.assertTrue(self.unit.exists())
        self.run_kind("create")
        self.assertEqual(self.current()["active"], "active")

    def test_stop_also_stops_an_activating_unit(self):
        self.unit.write_text(self.content)
        self.save(units={self.unit.name: {"active": "activating", "enabled": True}})
        self.inputs["intent"].pop("installation")
        self.inputs["intent"].pop("mounts")
        self.run_kind("stop")
        self.assertEqual(self.current()["active"], "inactive")

    def test_missing_mount_prevents_unit_write(self):
        self.inputs["intent"]["mounts"] = [
            {"path": str(self.repo / "missing"), "index": 0}
        ]
        self.run_kind("create", success=False)
        self.assertFalse(self.unit.exists())

    def test_environment_uses_private_stdin_and_mode_0600(self):
        secret = "native-private-" + os.urandom(12).hex()
        content = '# Orbit Process 17\nTOKEN="' + secret + '"\n'
        logical = (
            "/etc/orbit/processes/17/"
            + hashlib.sha256(content.encode()).hexdigest()
            + ".env"
        )
        install = self.inputs["intent"]["installation"]
        install.update(
            unit=self.content + "EnvironmentFile=" + logical + "\n",
            has_environment=True,
            environment_path=logical,
        )
        install["content_hash"] = hashlib.sha256(install["unit"].encode()).hexdigest()
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "process-create-operation-17",
            input=content,
        )
        self.inputs["execution_secrets"] = {
            "environment": {
                "vault": "orbit-control-inputs",
                "key": "process-create-operation-17",
            }
        }
        self.run_kind("create")
        path = self.host / logical.removeprefix("/etc/")
        self.assertEqual(path.read_text(), content)
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        history = self.cli("workflow", "history", "get", self.run["id"]).stdout
        self.assertNotIn(secret, history)
        for f in self.repo.rglob("*"):
            if f.is_file() and not f.is_symlink() and f != path:
                self.assertNotIn(secret.encode(), f.read_bytes(), str(f))

    def test_docker_exact_argv_private_environment_update_and_lifecycle(self):
        import http.server
        import socketserver
        import threading

        state_path = self.state

        class Engine(http.server.BaseHTTPRequestHandler):
            def do_GET(handler):
                state = json.loads(state_path.read_text())
                value = state.get("container")
                handler.send_response(200 if value else 404)
                handler.end_headers()
                handler.wfile.write(json.dumps(value or {"message": "absent"}).encode())

            def do_POST(handler):
                body = json.loads(
                    handler.rfile.read(int(handler.headers["Content-Length"]))
                )
                state = json.loads(state_path.read_text())
                state["container"] = {
                    "Config": {
                        "Image": body["Image"],
                        "Labels": body["Labels"],
                        "Env": body["Env"],
                    },
                    "State": {"Status": "created"},
                    "Image": "sha256:fixture",
                    "HostConfig": body["HostConfig"],
                    "Mounts": [],
                }
                state["body"] = body
                state["creates"] = state.get("creates", 0) + 1
                state_path.write_text(json.dumps(state))
                handler.send_response(201)
                handler.end_headers()
                handler.wfile.write(json.dumps({"Id": "a" * 64}).encode())

            def do_DELETE(handler):
                state = json.loads(state_path.read_text())
                state.pop("container", None)
                state_path.write_text(json.dumps(state))
                handler.send_response(204)
                handler.end_headers()

            def log_message(*args):
                pass

        socket = self.repo / "docker.sock"
        server = socketserver.UnixStreamServer(str(socket), Engine)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        script = (
            self.repo
            / "extensions/behaviors/operations/process-commands/scripts/lifecycle_container.py"
        )
        script.write_text(
            script.read_text().replace("/var/run/docker.sock", str(socket))
        )
        docker = self.repo / "tools/docker"
        docker.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import json,sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());args=sys.argv[1:]
if args[:2]==['image','inspect']:
 if not s.get('image'):sys.exit(1)
 print('sha256:fixture|||["alpine:fixture"]|||now|||1');sys.exit(0)
if args[:2]==['image','pull'] or args[:1]==['pull']:s['image']=True
else:
 if args[0]=='container':args=args[1:]
 action=args[0];container=s.get('container')
 if action=='rm':s.pop('container',None)
 elif not container:sys.exit(1)
 elif action=='inspect':
  print(container['State']['Status'] if '--format' in args else json.dumps([container]));sys.exit(0)
 elif action in ['start','restart','stop']:
  container['State']['Status']='exited' if action=='stop' else 'running'
p.write_text(json.dumps(s))
""".replace("STATE", repr(str(self.state)))
        )
        docker.chmod(0o700)
        secret = "docker-private-" + os.urandom(12).hex()
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "process-create-operation-17",
            input=json.dumps({"TOKEN": secret}),
        )
        control = self.inputs["intent"]["control"]
        control.update(runtime="docker", name="orbit-process-17-worker")
        self.inputs["intent"]["installation"] = {
            **control,
            "desired": "running",
            "has_environment": True,
            "retired": None,
            "spec": "a" * 64,
            "definition": {
                "image": "alpine:fixture",
                "command": ["/bin/sh", "-c", 'printf "$TOKEN"; sleep infinity'],
                "working_directory": "/tmp",
                "ports": ["127.0.0.1:8080:80"],
                "mounts": [],
                "restart": "unless-stopped",
            },
        }
        self.inputs["intent"]["installation"].pop("vite")
        self.inputs["execution_secrets"] = {
            "environment": {
                "vault": "orbit-control-inputs",
                "key": "process-create-operation-17",
            }
        }
        self.run_kind("create")
        state = json.loads(self.state.read_text())
        self.assertEqual(
            state["body"]["Cmd"],
            self.inputs["intent"]["installation"]["definition"]["command"],
        )
        self.assertEqual(state["body"]["WorkingDir"], "/tmp")
        self.assertEqual(state["body"]["Env"], ["TOKEN=" + secret])
        self.run_kind("create")
        self.assertEqual(json.loads(self.state.read_text())["creates"], 1)
        self.inputs["intent"]["installation"]["spec"] = "b" * 64
        self.inputs["intent"]["installation"]["definition"]["command"] = [
            "sleep",
            "infinity",
        ]
        self.run_kind("update")
        self.assertEqual(json.loads(self.state.read_text())["creates"], 2)
        self.inputs["intent"].pop("installation")
        self.inputs["intent"].pop("mounts")
        self.inputs["execution_secrets"] = {}
        for kind in ["stop", "start", "restart"]:
            self.run_kind(kind)
            state = json.loads(self.state.read_text())
            self.assertEqual(
                state["container"]["State"]["Status"],
                "exited" if kind == "stop" else "running",
            )
        self.run_kind("destroy")
        self.run_kind("destroy")
        self.assertNotIn("container", json.loads(self.state.read_text()))
        for path in self.repo.rglob("*"):
            if path.is_file() and not path.is_symlink() and path != self.state:
                self.assertNotIn(secret.encode(), path.read_bytes(), str(path))

    def test_vite_listener_must_exist_before_awake_marker(self):
        # Caddy publication is a separate, already tested child boundary.
        child = yaml.safe_load(
            (ROOT / "workflows/workflow-operation-process-vite.yaml").read_text()
        )
        child["jobs"] = [
            {
                "name": "publication",
                "steps": [
                    {
                        "name": "publish",
                        "task": {
                            "type": "behavior",
                            "behaviorType": "command/shell",
                            "behaviorName": "vite-publication-fixture",
                            "methodName": "execute",
                            "inputs": {"run": "true"},
                        },
                    }
                ],
            }
        ]
        (self.repo / "workflows/workflow-operation-process-vite.yaml").write_text(
            yaml.safe_dump(child, sort_keys=False)
        )
        marker = self.host / "awake"
        self.inputs["intent"]["vite_plan"] = {
            "listener": {"port": 5173},
            "awake": {"marker": str(marker)},
        }
        ss = self.repo / "tools/ss"
        ss.write_text("#!/bin/sh\nexit 0\n")
        ss.chmod(0o700)
        self.run_kind("create", success=False)
        self.assertFalse(marker.exists())
        ss.write_text('#!/bin/sh\nprintf "LISTEN 0 4096 127.0.0.1:5173\\n"\n')
        self.run_kind("create")
        self.assertTrue(marker.exists())

    def test_update_retires_the_previous_runtime_before_installing_its_replacement(
        self,
    ):
        self.cli("vault", "create", "local_encryption", "orbit-control-inputs")
        self.save(retired_present=True)
        docker = self.repo / "tools/docker"
        docker.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import json,sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());args=sys.argv[1:]
if args[0]=='inspect':
 if not s['retired_present']:sys.exit(1)
 print(json.dumps([{'State':{'Status':'running'},'Config':{'Image':'fixture'}}]))
elif args[:2]==['rm','-f']:s['retired_present']=False
else:raise SystemExit(1)
p.write_text(json.dumps(s))
""".replace("STATE", repr(str(self.state)))
        )
        docker.chmod(0o700)
        self.inputs["intent"]["installation"]["retired"] = {
            "runtime": "docker",
            "name": "orbit-process-17-worker",
        }
        self.run_kind("update")
        self.assertFalse(json.loads(self.state.read_text())["retired_present"])
        self.assertTrue(self.unit.exists())
        self.assertEqual(self.current()["active"], "active")
