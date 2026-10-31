"""The existing installation JSON through the two native task input mechanisms."""

import importlib
import io
import json
import os
import sys
import unittest
from pathlib import Path
from unittest.mock import patch

sys.path.insert(0, str(Path(__file__).parent / "scripts"))
files = importlib.import_module("unit_files")
common = importlib.import_module("process_common")


class MaterialDelivery(unittest.TestCase):
    def setUp(self):
        self.control = {"process_id": 17}
        self.content = "[Service]\nX-Orbit-Process-ID=17\n"
        self.admitted = {"runtime": "systemd", "content_hash": files.digest(self.content)}
        self.material = {"runtime": "systemd", "content": self.content, "environment": "TOKEN=private-fixture\n"}

    def test_official_ssh_stdin_reads_the_existing_value_and_scrubs_the_environment(self):
        with (patch.object(sys, "argv", ["stage_unit.py", "--material-stdin"]),
              patch.object(sys, "stdin", io.StringIO(json.dumps(self.material))),
              patch.dict(os.environ, {"ORBIT_PROCESS_MATERIAL": "unused-environment-value"})):
            self.assertEqual(files.material(self.control, self.admitted), self.material)
            self.assertNotIn("ORBIT_PROCESS_MATERIAL", os.environ)

    def test_stdin_accepts_the_existing_batch_shape_without_another_envelope(self):
        batch = {"17": {"installation": self.material}}
        with (patch.object(sys, "argv", ["stage_unit.py", "--material-stdin"]),
              patch.object(sys, "stdin", io.StringIO(json.dumps(batch))),
              patch.dict(os.environ, {}, clear=True)):
            self.assertEqual(files.material(self.control, self.admitted), self.material)

    def test_explicit_stdin_never_falls_back_to_a_valid_environment_value(self):
        with (patch.object(sys, "argv", ["stage_unit.py", "--material-stdin"]),
              patch.object(sys, "stdin", io.StringIO("not-json")),
              patch.dict(os.environ, {"ORBIT_PROCESS_MATERIAL": json.dumps(self.material)})):
            with self.assertRaises(ValueError):
                files.material(self.control, self.admitted)
            self.assertNotIn("ORBIT_PROCESS_MATERIAL", os.environ)

    def test_core_command_shell_keeps_its_native_vault_environment_input(self):
        with (patch.object(sys, "argv", ["stage_unit.py"]),
              patch.object(sys, "stdin", io.StringIO("not-used")),
              patch.dict(os.environ, {"ORBIT_PROCESS_MATERIAL": json.dumps(self.material)})):
            self.assertEqual(files.material(self.control, self.admitted), self.material)
            self.assertNotIn("ORBIT_PROCESS_MATERIAL", os.environ)

    def test_stdin_preserves_systemd_hash_and_docker_spec_validation(self):
        for admitted, private, code in (
            ({**self.admitted, "content_hash": "b" * 64}, self.material, "process.invalid"),
            ({"runtime": "docker", "spec": "a" * 64}, {"runtime": "docker", "spec": "b" * 64}, "process.spec_changed"),
        ):
            with (self.subTest(runtime=admitted["runtime"]),
                  patch.object(sys, "argv", ["create_container.py", "--material-stdin"]),
                  patch.object(sys, "stdin", io.StringIO(json.dumps(private)))):
                with self.assertRaises(common.Refused) as refusal:
                    files.material(self.control, admitted)
                self.assertEqual(refusal.exception.code, code)


if __name__ == "__main__":
    unittest.main()
