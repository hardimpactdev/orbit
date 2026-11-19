"""Run the response wait playbook against a stubbed former live. It waits for drained send queues across closing sockets,
counts a failed probe as busy unless Incus reports the container stopped, keeps the first start of the bound for every later
path and records the outcome."""
import json
import os
import shutil
import subprocess
import tempfile
import time
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
PLAYBOOK = ROOT / "swamp/ansible/release-response-wait.yml"
# `incus list` reports the container status; `incus exec … ss` prints the listener, a TIME-WAIT socket and the closing
# connections left in the counter file, then one fewer.
INCUS = """#!/bin/sh
state="$FIXTURE_STATE"
case " $* " in
  *" list "*)
    cat "$state/records/gateway-aaaaaaaaaaaa" > "$state/seen" 2>/dev/null
    status=$(cat "$state/status")
    if [ "$status" = error ]; then echo "incus: cannot reach the daemon" >&2; exit 1; fi
    if [ "$status" = absent ]; then echo '[]'; else echo "[{\\"name\\": \\"gateway-aaaaaaaaaaaa\\", \\"status\\": \\"$status\\"}]"; fi
    exit 0 ;;
esac
echo "$*" >> "$state/calls"
if [ "$(cat "$state/mode")" = error ]; then echo "exec: ss: not found" >&2; exit 1; fi
left=$(cat "$state/busy")
echo "LISTEN     0      4096   10.1.0.3:8080   0.0.0.0:*"
echo "TIME-WAIT  0      0      10.1.0.3:8080   10.1.0.1:6001"
i=0
while [ "$i" -lt "$left" ]; do echo "FIN-WAIT-1 0      4096   10.1.0.3:8080   10.1.0.1:5$i"; i=$((i+1)); done
if [ "$(cat "$state/mode")" = drain ] && [ "$left" -gt 0 ]; then echo $((left-1)) > "$state/busy"; fi
"""


@unittest.skipUnless(shutil.which("ansible-playbook"), "the pinned Ansible toolchain is not installed")
class ReleaseResponseWait(unittest.TestCase):
    def play(self, mode, busy, status="Running", record=None, seconds=10):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "bin").mkdir()
            incus = root / "bin/incus"
            incus.write_text(INCUS)
            incus.chmod(0o755)
            (root / "busy").write_text(str(busy))
            (root / "mode").write_text(mode)
            (root / "status").write_text(status)
            (root / "calls").touch()
            records = root / "records"
            if record is not None:
                records.mkdir()
                (records / "gateway-aaaaaaaaaaaa").write_text(record)
            (root / "intent.json").write_text(json.dumps({"container": "gateway-aaaaaaaaaaaa", "operation": 42,
                                                          "ssh_execution": {"target": {}, "identity_file": "/dev/null"}}))
            (root / "inventory").write_text("[release_node]\nlocalhost ansible_connection=local\n")
            result = subprocess.run(
                ["ansible-playbook", "-i", root / "inventory", PLAYBOOK, "--start-at-task", "Read this Operation's wait record",
                 "-e", "ansible_become=false", "-e", f"wait_seconds={seconds}", "-e", "wait_delay=1", "-e", f"record_dir={records}",
                 "-e", "ansible_python_interpreter=" + shutil.which("python3")],
                env={**os.environ, "PATH": f"{root / 'bin'}:{os.environ['PATH']}", "ORBIT_RUN": str(root), "FIXTURE_STATE": str(root),
                     "ANSIBLE_CONFIG": str(ROOT / "swamp/ansible/ansible.cfg"), "ANSIBLE_FORCE_COLOR": "0"},
                capture_output=True, text=True, timeout=180, check=False)
            calls = (root / "calls").read_text().splitlines()
            saved = records / "gateway-aaaaaaaaaaaa"
            self.seen = (root / "seen").read_text() if (root / "seen").exists() else None
            return result, calls, saved.read_text() if saved.exists() else None

    def test_a_slow_reader_after_the_server_closed_is_waited_for(self):
        result, calls, record = self.play("drain", 2, seconds=60)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(calls), 3)
        self.assertTrue(all("exec gateway-aaaaaaaaaaaa -- ss -tanH" in call for call in calls))
        self.assertNotIn("gateway.release_response_wait_timeout", result.stdout)
        self.assertEqual(record, "42 drained\n")

    def test_a_reached_bound_is_logged_recorded_and_never_fails_retire(self):
        result, calls, record = self.play("stuck", 1)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertGreaterEqual(len(calls), 2)
        self.assertIn("gateway.release_response_wait_timeout: gateway-aaaaaaaaaaaa", result.stdout)
        self.assertEqual(record, "42 timeout\n")

    def test_a_failed_probe_stays_busy_until_the_bound_unless_incus_reports_the_container_stopped(self):
        for status in ("Running", "error"):
            result, calls, record = self.play("error", 0, status=status)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            self.assertGreaterEqual(len(calls), 2)
            self.assertEqual(record, "42 timeout\n")
            # The start was saved before the first Incus call.
            self.assertRegex(self.seen, r"^42 waiting \d+\n?$")
        for status in ("Stopped", "absent"):
            result, calls, record = self.play("error", 0, status=status)
            self.assertEqual(calls, [])
            self.assertEqual(record, "42 drained\n")

    def test_the_first_start_is_kept_so_a_later_path_never_restarts_the_bound(self):
        result, calls, record = self.play("stuck", 1, record=f"42 waiting {int(time.time()) - 1000}\n", seconds=600)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        self.assertEqual(len(calls), 1)
        self.assertEqual(record, "42 timeout\n")
        result, calls, record = self.play("drain", 3, record="42 drained\n")
        self.assertEqual(calls, [])
        self.assertEqual(record, "42 drained\n")


if __name__ == "__main__":
    unittest.main()
