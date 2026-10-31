"""Real file publication and replay, with only the system manager boundary replaced."""

import importlib
import json
import os
import stat
import sys
import tempfile
import unittest
from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).parent / "scripts"))
common = importlib.import_module("process_common")
files = importlib.import_module("unit_files")
environment = importlib.import_module("publish_environment")
stage = importlib.import_module("stage_unit")
validate = importlib.import_module("validate_unit")
backup = importlib.import_module("backup_unit")
publish = importlib.import_module("publish_unit")
restore = importlib.import_module("restore_unit")
reload = importlib.import_module("reload_units")
remove = importlib.import_module("remove_unit")
remove_environment = importlib.import_module("remove_environment")
release = importlib.import_module("check_release")


class UnitInstallation(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.host = Path(self.temp.name)
        self.units = self.host / "units"
        self.units.mkdir()
        self.root = self.host / "orbit"
        self.control = {
            "process_id": 17,
            "runtime": "systemd",
            "name": "orbit-process-17-worker.service",
            "on_demand": False,
        }
        self.unit = self.units / self.control["name"]
        self.private_environment = (
            '# Orbit Process 17\nTOKEN="private-installation-sentinel"\n'
        )
        self.environment_path = (
            self.root
            / "processes/17"
            / (files.digest(self.private_environment) + ".env")
        )
        self.content = (
            "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/true\nEnvironmentFile="
            + str(self.environment_path)
            + "\n"
        )
        self.admitted = {
            **self.control,
            "desired": "running",
            "retired": None,
            "content_hash": files.digest(self.content),
            "activation_pending": False,
        }
        self.material = {
            "runtime": "systemd",
            "content": self.content,
            "environment": self.private_environment,
        }
        self.stage_hash = None
        self.previous_hash = None
        self.calls = []
        self.state = "inactive"
        self.addCleanup(patch.stopall)
        patch.object(common, "UNITS", self.units).start()
        patch.object(common, "ROOT_UID", os.getuid()).start()
        patch.object(common, "ROOT_GID", os.getgid()).start()
        patch.object(files, "ORBIT_ROOT", self.root).start()
        patch.object(environment, "ORBIT_ROOT", self.root).start()
        patch.object(remove_environment, "ORBIT_ROOT", self.root).start()

        def command(argv):
            self.calls.append(argv)
            self.assertNotIn("private-installation-sentinel", repr(argv))
            if argv[:2] == ["/usr/bin/getent", "passwd"]:
                return SimpleNamespace(
                    returncode=0,
                    stdout="app:x:1001:1001::/srv/runtime user:/bin/bash\n",
                )
            return SimpleNamespace(
                returncode=0, stdout=self.state if "is-active" in argv else ""
            )

        patch.object(common, "command", side_effect=command).start()

    def invoke(self, action, batch=False):
        material = {"17": {"installation": self.material}} if batch else self.material
        with patch.dict(
            os.environ,
            {
                "ORBIT_PROCESS_INSTALLATION": json.dumps(self.admitted),
                "ORBIT_PROCESS_MATERIAL": json.dumps(material),
                "ORBIT_OPERATION_ID": "91",
                "ORBIT_PROCESS_STAGED_HASH": self.stage_hash or "",
                "ORBIT_PROCESS_PREVIOUS_HASH": self.previous_hash or "null",
            },
        ):
            return action(self.control)

    def prepare(self):
        self.invoke(environment.publish, batch=True)
        self.stage_hash = self.invoke(stage.stage, batch=True)["content_hash"]
        self.invoke(validate.validate)
        self.previous_hash = self.invoke(backup.backup)["previous_hash"]

    def test_environment_stage_validate_backup_and_publish_are_distinct_actions(self):
        self.prepare()
        self.assertFalse(self.unit.exists())
        candidate = (
            self.root
            / "process-commands/91/17"
            / self.admitted["content_hash"]
            / self.unit.name
        )
        self.assertEqual(stat.S_IMODE(candidate.stat().st_mode), 0o600)
        self.assertEqual(stat.S_IMODE(self.environment_path.stat().st_mode), 0o600)
        self.assertEqual(self.environment_path.read_text(), self.private_environment)
        self.assertTrue(
            all(
                "is-active" not in call and "daemon-reload" not in call
                for call in self.calls
            )
        )
        self.invoke(publish.publish)
        self.assertIsNone(self.invoke(backup.backup)["previous_hash"])
        inode = self.unit.stat().st_ino
        self.invoke(publish.publish)
        self.assertEqual(self.unit.stat().st_ino, inode)
        self.assertEqual(stat.S_IMODE(self.unit.stat().st_mode), 0o644)
        self.assertNotIn("private-installation-sentinel", self.unit.read_text())
        self.assertFalse(any("daemon-reload" in call for call in self.calls))
        self.invoke(reload.reload)
        self.assertEqual(self.calls[-1], [common.SYSTEMCTL, "daemon-reload"])

    def test_replacement_preserves_private_backup_and_restores_exact_previous_content(
        self,
    ):
        original = "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nEnvironment=OLD_SECRET=prior-private\n"
        self.unit.write_text(original)
        self.prepare()
        saved = (
            self.root
            / "process-commands/91/17"
            / self.admitted["content_hash"]
            / "previous.service"
        )
        self.assertEqual(saved.read_text(), original)
        self.assertEqual(stat.S_IMODE(saved.stat().st_mode), 0o600)
        self.invoke(publish.publish)
        self.assertEqual(self.unit.read_text(), self.content)
        self.invoke(restore.restore)
        self.assertEqual(self.unit.read_text(), original)
        self.assertEqual(stat.S_IMODE(self.unit.stat().st_mode), 0o600)
        self.invoke(restore.restore)
        self.assertEqual(self.unit.read_text(), original)
        self.assertFalse(any("restart" in call for call in self.calls))

    def test_create_restore_removes_new_unit_and_reload_accepts_absence(self):
        self.prepare()
        self.invoke(publish.publish)
        self.invoke(restore.restore)
        self.invoke(restore.restore)
        self.assertFalse(self.unit.exists())
        self.invoke(reload.reload)
        self.assertEqual(self.calls[-1], [common.SYSTEMCTL, "daemon-reload"])

    def test_foreign_unit_refuses_before_any_environment_or_stage_mutation(self):
        self.unit.write_text("[Unit]\nX-Orbit-Process-ID=18\n")
        for action in [environment.publish, stage.stage]:
            with self.assertRaises(common.Refused):
                self.invoke(action)
        self.assertFalse(self.root.exists())
        self.assertEqual(self.calls, [])

    def test_changed_content_or_environment_reference_refuses_before_publication(self):
        self.material["content"] += "\n"
        with self.assertRaises(common.Refused):
            self.invoke(stage.stage)
        self.assertFalse(self.root.exists())
        self.material["content"] = self.content
        self.material["environment"] += 'OTHER="unadmitted"\n'
        with self.assertRaises(common.Refused):
            self.invoke(environment.publish)
        self.assertFalse(self.root.exists())

    def test_changed_candidate_refuses_before_validation_or_live_unit_replacement(self):
        self.prepare()
        candidate = (
            self.root
            / "process-commands/91/17"
            / self.admitted["content_hash"]
            / self.unit.name
        )
        candidate.write_text(self.content + "\n")
        self.calls.clear()
        for action in [validate.validate, publish.publish]:
            with self.assertRaises(common.Refused):
                self.invoke(action)
        self.assertFalse(self.unit.exists())
        self.assertEqual(self.calls, [])

    def test_symlink_and_hardlinked_environment_are_never_overwritten(self):
        self.invoke(environment.publish)
        other = self.environment_path.with_name("other")
        self.environment_path.rename(other)
        self.environment_path.symlink_to(other)
        with self.assertRaises(common.Refused):
            self.invoke(environment.publish)
        self.environment_path.unlink()
        os.link(other, self.environment_path)
        with self.assertRaises(common.Refused):
            self.invoke(environment.publish)
        self.assertEqual(other.read_text(), self.private_environment)

    def test_account_home_is_observed_on_node_without_guessing_or_public_output(self):
        self.content = self.content.replace(
            "[Service]\n",
            "[Service]\nUser=app\nWorkingDirectory=/__orbit_process_account_home__/app\n",
        )
        self.material["content"] = self.content
        self.admitted.update(
            content_hash=files.digest(self.content), account_user="app"
        )
        result = self.invoke(stage.stage)
        candidate = (
            self.root
            / "process-commands/91/17"
            / self.admitted["content_hash"]
            / self.unit.name
        )
        self.assertIn(
            "WorkingDirectory=/srv/runtime\\x20user/app", candidate.read_text()
        )
        self.assertNotIn("/srv/", repr(result))
        self.assertEqual(self.calls, [["/usr/bin/getent", "passwd", "app"]])

    def test_retirement_requires_inactive_unit_and_removes_only_its_environment(self):
        self.prepare()
        self.invoke(publish.publish)
        self.state = "active"
        with self.assertRaises(common.Refused):
            remove.remove(self.control)
        with self.assertRaises(common.Refused):
            remove_environment.remove(self.control)
        self.assertTrue(self.environment_path.exists())
        self.state = "inactive"
        remove.remove(self.control)
        remove_environment.remove(self.control)
        remove_environment.remove(self.control)
        self.assertFalse(self.unit.exists())
        self.assertFalse(self.environment_path.exists())

    def test_unknown_environment_member_prevents_the_entire_cleanup(self):
        self.prepare()
        unknown = self.environment_path.parent / "unowned.txt"
        unknown.write_text("keep")
        unknown.chmod(0o600)
        with self.assertRaises(common.Refused):
            remove_environment.remove(self.control)
        self.assertTrue(self.environment_path.exists())
        self.assertEqual(unknown.read_text(), "keep")

    def test_publish_and_restore_refuse_a_changed_owned_live_specification(self):
        original = "[Unit]\nX-Orbit-Process-ID=17\n[Service]\nExecStart=/bin/false\n"
        self.unit.write_text(original)
        self.prepare()
        self.unit.write_text(original + "\n# newer\n")
        for action in [publish.publish, restore.restore]:
            with self.assertRaises(common.Refused):
                self.invoke(action)
        self.assertTrue(self.unit.read_text().endswith("# newer\n"))

    def test_release_absence_fails_without_a_host_mutation(self):
        with self.assertRaises(common.Refused) as failure:
            release.check({**self.control, "directory": str(self.host / "missing")})
        self.assertEqual(failure.exception.code, "process.release_unavailable")
        self.assertEqual(self.calls, [])


if __name__ == "__main__":
    unittest.main()
