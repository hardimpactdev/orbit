"""The NIC move of a role converge (tasks/instance-network-move-nic.yml) with a container that a removal deleted after the listing: Incus
confirms it is gone and the converge goes on; a failed update of a container that still exists fails it."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
TASKS = ROOT / "swamp/ansible/tasks/instance-network-move-nic.yml"

# A fake Incus: every `config device` call fails as the real one does; a query answers from GONE, the container names it lists as deleted.
FAKE = """#!/usr/bin/env python3
import os, sys
args = sys.argv[1:]
if args[0] == "--project":
    print("Error: " + os.environ["FAKE_DEVICE_ERROR"], file=sys.stderr)
    sys.exit(1)
name, project = args[2].split("/")[3].split("?project=")
if name in os.environ["FAKE_GONE"].split(","):
    print(f'Error: Failed to fetch instance "{name}" in project "{project}": Instance not found', file=sys.stderr)
    sys.exit(1)
print("{}")
"""


@unittest.skipUnless(shutil.which("ansible-playbook"), "pinned Ansible required")
class NetworkMoveGoneTest(unittest.TestCase):
    def run_move(self, gone, error):
        with tempfile.TemporaryDirectory(prefix="orbit-network-move-") as temp:
            root = Path(temp)
            (root / "bin").mkdir()
            (root / "bin/incus").write_text(FAKE)
            (root / "bin/incus").chmod(0o755)
            container = {"project": "orbit-instances", "name": "instance-7", "devices": {},
                         "expanded_devices": {"eth0": {"type": "nic", "network": "orbitinst0", "security.acls": "orbitinst0-egress"}}}
            play = [{"hosts": "localhost", "connection": "local", "gather_facts": False,
                     "vars": {"ansible_python_interpreter": sys.executable, "instances": {"network": "orbitinst0", "nic_acl": "orbit-nic"},
                              "move_container": container},
                     "tasks": [{"ansible.builtin.include_tasks": str(TASKS)}]}]
            (root / "play.json").write_text(json.dumps(play))
            return subprocess.run(["ansible-playbook", "-i", "localhost,", str(root / "play.json")],
                                  env=os.environ | {"PATH": f"{root / 'bin'}:{os.environ['PATH']}", "FAKE_GONE": gone, "FAKE_DEVICE_ERROR": error,
                                                    "ANSIBLE_STDOUT_CALLBACK": "default", "ANSIBLE_COLLECTIONS_PATH": str(root / "empty")},
                                  capture_output=True, text=True, check=False, timeout=60)

    def test_a_container_deleted_after_the_listing_is_skipped(self):
        done = self.run_move("instance-7", 'Failed to fetch instance "instance-7" in project "orbit-instances": Instance not found')
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)

    def test_a_failed_update_of_a_container_that_exists_fails(self):
        done = self.run_move("", "Failed to update device: input/output error")
        self.assertNotEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertIn("instance.host_unavailable: orbit-instances/instance-7", done.stdout)
        self.assertIn("input/output error", done.stdout)


if __name__ == "__main__":
    unittest.main()
