"""Run the response wait playbook against a stubbed former live: it waits for drained send queues, or logs a reached bound."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLAYBOOK = ROOT / "swamp/ansible/release-response-wait.yml"
# Each call prints the queued connections left in the counter file, then one fewer, as a slow download finishes.
INCUS = """#!/bin/sh
state="$FIXTURE_STATE"
echo "$*" >> "$state/calls"
left=$(cat "$state/busy")
i=0
while [ "$i" -lt "$left" ]; do echo "0      4096   10.1.0.3:8080   10.1.0.1:5$i"; i=$((i+1)); done
echo "0      0      10.1.0.3:8080   10.1.0.1:6000"
if [ "$(cat "$state/mode")" = drain ] && [ "$left" -gt 0 ]; then echo $((left-1)) > "$state/busy"; fi
"""


@unittest.skipUnless(shutil.which("ansible-playbook"), "the pinned Ansible toolchain is not installed")
class ReleaseResponseWait(unittest.TestCase):
    def play(self, mode, busy):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "bin").mkdir()
            incus = root / "bin/incus"
            incus.write_text(INCUS)
            incus.chmod(0o755)
            (root / "busy").write_text(str(busy))
            (root / "mode").write_text(mode)
            (root / "intent.json").write_text(json.dumps({"container": "gateway-aaaaaaaaaaaa",
                                                          "ssh_execution": {"target": {}, "identity_file": "/dev/null"}}))
            (root / "inventory").write_text("[release_node]\nlocalhost ansible_connection=local\n")
            result = subprocess.run(
                ["ansible-playbook", "-i", root / "inventory", PLAYBOOK, "--start-at-task", "Read established web connections that still hold queued bytes",
                 "-e", "ansible_become=false", "-e", "wait_retries=4", "-e", "wait_delay=0",
                 "-e", "ansible_python_interpreter=" + shutil.which("python3")],
                env={**os.environ, "PATH": f"{root / 'bin'}:{os.environ['PATH']}", "ORBIT_RUN": str(root), "FIXTURE_STATE": str(root),
                     "ANSIBLE_CONFIG": str(ROOT / "swamp/ansible/ansible.cfg"), "ANSIBLE_FORCE_COLOR": "0"},
                capture_output=True, text=True, timeout=120, check=False)
            calls = (root / "calls").read_text().splitlines()
            return result, calls

    def test_retire_waits_until_the_send_queues_drain(self):
        result, calls = self.play("drain", 2)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(calls), 3)
        self.assertTrue(all("exec gateway-aaaaaaaaaaaa -- ss -tnH state established" in call for call in calls))
        self.assertNotIn("gateway.release_response_wait_timeout", result.stdout)

    def test_a_reached_bound_is_logged_and_never_fails_retire(self):
        result, calls = self.play("stuck", 1)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(calls), 5)
        self.assertIn("gateway.release_response_wait_timeout: gateway-aaaaaaaaaaaa", result.stdout)


if __name__ == "__main__":
    unittest.main()
