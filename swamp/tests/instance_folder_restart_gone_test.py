"""The runtime folder's restart in a role converge (tasks/instance-folder.yml) with a container that a removal deleted after the listing:
Incus confirms it is gone and the restart goes on to the next; a failed restart of a container that still exists fails the run."""

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

import yaml

ROOT = Path(__file__).resolve().parents[2]
TASKS = ROOT / "swamp/ansible/tasks"
NAMES = ("Restart them one after another", "Fail a restart of a container that still exists")

# A fake Incus. `exec` fails for the containers in FAKE_GONE (deleted) and FAKE_BROKEN (an error of a container that exists), and logs
# every restart it ran; a query answers not found for FAKE_GONE only.
FAKE = """#!/usr/bin/env python3
import os, sys
args = sys.argv[1:]
gone, broken = os.environ["FAKE_GONE"].split(","), os.environ["FAKE_BROKEN"].split(",")
if args[0] == "exec":
    project, name = args[2], args[3]
    if name in gone:
        print(f'Error: Failed to fetch instance "{name}" in project "{project}": Instance not found', file=sys.stderr)
        sys.exit(1)
    if name in broken:
        print("Error: Failed to retrieve PID of executing child process", file=sys.stderr)
        sys.exit(1)
    open(os.environ["FAKE_LOG"], "a").write(name + "\\n")
    sys.exit(0)
name, project = args[2].split("/")[3].split("?project=")
if name in gone:
    print(f'Error: Failed to fetch instance "{name}" in project "{project}": Instance not found', file=sys.stderr)
    sys.exit(1)
print("{}")
"""


def restart_tasks():
    """The two shipped tasks of the restart block, as instance-folder.yml holds them."""
    for task in yaml.safe_load((TASKS / "instance-folder.yml").read_text()):
        for child in task.get("block", []):
            if child.get("name") in NAMES:
                yield child


@unittest.skipUnless(shutil.which("ansible-playbook"), "pinned Ansible required")
class FolderRestartGoneTest(unittest.TestCase):
    def run_restart(self, gone="", broken=""):
        with tempfile.TemporaryDirectory(prefix="orbit-folder-restart-") as temp:
            root = Path(temp)
            (root / "bin").mkdir()
            (root / "bin/incus").write_text(FAKE)
            (root / "bin/incus").chmod(0o755)
            shutil.copy(TASKS / "instance-containers-gone.yml", root / "instance-containers-gone.yml")
            tasks = list(restart_tasks())
            self.assertEqual([task["name"] for task in tasks], list(NAMES))
            targets = [{"project": "orbit-instances", "name": name} for name in ("instance-3", "instance-4", "instance-6")]
            play = [{"hosts": "localhost", "connection": "local", "gather_facts": False,
                     "vars": {"ansible_python_interpreter": sys.executable, "folder_name": "runtime", "folder_targets": targets},
                     "tasks": tasks}]
            (root / "play.json").write_text(json.dumps(play))
            log = root / "restarted"
            log.write_text("")
            done = subprocess.run(["ansible-playbook", "-i", "localhost,", str(root / "play.json")],
                                  env=os.environ | {"PATH": f"{root / 'bin'}:{os.environ['PATH']}", "FAKE_GONE": gone, "FAKE_BROKEN": broken,
                                                    "FAKE_LOG": str(log), "ANSIBLE_STDOUT_CALLBACK": "default",
                                                    "ANSIBLE_COLLECTIONS_PATH": str(root / "empty")},
                                  capture_output=True, text=True, check=False, timeout=60)
            return done, log.read_text().split()

    def test_a_container_deleted_after_the_listing_is_skipped_and_the_others_restart(self):
        done, restarted = self.run_restart(gone="instance-4")
        self.assertEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(restarted, ["instance-3", "instance-6"])

    def test_a_failed_restart_of_a_container_that_exists_fails_the_run(self):
        done, restarted = self.run_restart(gone="instance-3", broken="instance-6")
        self.assertNotEqual(done.returncode, 0, done.stdout + done.stderr)
        self.assertEqual(restarted, ["instance-4"])
        self.assertIn("instance.host_unavailable: orbit-instances/instance-6: Error: Failed to retrieve PID", done.stdout)
        self.assertNotIn("orbit-instances/instance-3: Error", done.stdout)


if __name__ == "__main__":
    unittest.main()
