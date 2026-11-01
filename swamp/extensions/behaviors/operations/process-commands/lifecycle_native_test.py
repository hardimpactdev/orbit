"""Execute production Process DAGs with unmodified official cfgmgmt and SSH models."""

import grp
import hashlib
import json
import os
import pwd
import shutil
import sys
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[4]
sys.path.insert(0, str(ROOT / "extensions/behaviors/operations/schedule-run"))
from native_test import ScheduleSshFixture


class ProcessLifecycleNative(ScheduleSshFixture):
    native_timeout = 90

    def setUp(self):
        super().setUp()
        source = ROOT / ".swamp/pulled-extensions/@swamp/cfgmgmt"
        shutil.copytree(source, self.repo / ".swamp/pulled-extensions/@swamp/cfgmgmt")
        registry = self.repo / "extensions/models/upstream_extensions.json"
        values = json.loads(registry.read_text())
        values["@swamp/cfgmgmt"] = json.loads(
            (ROOT / "extensions/models/upstream_extensions.json").read_text()
        )["@swamp/cfgmgmt"]
        registry.write_text(json.dumps(values))
        self.host = self.repo / "host"
        (self.host / "units").mkdir(parents=True)
        (self.host / "schedules").mkdir()
        self.state = self.host / "systemd.json"
        self.save_state(units={}, calls=[], failures=[])
        tools = self.repo / "cfgmgmt-tools"
        tools.mkdir()
        self.native_env["PATH"] = str(tools) + ":" + os.environ["PATH"]
        scripts = {
            "ssh": """import subprocess,sys
if '-M' in sys.argv or '-O' in sys.argv:raise SystemExit(0)
command=sys.argv[-1].replace('/etc/systemd/system',UNITS).replace('/etc/orbit/schedules',SCHEDULES)
raise SystemExit(subprocess.call(['/bin/sh','-c',command]))
""".replace("UNITS", repr(str(self.host / "units"))).replace(
                "SCHEDULES", repr(str(self.host / "schedules"))
            ),
            "scp": """import shutil,sys
from pathlib import Path
src=Path(sys.argv[-2]);dst=Path(sys.argv[-1].split(':',1)[1])
if src.is_dir():shutil.copytree(src,dst/src.name,dirs_exist_ok=True)
else:shutil.copyfile(src,dst)
""",
            "sudo": """import subprocess,sys
args=sys.argv[1:];args=args[args.index('--')+1:]
raise SystemExit(subprocess.call(args))
""",
            "chown": "pass\n",
            "chgrp": "pass\n",
            "rm": """import subprocess,sys
from pathlib import Path
flag=Path(FLAG)
if flag.exists() and flag.read_text() in sys.argv:
 flag.unlink();raise SystemExit(1)
raise SystemExit(subprocess.call(['/usr/bin/rm',*sys.argv[1:]]))
""".replace("FLAG", repr(str(self.host / "fail-remove"))),
            "systemctl": """import json,sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());a=sys.argv[1:];action=a[0];s['calls'].append(a)
if s['failures'] and s['failures'][0]==action:
 s['failures'].pop(0);p.write_text(json.dumps(s));raise SystemExit(1)
unit=a[1] if len(a)>1 else '';current=s['units'].setdefault(unit,{'enabled':False,'active':False})
if action=='show':
 print('LoadState='+('loaded' if (Path(UNITS)/unit).exists() else 'not-found'))
 print('ActiveState='+(current['active'] if isinstance(current['active'],str) else 'active' if current['active'] else 'inactive'))
 print('UnitFileState='+('enabled' if current['enabled'] else 'disabled'))
elif action=='is-active':
 print(current['active'] if isinstance(current['active'],str) else 'active' if current['active'] else 'inactive')
elif action in ['enable','disable']:current['enabled']=action=='enable'
elif action in ['start','restart','stop']:
 if action=='start' and not (Path(UNITS)/unit).exists():p.write_text(json.dumps(s));raise SystemExit(5)
 current['active']=action in ['start','restart']
elif action=='daemon-reload':s['loaded']={p.name:p.read_text() for p in Path(UNITS).glob('*')}
p.write_text(json.dumps(s))
""".replace("STATE", repr(str(self.state))).replace(
                "UNITS", repr(str(self.host / "units"))
            ),
        }
        for name, body in scripts.items():
            path = tools / name
            path.write_text("#!" + sys.executable + "\n" + body)
            path.chmod(0o700)
        shutil.copyfile(tools / "ssh", self.ssh)
        shutil.copyfile(tools / "scp", self.scp)

        self.copy_behaviors("operations/process-commands")
        contracts = self.repo / "extensions/behaviors/operations/processes-schedules"
        for name in [
            "process-input.ts",
            "installation-input.ts",
            "required-mount-paths.ts",
            "container-input.ts",
        ]:
            shutil.copy(
                ROOT / "extensions/behaviors/operations/processes-schedules" / name,
                contracts / name,
            )
        self.unit = self.host / "units/orbit-process-17-proof.service"
        self.host_root = self.host / "orbit"
        scripts = self.repo / "extensions/behaviors/operations/process-commands/scripts"
        common = scripts / "process_common.py"
        common.write_text(
            common.read_text()
            .replace(
                'Path("/etc/systemd/system")',
                "Path(" + repr(str(self.host / "units")) + ")",
            )
            .replace("ROOT_UID = 0", "ROOT_UID = " + str(os.getuid()))
            .replace("ROOT_GID = 0", "ROOT_GID = " + str(os.getgid()))
            .replace('"/usr/bin/systemctl"', repr(str(tools / "systemctl")))
        )
        # Fixed path substitution represents the disposable remote host.
        ssh = tools / "ssh"
        ssh.write_text(
            ssh.read_text().replace(
                ".replace('/etc/orbit/schedules',",
                ".replace('/usr/bin/systemctl',"
                + repr(str(tools / "systemctl"))
                + ").replace('/etc/orbit',"
                + repr(str(self.host_root))
                + ").replace('/etc/orbit/schedules',",
            )
        )
        shutil.copyfile(ssh, self.ssh)
        for filename in ["unit_files.py", "lifecycle_environment.py"]:
            p = scripts / filename
            text = p.read_text().replace(
                'Path("/etc/orbit")', "Path(" + repr(str(self.host_root)) + ")"
            )
            if filename == "lifecycle_environment.py":
                text = text.replace(
                    "root = Path(",
                    'install["environment_path"]=install["environment_path"].replace("/etc/orbit",'
                    + repr(str(self.host_root))
                    + ')\n    install["unit"]=install["unit"].replace("/etc/orbit",'
                    + repr(str(self.host_root))
                    + ")\n    root = Path(",
                )
            p.write_text(text)
        for kind in ["create", "destroy"]:
            w = yaml.safe_load(
                (ROOT / f"workflows/workflow-operation-process-{kind}.yaml").read_text()
            )
            for job in w["jobs"]:
                self.assertEqual(job.pop("target"), "orbit-node-209")
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    t = step["task"]
                    if t["type"] == "workflow":
                        continue
                    if t["behaviorType"] == "@swamp/ssh":
                        t["globalArgs"].update(
                            sshBinary=str(self.ssh), scpBinary=str(self.scp)
                        )
                    if (
                        t["behaviorType"].startswith("@swamp/cfgmgmt/")
                        and "owner" in t["globalArgs"]
                    ):
                        t["globalArgs"].update(
                            owner=pwd.getpwuid(os.getuid()).pw_name,
                            group=grp.getgrgid(os.getgid()).gr_name,
                        )
            (
                self.repo / f"workflows/workflow-operation-process-{kind}.yaml"
            ).write_text(
                yaml.safe_dump(w, sort_keys=False).replace(
                    "/tmp/orbit-process-", str(self.repo / "staging-")
                )
            )
        self.content = (
            "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/sleep 30\n"
        )
        control = {
            "process_id": 17,
            "runtime": "systemd",
            "name": self.unit.name,
            "on_demand": False,
            "vite": None,
        }
        installation = {k: v for k, v in control.items() if k != "vite"}
        installation.update(
            unit=self.content,
            content_hash=hashlib.sha256(self.content.encode()).hexdigest(),
            desired="running",
            activation_pending=False,
            has_environment=False,
            environment_path="/etc/orbit/processes/17/" + "a" * 64 + ".env",
            retired=None,
        )
        connection = self.inputs["intent"]["ssh_execution"]
        connection["target"]["user"] = pwd.getpwuid(os.getuid()).pw_name
        self.inputs = {
            "operation_id": 17,
            "node_id": 9,
            "kind": "process.create",
            "intent": {
                "control": control,
                "installation": installation,
                "ssh_execution": connection,
            },
            "execution_secrets": {},
        }

    def run_lifecycle(self, kind="create", success=True):
        self.inputs["kind"] = "process." + kind
        reply = self.cli(
            "workflow",
            "run",
            "operation-process-" + ("create" if kind == "update" else kind),
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        self.run = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]
        return self.run

    def current(self):
        return json.loads(self.state.read_text())["units"][self.unit.name]

    def drift(self, **changes):
        state = json.loads(self.state.read_text())
        state["units"].setdefault(
            self.unit.name, {"active": False, "enabled": False}
        ).update(changes)
        self.state.write_text(json.dumps(state))

    def test_create_then_destroy_and_absent_destroy_converge(self):
        self.run_lifecycle()
        self.assertEqual(self.unit.read_text(), self.content)
        self.assertTrue(self.current()["active"])
        self.inputs["intent"].pop("installation")
        self.run_lifecycle("destroy")
        self.assertFalse(self.unit.exists())
        self.assertFalse(self.current()["active"])
        self.run_lifecycle("destroy")
        self.assertFalse(list(self.repo.glob("staging-*")))

    def test_changed_unit_restarts_once_then_compliant_rerun_does_not(self):
        self.unit.write_text(self.content.replace("30", "20"))
        self.drift(active=True, enabled=True)
        self.run_lifecycle()
        self.assertEqual(
            [x[0] for x in json.loads(self.state.read_text())["calls"]].count(
                "restart"
            ),
            1,
        )
        self.save_state(calls=[])
        self.run_lifecycle()
        self.assertNotIn(
            "restart", [x[0] for x in json.loads(self.state.read_text())["calls"]]
        )

    def test_update_reuses_native_convergence_and_preserves_process_identity(self):
        self.run_lifecycle()
        self.inputs["operation_id"] = 18
        self.save_state(calls=[])
        install = self.inputs["intent"]["installation"]
        install["unit"] = self.content.replace("30", "45")
        install["content_hash"] = hashlib.sha256(install["unit"].encode()).hexdigest()
        self.run_lifecycle("update")
        self.assertEqual(self.run["status"], "succeeded")
        self.assertEqual(self.unit.read_text(), install["unit"])
        self.assertTrue(self.current()["active"])
        self.assertEqual([c[0] for c in json.loads(self.state.read_text())["calls"]].count("restart"), 1)
        self.save_state(calls=[])
        self.run_lifecycle("update")
        self.assertNotIn("restart", [c[0] for c in json.loads(self.state.read_text())["calls"]])

    def test_stopped_intent_handles_activating_service(self):
        self.unit.write_text(self.content)
        self.drift(active="activating", enabled=True)
        self.inputs["intent"]["installation"]["desired"] = "stopped"
        self.run_lifecycle()
        self.assertFalse(self.current()["active"])
        self.assertFalse(self.current()["enabled"])

    def test_foreign_unit_refuses_before_any_mutation(self):
        self.unit.write_text("[Unit]\nDescription=foreign\n")
        self.run_lifecycle(success=False)
        self.assertEqual(self.unit.read_text(), "[Unit]\nDescription=foreign\n")
        self.assertEqual(json.loads(self.state.read_text())["calls"], [])

    def test_missing_mount_refuses_before_unit_publication(self):
        path = str(self.repo / "missing")
        self.inputs["intent"]["mounts"] = [{"path": path, "index": 0}]
        install = self.inputs["intent"]["installation"]
        install["required_mount_paths"] = [path]
        install["unit"] = self.content.replace(
            "[Unit]\n", "[Unit]\nRequiresMountsFor=" + path + "\n"
        )
        install["content_hash"] = hashlib.sha256(install["unit"].encode()).hexdigest()
        self.run_lifecycle(success=False)
        self.assertFalse(self.unit.exists())
        self.assertFalse(
            any(x[0] == "start" for x in json.loads(self.state.read_text())["calls"])
        )

    def test_start_failure_records_failed_run_and_rerun_converges(self):
        self.save_state(failures=["start"])
        self.run_lifecycle(success=False)
        self.assertTrue(self.unit.exists())
        self.run_lifecycle()
        self.assertTrue(self.current()["active"])

    def save_state(self, **changes):
        state = json.loads(self.state.read_text()) if self.state.exists() else {}
        state.update(changes)
        self.state.write_text(json.dumps(state))

    def test_environment_copy_is_delivered_by_stdin_without_history_or_argv_leak(self):
        self.verify_environment_copy("create")

    def test_update_environment_copy_uses_its_own_key_without_history_or_argv_leak(self):
        self.verify_environment_copy("update")

    def verify_environment_copy(self, kind):
        secret = "lifecycle-native-private-" + os.urandom(12).hex()
        content = '# Orbit Process 17\nTOKEN="' + secret + '"\n'
        digest = hashlib.sha256(content.encode()).hexdigest()
        logical = "/etc/orbit/processes/17/" + digest + ".env"
        install = self.inputs["intent"]["installation"]
        install.update(
            unit=self.content + "EnvironmentFile=" + logical + "\n",
            has_environment=True,
            environment_path=logical,
        )
        install["content_hash"] = hashlib.sha256(install["unit"].encode()).hexdigest()
        self.environment_path = self.host_root / "processes/17" / (digest + ".env")
        self.cli(
            "vault",
            "put",
            "orbit-control-inputs",
            "process-" + kind + "-operation-17",
            input=content,
        )
        self.secrets = (secret,)
        self.inputs["execution_secrets"] = {
            "environment": {
                "vault": "orbit-control-inputs",
                "key": "process-" + kind + "-operation-17",
            }
        }
        self.run_lifecycle(kind)
        self.assertEqual(self.environment_path.read_text(), content)
        self.assertEqual(self.environment_path.stat().st_mode & 0o777, 0o600)
        self.assert_private_absent(
            self.cli("workflow", "history", "get", self.run["id"]).stdout
        )

    def assert_private_absent(self, *outputs):
        for output in outputs:
            self.assertFalse(any(value in output for value in self.secrets))
        for path in self.repo.rglob("*"):
            if (
                not path.is_file()
                or path.is_symlink()
                or path == getattr(self, "environment_path", None)
            ):
                continue
            self.assertFalse(
                any(value.encode() in path.read_bytes() for value in self.secrets),
                "Private bytes outside EnvironmentFile: " + str(path),
            )

    def test_docker_create_state_and_destroy_use_the_same_native_dags(self):
        import socketserver
        import threading
        from http.server import BaseHTTPRequestHandler

        state_path = self.state

        class Engine(BaseHTTPRequestHandler):
            def do_POST(handler):
                body = json.loads(
                    handler.rfile.read(int(handler.headers["Content-Length"]))
                )
                state = json.loads(state_path.read_text())
                state["docker"] = {"present": True, "state": "created", "body": body}
                state["creates"] = state.get("creates", 0) + 1
                state_path.write_text(json.dumps(state))
                raw = json.dumps({"Id": "e" * 64}).encode()
                handler.send_response(201)
                handler.send_header("Content-Length", str(len(raw)))
                handler.end_headers()
                handler.wfile.write(raw)

            def log_message(handler, *_):
                pass

        socket_path = self.repo / "docker.sock"
        engine = socketserver.UnixStreamServer(str(socket_path), Engine)
        thread = threading.Thread(target=engine.serve_forever, daemon=True)
        thread.start()
        self.addCleanup(engine.server_close)
        self.addCleanup(engine.shutdown)
        docker = self.repo / "cfgmgmt-tools/docker"
        docker.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import json,sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());a=sys.argv[2:];d=s.setdefault('docker',{'present':False})
s.setdefault('docker_calls',[]).append(a)
if a[:2]==['image','inspect']:print('image-present')
elif a[:2]==['container','ls']:
 if d['present']:print(NAME)
elif a[:2]==['container','inspect']:
 print('true\\nprocess\\n17\\n'+d['body']['Labels']['orbit.process.spec']+'\\n'+d['state'])
elif a[:2]==['container','start']:d['state']='running'
elif a[:2]==['container','stop']:d['state']='exited'
elif a[:2]==['container','rm']:d['present']=False
else:raise SystemExit(1)
p.write_text(json.dumps(s))
""".replace("STATE", repr(str(self.state))).replace(
                "NAME", repr("orbit-process-17-proof")
            )
        )
        docker.chmod(0o700)
        scripts = self.repo / "extensions/behaviors/operations/process-commands/scripts"
        p = scripts / "process_common.py"
        p.write_text(p.read_text().replace('"/usr/bin/docker"', repr(str(docker))))
        p = scripts / "docker_files.py"
        p.write_text(
            p.read_text().replace('"/var/run/docker.sock"', repr(str(socket_path)))
        )
        control = self.inputs["intent"]["control"]
        control.update(runtime="docker", name="orbit-process-17-proof")
        self.inputs["intent"]["installation"] = {
            "process_id": 17,
            "runtime": "docker",
            "name": control["name"],
            "on_demand": False,
            "desired": "running",
            "spec": "a" * 64,
            "has_environment": False,
            "retired": None,
            "definition": {
                "image": "example:1",
                "command": ["echo", "ok"],
                "restart": "no",
                "working_directory": "/work",
                "ports": [],
                "mounts": [{"source": "proof-volume", "target": "/data", "read_only": False}],
            },
        }
        self.run_lifecycle()
        state = json.loads(self.state.read_text())["docker"]
        self.assertEqual(state["state"], "running")
        self.assertEqual(state["body"]["Env"], [])
        self.assertEqual(state["body"]["Cmd"], ["echo", "ok"])
        self.assertEqual(state["body"]["WorkingDir"], "/work")
        self.run_lifecycle()
        self.assertEqual(json.loads(self.state.read_text())["creates"], 1)
        install = self.inputs["intent"]["installation"]
        install["spec"] = "b" * 64
        install["definition"].update(image="example:2", command=["echo", "updated"])
        self.inputs["operation_id"] = 18
        self.run_lifecycle("update")
        state = json.loads(self.state.read_text())
        self.assertEqual(state["creates"], 2)
        self.assertEqual(state["docker"]["state"], "running")
        self.assertEqual(state["docker"]["body"]["Image"], "example:2")
        self.assertEqual(state["docker"]["body"]["Cmd"], ["echo", "updated"])
        self.assertEqual(state["docker"]["body"]["Labels"]["orbit.process.id"], "17")
        self.assertEqual(state["docker"]["body"]["HostConfig"]["Mounts"][0]["Source"], "proof-volume")
        removals = [call for call in state["docker_calls"] if call[:2] == ["container", "rm"]]
        self.assertEqual(removals, [["container", "rm", "--volumes", "orbit-process-17-proof"]])
        self.run_lifecycle("update")
        self.assertEqual(json.loads(self.state.read_text())["creates"], 2)
        install.update(spec="c" * 64, desired="stopped")
        self.run_lifecycle("update")
        self.assertEqual(json.loads(self.state.read_text())["docker"]["state"], "exited")
        self.assertEqual(json.loads(self.state.read_text())["creates"], 3)
        self.inputs["intent"].pop("installation")
        self.run_lifecycle("destroy")
        self.run_lifecycle("destroy")
        self.assertFalse(json.loads(self.state.read_text())["docker"]["present"])

    def test_pending_activation_survives_failed_reload_on_changed_active_unit(self):
        self.unit.write_text(self.content.replace("30", "20"))
        self.drift(active=True, enabled=True)
        # The Gateway derives this from content_hash != last accepted systemd_activation_hash.
        self.inputs["intent"]["installation"]["activation_pending"] = True
        self.save_state(failures=["daemon-reload"])
        self.run_lifecycle(success=False)
        self.assertEqual(self.unit.read_text(), self.content)
        self.save_state(calls=[])
        self.run_lifecycle()
        self.assertIn(
            "restart", [x[0] for x in json.loads(self.state.read_text())["calls"]]
        )

    def test_stopped_intent_stops_reloading_service(self):
        self.unit.write_text(self.content)
        self.drift(active="reloading", enabled=True)
        self.inputs["intent"]["installation"]["desired"] = "stopped"
        self.run_lifecycle()
        self.assertFalse(self.current()["active"])

    def test_missing_mount_does_not_block_stopped_installation(self):
        self.inputs["intent"]["installation"]["desired"] = "stopped"
        path = str(self.repo / "missing")
        install = self.inputs["intent"]["installation"]
        install["required_mount_paths"] = [path]
        install["unit"] = self.content.replace(
            "[Unit]\n", "[Unit]\nRequiresMountsFor=" + path + "\n"
        )
        install["content_hash"] = hashlib.sha256(install["unit"].encode()).hexdigest()
        self.inputs["intent"]["mounts"] = [{"path": path, "index": 0}]
        self.run_lifecycle()
        self.assertTrue(self.unit.exists())
        self.assertFalse(self.current()["active"])

    @unittest.expectedFailure
    def test_failed_start_resumes_from_native_step_without_republishing_unit(self):
        self.save_state(failures=["start"])
        self.run_lifecycle(success=False)
        inode = self.unit.stat().st_ino
        self.cli(
            "workflow",
            "resume",
            "operation-process-create",
            "--run",
            self.run["id"],
            "--from",
            "service",
        )
        self.assertEqual(self.unit.stat().st_ino, inode)
        self.assertTrue(self.current()["active"])

    def test_mount_fanout_has_distinct_per_item_models(self):
        paths = [str(self.repo / "mount-one"), str(self.repo / "mount-two")]
        for path in paths:
            Path(path).mkdir()
        install = self.inputs["intent"]["installation"]
        install["required_mount_paths"] = paths
        install["unit"] = self.content.replace(
            "[Unit]\n", "[Unit]\nRequiresMountsFor=" + " ".join(paths) + "\n"
        )
        install["content_hash"] = hashlib.sha256(install["unit"].encode()).hexdigest()
        self.inputs["intent"]["mounts"] = [
            {"index": index, "path": path} for index, path in enumerate(paths)
        ]
        self.run_lifecycle()
        names = []
        for index in range(2):
            model = json.loads(
                self.cli("model", "get", f"process-mount-17-{index}").stdout
            )
            names.append(model["name"])
        self.assertEqual(names, ["process-mount-17-0", "process-mount-17-1"])
