"""Production create/destroy DAGs, official SSH, and a disposable host manager."""

import json
import os
import sys

import yaml
from native_test import ROOT, ScheduleSshFixture


class ScheduleLifecycleNative(ScheduleSshFixture):
    def setUp(self):
        super().setUp()
        self.secrets = ()
        self.host = self.repo / "host"
        (self.host / "etc/systemd/system").mkdir(parents=True)
        self.state = self.host / "manager.json"
        self.save_state(enabled=False, active=False, busy=False, calls=[], failures=[])
        manager = self.host / "systemctl"
        manager.write_text(
            "#!"
            + sys.executable
            + "\n"
            + """import json,sys
from pathlib import Path
p=Path(STATE);s=json.loads(p.read_text());a=sys.argv[1:];action=a[0];s['calls'].append(a)
if s['failures'] and s['failures'][0]==action:
 s['failures'].pop(0);p.write_text(json.dumps(s));raise SystemExit(1)
if action=='is-enabled':print('enabled' if s['enabled'] else 'disabled')
elif action=='is-active':print('active' if s['busy' if a[-1].endswith('.service') else 'active'] else 'inactive')
elif action=='enable':s['enabled']=True
elif action=='disable':
 s['enabled']=False
 if '--now' in a:s['active']=False
elif action in ['start','stop']:s['busy' if a[-1].endswith('.service') else 'active']=action=='start'
p.write_text(json.dumps(s))
""".replace("STATE", repr(str(self.state)))
        )
        manager.chmod(0o755)
        self.replacements = {
            "/usr/bin/systemctl": str(manager),
            "/usr/bin/systemd-analyze": "/usr/bin/true",
            "/etc/systemd/system": str(self.host / "etc/systemd/system"),
            "/etc/orbit": str(self.host / "etc/orbit"),
            "/tmp/orbit-schedule-lifecycle-": str(self.repo / "lifecycle-assets-"),
        }
        for p in (
            self.repo / "extensions/behaviors/operations/schedule-run/scripts"
        ).glob("*.py"):
            s = (
                p.read_text()
                .replace("ROOT_UID = 0", f"ROOT_UID = {os.getuid()}")
                .replace("ROOT_GID = 0", f"ROOT_GID = {os.getgid()}")
            )
            p.write_text(self.rewrite(s))
        ssh = self.ssh.read_text().replace(
            "argv=[a.replace('/usr/bin/systemd-cat',"
            + repr(str(self.logger))
            + ") for a in argv]",
            """while argv[0] in ['sudo','/usr/bin/sudo']:argv=argv[argv.index('--')+1:]
if argv[0]=='/usr/bin/install':
 argv[argv.index('-o')+1]=UID;argv[argv.index('-g')+1]=GID
failure=Path(FAILURE)
if failure.exists() and argv[0] in ['/usr/bin/mv','/usr/bin/rm'] and failure.read_text() in argv:
 failure.unlink();raise SystemExit(1)""".replace("UID", repr(str(os.getuid())))
            .replace("GID", repr(str(os.getgid())))
            .replace("FAILURE", repr(str(self.host / "fail-path"))),
        )
        self.ssh.write_text(ssh)
        for kind in ["create", "destroy"]:
            workflow = yaml.safe_load(
                (
                    ROOT / f"workflows/workflow-operation-schedule-{kind}.yaml"
                ).read_text()
            )
            for job in workflow["jobs"]:
                self.assertEqual(job.pop("target"), "orbit-node-209")
                self.assertEqual(job.pop("labels"), {"node": "209"})
                for step in job["steps"]:
                    self.assertEqual(step.pop("target"), "orbit-node-209")
                    self.assertEqual(step.pop("labels"), {"node": "209"})
                    if step["task"]["behaviorType"] == "@swamp/ssh":
                        step["task"]["globalArgs"].update(
                            sshBinary=str(self.ssh), scpBinary=str(self.scp)
                        )
            (
                self.repo / f"workflows/workflow-operation-schedule-{kind}.yaml"
            ).write_text(self.rewrite(yaml.safe_dump(workflow, sort_keys=False)))
        self.inputs["intent"]["schedule"].update(calendar="hourly", desired="disabled")
        self.inputs["intent"]["cascade"] = False
        self.inputs["execution_secrets"] = {}

    def set_command(self, command):
        super().set_command(command)
        self.command = command

    def rewrite(self, value):
        for old, new in self.replacements.items():
            value = value.replace(old, new)
        return value

    def save_state(self, **changes):
        s = json.loads(self.state.read_text()) if self.state.exists() else {}
        s.update(changes)
        self.state.write_text(json.dumps(s))

    def manager(self):
        return json.loads(self.state.read_text())

    def unit(self, kind):
        return (
            self.host
            / "etc/systemd/system"
            / ("orbit-schedule-" + self.inputs["intent"]["schedule"]["id"] + "." + kind)
        )

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
        for n, c in enumerate(reply.stdout):
            if c == "{":
                try:
                    self.run = json.JSONDecoder().raw_decode(reply.stdout[n:])[0]
                    if "id" in self.run:
                        return self.run
                except ValueError:
                    pass
        self.fail(reply.stdout + reply.stderr)

    def history(self):
        return json.loads(self.cli("workflow", "history", "get", self.run["id"]).stdout)

    def result(self):
        return json.loads(
            next(
                s for s in self.history()["jobs"][0]["steps"] if s["name"] == "result"
            )["outputs"]["stdout"]
        )

    def seed_legacy(self, enabled=False, active=True):
        self.run_lifecycle()
        legacy = json.loads(
            next(
                s for s in self.history()["jobs"][0]["steps"] if s["name"] == "assets"
            )["outputs"]["lifecycle_json"]
        )["legacy"]
        self.unit("service").write_text(legacy["directService"])
        directory = self.host / "etc/orbit/schedules"
        directory.mkdir(mode=0o700)
        identity = self.inputs["intent"]["schedule"]["id"]
        script = directory / (identity + ".sh")
        script.write_text(legacy["script_prefix"] + self.command + "\n")
        script.chmod(0o750)
        callback = directory / (identity + ".complete")
        callback.write_text(legacy["callback"])
        callback.chmod(0o700)
        self.save_state(enabled=enabled, active=active, calls=[])
        return script, callback

    def test_create_disabled_destroy_and_absent_destroy(self):
        self.run_lifecycle()
        self.assertEqual(self.result(), {"ready": True})
        self.assertIn("/api/v1/schedules/", self.unit("service").read_text())
        self.assertNotIn(self.command, self.unit("service").read_text())
        self.assertFalse(self.manager()["enabled"])
        self.assertFalse(self.manager()["active"])
        self.assertEqual(self.unit("service").stat().st_mode & 0o777, 0o644)
        self.assertFalse(list(self.repo.glob("lifecycle-assets-*")))
        for _ in range(2):
            self.run_lifecycle("destroy")
            self.assertEqual(self.result(), {"ready": True})
            self.assertFalse(self.unit("service").exists())
            self.assertFalse(self.unit("timer").exists())

    def test_create_enabled_and_identical_preserves_separate_timer_states(self):
        self.inputs["intent"]["schedule"]["desired"] = "enabled"
        self.run_lifecycle()
        self.assertTrue(self.manager()["enabled"])
        self.assertTrue(self.manager()["active"])
        self.save_state(enabled=False, active=True, calls=[])
        self.run_lifecycle()
        self.assertEqual(self.result(), {"ready": True})
        self.assertFalse(self.manager()["enabled"])
        self.assertTrue(self.manager()["active"])
        self.assertFalse(
            any(
                c[0] in ["disable", "enable", "start", "stop", "daemon-reload"]
                for c in self.manager()["calls"]
            )
        )

    def test_obsolete_files_migrate_without_private_native_outputs(self):
        script, callback = self.seed_legacy()
        self.run_lifecycle()
        self.assertEqual(self.result(), {"ready": True})
        self.assertFalse(script.exists())
        self.assertFalse(callback.exists())
        self.assertTrue(self.manager()["active"])
        self.assertFalse(self.manager()["enabled"])
        self.assertNotIn(self.command, json.dumps(self.history()))
        self.assertNotIn(self.command, self.calls.read_text())

    def test_wrong_hash_and_foreign_artifacts_refuse_before_mutation(self):
        script, _ = self.seed_legacy()
        script.write_text(script.read_text() + "changed\n")
        before = self.unit("service").read_text()
        self.run_lifecycle(success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.artifact_conflict"})
        self.assertEqual(self.unit("service").read_text(), before)
        self.assertEqual(self.manager()["calls"], [])
        script.unlink()
        self.unit("service").write_text("foreign")
        self.run_lifecycle("destroy", success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.artifact_conflict"})
        self.assertEqual(self.unit("service").read_text(), "foreign")

    def test_failed_publication_restores_files_and_timer_then_native_resume(self):
        script, callback = self.seed_legacy(enabled=True, active=False)
        originals = {
            p: p.read_bytes()
            for p in [script, callback, self.unit("service"), self.unit("timer")]
        }
        (self.host / "fail-path").write_text(str(self.unit("timer")))
        self.run_lifecycle(success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.install_failed"})
        for p, content in originals.items():
            self.assertEqual(p.read_bytes(), content)
        self.assertTrue(self.manager()["enabled"])
        self.assertFalse(self.manager()["active"])
        self.cli(
            "workflow",
            "resume",
            "operation-schedule-create",
            "--run",
            self.run["id"],
            "--from",
            "stage_service",
        )
        self.assertEqual(self.result(), {"ready": True})
        self.assertFalse(script.exists())
        self.assertFalse(callback.exists())

    def test_initial_failure_restores_absence_and_reports_failed_rollback(self):
        self.inputs["intent"]["schedule"]["desired"] = "enabled"
        self.save_state(failures=["start"])
        self.run_lifecycle(success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.install_failed"})
        self.assertFalse(self.unit("service").exists())
        self.assertFalse(self.unit("timer").exists())
        self.assertFalse(self.manager()["enabled"])
        self.assertFalse(self.manager()["active"])
        self.save_state(failures=["start", "daemon-reload"])
        self.run_lifecycle(success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.rollback_failed"})

    def test_busy_destroy_refuses_then_cascade_stops_before_removal(self):
        self.run_lifecycle()
        self.save_state(busy=True, calls=[])
        self.run_lifecycle("destroy", success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.run_active"})
        self.assertTrue(self.unit("service").exists())
        self.assertFalse(any(c[0] == "disable" for c in self.manager()["calls"]))
        self.inputs["intent"]["cascade"] = True
        self.run_lifecycle("destroy")
        self.assertEqual(self.result(), {"ready": True})
        self.assertFalse(self.manager()["busy"])
        self.assertFalse(self.unit("service").exists())

    def test_partial_removal_native_resume(self):
        self.run_lifecycle()
        (self.host / "fail-path").write_text(str(self.unit("service")))
        self.run_lifecycle("destroy", success=False)
        self.assertEqual(self.result(), {"error_code": "schedule.remove_failed"})
        self.assertFalse(self.unit("timer").exists())
        self.assertTrue(self.unit("service").exists())
        self.assertTrue(list(self.repo.glob("lifecycle-assets-*")))
        self.cli(
            "workflow",
            "resume",
            "operation-schedule-destroy",
            "--run",
            self.run["id"],
            "--from",
            "remove_service",
        )
        self.assertEqual(self.result(), {"ready": True})
        self.assertFalse(self.unit("service").exists())
        self.assertFalse(list(self.repo.glob("lifecycle-assets-*")))
