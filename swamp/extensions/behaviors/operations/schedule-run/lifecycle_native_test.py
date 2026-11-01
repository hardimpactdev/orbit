"""Run the production lifecycle DAGs with unmodified official cfgmgmt and SSH models."""

import grp
import json
import os
import pwd
import shutil
import sys

import yaml
from native_test import ROOT, ScheduleSshFixture


class ScheduleLifecycleNative(ScheduleSshFixture):
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
shutil.copyfile(sys.argv[-2],sys.argv[-1].split(':',1)[1])
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
elif action in ['start','stop']:
 if action=='start' and not (Path(UNITS)/unit).exists():p.write_text(json.dumps(s));raise SystemExit(5)
 current['active']=action=='start'
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
        for kind in ["create", "destroy", "control"]:
            workflow = yaml.safe_load(
                (
                    ROOT / f"workflows/workflow-operation-schedule-{kind}.yaml"
                ).read_text()
            )
            self.assertLessEqual(
                len(
                    (ROOT / f"workflows/workflow-operation-schedule-{kind}.yaml")
                    .read_text()
                    .splitlines()
                ),
                300 if kind == "create" else 200,
            )
            for job in workflow["jobs"]:
                self.assertEqual(job.pop("target"), "orbit-node-209")
                self.assertNotIn("labels", job)
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertNotIn("labels", step)
                    # Map declared root ownership to this disposable fixture account.
                    if step["task"]["behaviorType"] == "@swamp/cfgmgmt/file":
                        globals = step["task"]["globalArgs"]
                        if "owner" in globals:
                            globals["owner"] = pwd.getpwuid(os.getuid()).pw_name
                            globals["group"] = grp.getgrgid(os.getgid()).gr_name
                    if step["task"]["behaviorType"] == "@swamp/ssh":
                        step["task"]["globalArgs"].update(
                            sshBinary=str(self.ssh), scpBinary=str(self.scp)
                        )
            (
                self.repo / f"workflows/workflow-operation-schedule-{kind}.yaml"
            ).write_text(yaml.safe_dump(workflow, sort_keys=False))
        self.inputs["intent"]["schedule"].update(calendar="hourly", desired="disabled")
        self.inputs["intent"]["cascade"] = False
        self.inputs["execution_secrets"] = {}

    def save_state(self, **changes):
        state = json.loads(self.state.read_text()) if self.state.exists() else {}
        state.update(changes)
        self.state.write_text(json.dumps(state))

    def unit_name(self, kind):
        return "orbit-schedule-" + self.inputs["intent"]["schedule"]["id"] + "." + kind

    def unit(self, kind):
        return self.host / "units" / self.unit_name(kind)

    def current(self, kind):
        return json.loads(self.state.read_text())["units"][self.unit_name(kind)]

    def drift(self, kind, **changes):
        state = json.loads(self.state.read_text())
        state["units"].setdefault(
            self.unit_name(kind), {"enabled": False, "active": False}
        ).update(changes)
        self.state.write_text(json.dumps(state))

    def run_lifecycle(self, kind="create", success=True):
        self.inputs["kind"] = "schedule." + kind
        reply = self.cli(
            "workflow",
            "run",
            "operation-schedule-" + kind,
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        self.run = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]
        return self.run

    def history(self):
        return json.loads(self.cli("workflow", "history", "get", self.run["id"]).stdout)

    def result(self):
        step = next(
            s for s in self.history()["jobs"][0]["steps"] if s["name"] == "result"
        )
        return json.loads(step.get("outputs", {}).get("stdout", "{}"))

    def test_create_converges_timer_intent_and_repeated_create_is_compliant(self):
        self.inputs["intent"]["schedule"]["desired"] = "enabled"
        self.run_lifecycle()
        self.assertEqual(self.result(), {"ready": True})
        self.assertEqual(self.current("timer"), {"active": True, "enabled": True})
        self.assertIn("/api/v1/schedules/", self.unit("service").read_text())
        self.drift("timer", active=False, enabled=False)
        self.run_lifecycle()
        self.assertEqual(self.current("timer"), {"active": True, "enabled": True})
        self.save_state(calls=[])
        self.run_lifecycle()
        self.assertFalse(
            any(
                c[0] not in ["show", "daemon-reload"]
                for c in json.loads(self.state.read_text())["calls"]
            )
        )
        self.assertFalse(list(self.host.glob("**/*snapshot*")))

    def run_enable(self, success=True):
        self.inputs["kind"] = "schedule.enable"
        self.inputs["intent"] = {
            "schedule": {"id": self.inputs["intent"]["schedule"]["id"]},
            "ssh_execution": self.inputs["intent"]["ssh_execution"],
        }
        reply = self.cli(
            "workflow",
            "run",
            "operation-schedule-control",
            "--stdin",
            input=json.dumps(self.inputs),
            success=success,
        )
        self.run = json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]

    def test_enable_starts_only_the_installed_timer_without_private_material(self):
        self.run_lifecycle()
        before = {p.name: p.read_text() for p in (self.host / "units").iterdir()}
        self.save_state(calls=[])
        self.run_enable()
        self.assertEqual(self.result(), {"ready": True})
        self.assertEqual(self.current("timer"), {"active": True, "enabled": True})
        self.assertEqual(
            {p.name: p.read_text() for p in (self.host / "units").iterdir()}, before
        )
        self.assertEqual(
            [c[0] for c in json.loads(self.state.read_text())["calls"]],
            ["show", "enable", "start", "show"],
        )
        self.save_state(calls=[])
        self.run_enable()
        self.assertEqual(self.result(), {"ready": True})
        self.assertEqual(
            [c[0] for c in json.loads(self.state.read_text())["calls"]], ["show"]
        )

    def test_failed_enable_skips_success_and_rerun_converges_without_rollback(self):
        self.run_lifecycle()
        self.save_state(calls=[], failures=["start"])
        self.run_enable(success=False)
        self.assertEqual(self.run["status"], "failed")
        self.assertEqual(self.result(), {})
        self.assertEqual(self.current("timer"), {"active": False, "enabled": True})
        self.assertNotIn(
            "disable", [c[0] for c in json.loads(self.state.read_text())["calls"]]
        )
        self.run_enable()
        self.assertEqual(self.current("timer"), {"active": True, "enabled": True})
        self.assertEqual(self.result(), {"ready": True})

    def test_enable_missing_timer_fails_without_creating_units(self):
        self.run_enable(success=False)
        self.assertEqual(self.run["status"], "failed")
        self.assertEqual(self.result(), {})
        self.assertEqual(list((self.host / "units").iterdir()), [])

    def test_disabled_create_removes_obsolete_files_without_reading_private_bytes(self):
        identity = self.inputs["intent"]["schedule"]["id"]
        private = "obsolete-private-command"
        for suffix in [".sh", ".complete"]:
            (self.host / "schedules" / (identity + suffix)).write_text(private)
        self.run_lifecycle()
        self.assertEqual(self.result(), {"ready": True})
        self.assertEqual(self.current("timer"), {"active": False, "enabled": False})
        self.assertEqual(list((self.host / "schedules").iterdir()), [])
        self.assertNotIn(private, json.dumps(self.history()))

    def test_failed_apply_keeps_files_and_rerun_converges_without_rollback(self):
        self.inputs["intent"]["schedule"]["desired"] = "enabled"
        self.save_state(failures=["start"])
        self.run_lifecycle(success=False)
        self.assertEqual(self.run["status"], "failed")
        self.assertEqual(self.result(), {})
        self.assertTrue(self.unit("service").exists())
        self.assertTrue(self.unit("timer").exists())
        self.assertFalse(
            any(
                s["name"].startswith("rollback")
                for s in self.history()["jobs"][0]["steps"]
            )
        )
        self.run_lifecycle()
        self.assertEqual(self.result(), {"ready": True})
        self.assertTrue(self.current("timer")["active"])

    def test_destroy_checks_busy_work_before_effects_and_cascade_stops_it(self):
        self.run_lifecycle()
        self.drift("service", active=True)
        self.save_state(calls=[])
        self.run_lifecycle("destroy", success=False)
        self.assertEqual(self.run["status"], "failed")
        self.assertTrue(self.unit("timer").exists())
        self.assertTrue(self.current("service")["active"])
        self.assertTrue(
            all(
                c[0] == "is-active" for c in json.loads(self.state.read_text())["calls"]
            )
        )
        self.inputs["intent"]["cascade"] = True
        self.run_lifecycle("destroy")
        self.assertEqual(self.result(), {"ready": True})
        self.assertFalse(self.current("service")["active"])
        self.assertFalse(self.unit("service").exists())
        self.assertFalse(self.unit("timer").exists())

    def test_partial_destroy_reruns_and_absent_destroy_is_compliant(self):
        self.run_lifecycle()
        (self.host / "fail-remove").write_text(str(self.unit("service")))
        self.run_lifecycle("destroy", success=False)
        self.assertEqual(self.result(), {})
        self.assertTrue(self.unit("service").exists())
        for _ in range(2):
            self.run_lifecycle("destroy")
            self.assertEqual(self.result(), {"ready": True})
            self.assertFalse(self.unit("service").exists())
            self.assertFalse(self.unit("timer").exists())

    def test_cascade_stops_an_activating_oneshot_before_removing_units(self):
        self.run_lifecycle()
        self.drift("service", active="activating")
        self.inputs["intent"]["cascade"] = True
        self.run_lifecycle("destroy")
        self.assertEqual(self.result(), {"ready": True})
        self.assertFalse(self.current("service")["active"])
        self.assertIn(
            ["stop", self.unit_name("service")],
            json.loads(self.state.read_text())["calls"],
        )
        self.assertFalse(self.unit("service").exists())

    def test_rerun_reloads_failed_replacement_before_removing_old_runner(self):
        self.inputs["intent"]["schedule"]["desired"] = "enabled"
        self.run_lifecycle()
        self.unit("service").write_text("obsolete-service-definition")
        state = json.loads(self.state.read_text())
        state["loaded"][self.unit_name("service")] = "obsolete-service-definition"
        self.save_state(loaded=state["loaded"], failures=["daemon-reload"])
        obsolete = (
            self.host / "schedules" / (self.inputs["intent"]["schedule"]["id"] + ".sh")
        )
        obsolete.write_text("obsolete-private-command")
        self.run_lifecycle(success=False)
        self.assertTrue(obsolete.exists())
        self.assertEqual(
            json.loads(self.state.read_text())["loaded"][self.unit_name("service")],
            "obsolete-service-definition",
        )
        self.run_lifecycle()
        self.assertEqual(self.result(), {"ready": True})
        self.assertEqual(
            json.loads(self.state.read_text())["loaded"][self.unit_name("service")],
            self.unit("service").read_text(),
        )
        self.assertFalse(obsolete.exists())

    def test_obsolete_file_fanout_uses_distinct_model_names(self):
        self.run_lifecycle()
        names = []
        for suffix in ['.sh', '.complete']:
            name = 'schedule-remove_obsolete-' + ('script' if suffix == '.sh' else 'complete') + '-' + str(self.inputs['operation_id'])
            model = json.loads(self.cli('model', 'get', name).stdout)
            names.append(model['name'])
        self.assertEqual(len(set(names)), 2)

    def test_destroy_file_fanout_uses_one_model_per_path(self):
        self.run_lifecycle()
        self.run_lifecycle("destroy")
        paths = []
        names = []
        for index in range(4):
            name = "schedule-remove_files-" + self.run["id"] + "-" + str(index)
            model = json.loads(self.cli("model", "get", name).stdout)
            names.append(model["name"])
            paths.append(model["globalArguments"]["path"])
        self.assertEqual(len(set(names)), 4)
        self.assertEqual(len(set(paths)), 4)
        self.assertFalse(self.unit("timer").exists())
        self.assertFalse(self.unit("service").exists())

    def test_definition_parent_converges_two_members_without_model_lock_collisions(self):
        import copy
        import uuid
        workflow = yaml.safe_load((ROOT / "workflows/workflow-operation-schedule-install-instance-definitions.yaml").read_text())
        for job in workflow["jobs"]:
            self.assertEqual(job.pop("target"), "orbit-node-209")
            for step in job["steps"]:
                self.assertEqual(step.pop("target"), "orbit-node-209")
        (self.repo / "workflows/workflow-operation-schedule-install-instance-definitions.yaml").write_text(yaml.safe_dump(workflow, sort_keys=False))
        systemctl = self.repo / "cfgmgmt-tools/systemctl"
        text = systemctl.read_text()
        text = text.replace("import json,sys", "import json,sys,fcntl\nlock=open(" + repr(str(self.host / "systemd.lock")) + ", 'a')\nfcntl.flock(lock,fcntl.LOCK_EX)")
        systemctl.write_text(text)
        members = []
        for _ in range(2):
            intent = copy.deepcopy(self.inputs["intent"])
            intent["schedule"].update(id=str(uuid.uuid4()), desired="disabled")
            members.append({"id": intent["schedule"]["id"], "intent": intent})
        value = {"operation_id": 17, "node_id": 9, "kind": "schedule.install_instance_definitions", "intent": {"instance_id": 4, "members": members}, "execution_secrets": {}}
        reply = self.cli("workflow", "run", "operation-schedule-install-instance-definitions", "--stdin", input=json.dumps(value))
        self.assertEqual(json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]["status"], "succeeded")
        for member in members:
            name = "orbit-schedule-" + member["id"]
            for suffix in [".service", ".timer"]:
                self.assertTrue((self.host / "units" / (name + suffix)).exists())
            state = json.loads(self.state.read_text())["units"][name + ".timer"]
            self.assertFalse(state["active"])
            self.assertFalse(state["enabled"])
        value["intent"]["members"] = []
        reply = self.cli("workflow", "run", "operation-schedule-install-instance-definitions", "--stdin", input=json.dumps(value))
        self.assertEqual(json.JSONDecoder().raw_decode(reply.stdout.lstrip())[0]["status"], "succeeded")
