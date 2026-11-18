"""Run the response wait playbook against a stubbed former live: it waits for drained send queues across closing sockets,
treats a failed probe as busy unless the container is gone, records the outcome and logs a reached bound."""
import json
import os
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLAYBOOK = ROOT / "swamp/ansible/release-response-wait.yml"
# Each call prints the listener, a TIME-WAIT socket and the closing connections left in the counter file, then one fewer.
INCUS = """#!/bin/sh
state="$FIXTURE_STATE"
echo "$*" >> "$state/calls"
case "$(cat "$state/mode")" in
  error) echo "Error: websocket: close 1006" >&2; exit 1 ;;
  gone) echo "Error: Instance is not running" >&2; exit 1 ;;
esac
left=$(cat "$state/busy")
echo "LISTEN     0      4096   10.1.0.3:8080   0.0.0.0:*"
echo "TIME-WAIT  0      0      10.1.0.3:8080   10.1.0.1:6001"
i=0
while [ "$i" -lt "$left" ]; do echo "FIN-WAIT-1 0      4096   10.1.0.3:8080   10.1.0.1:5$i"; i=$((i+1)); done
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
            (root / "intent.json").write_text(json.dumps({"container": "gateway-aaaaaaaaaaaa", "operation": 42,
                                                          "ssh_execution": {"target": {}, "identity_file": "/dev/null"}}))
            (root / "inventory").write_text("[release_node]\nlocalhost ansible_connection=local\n")
            result = subprocess.run(
                ["ansible-playbook", "-i", root / "inventory", PLAYBOOK, "--start-at-task", "Read web sockets that still hold queued bytes",
                 "-e", "ansible_become=false", "-e", "wait_retries=4", "-e", "wait_delay=0", "-e", f"record_dir={root / 'records'}",
                 "-e", "ansible_python_interpreter=" + shutil.which("python3")],
                env={**os.environ, "PATH": f"{root / 'bin'}:{os.environ['PATH']}", "ORBIT_RUN": str(root), "FIXTURE_STATE": str(root),
                     "ANSIBLE_CONFIG": str(ROOT / "swamp/ansible/ansible.cfg"), "ANSIBLE_FORCE_COLOR": "0"},
                capture_output=True, text=True, timeout=120, check=False)
            calls = (root / "calls").read_text().splitlines()
            record = root / "records/gateway-aaaaaaaaaaaa"
            return result, calls, record.read_text() if record.exists() else None

    def test_a_slow_reader_after_the_server_closed_is_waited_for(self):
        result, calls, record = self.play("drain", 2)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(calls), 3)
        self.assertTrue(all("exec gateway-aaaaaaaaaaaa -- ss -tanH" in call for call in calls))
        self.assertNotIn("gateway.release_response_wait_timeout", result.stdout)
        self.assertEqual(record, "42 drained\n")

    def test_a_reached_bound_is_logged_recorded_and_never_fails_retire(self):
        result, calls, record = self.play("stuck", 1)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(calls), 5)
        self.assertIn("gateway.release_response_wait_timeout: gateway-aaaaaaaaaaaa", result.stdout)
        self.assertEqual(record, "42 timeout\n")

    def test_a_failed_probe_is_not_drained_unless_the_container_is_gone(self):
        result, calls, record = self.play("error", 0)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(calls), 5)
        self.assertEqual(record, "42 timeout\n")
        result, calls, record = self.play("gone", 0)
        self.assertEqual(len(calls), 1)
        self.assertEqual(record, "42 drained\n")


if __name__ == "__main__":
    unittest.main()
