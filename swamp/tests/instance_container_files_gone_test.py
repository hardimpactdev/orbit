"""A container that a removal deletes while a role converge walks the Node's containers (instance_container_files.py): confirmed gone,
it is skipped as already removed; a container that still exists keeps its failure."""

import contextlib
import importlib.util
import io
import json
import subprocess
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

SOURCE = Path(__file__).resolve().parents[1] / "ansible/instance_container_files.py"
SPEC = importlib.util.spec_from_file_location("instance_container_files", SOURCE)
FILES = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(FILES)

PROJECT = "orbit-instances"


class Incus:
    """Answers the Incus calls of the passes from a set of containers. `delete_at` deletes a container at its first per-container call,
    after the pass listed it, as a destroy admitted beside the converge does; `stop_at` stops it there, as the destroy's stop or an idle
    sleep does: it still exists and refuses `incus exec`. `broken` containers exist but refuse every write."""

    def __init__(self, names, delete_at=(), broken=(), stop_at=()):
        self.names, self.delete_at, self.broken, self.known = set(names), set(delete_at), set(broken), set(names)
        self.stop_at, self.stopped = set(stop_at), set()

    def missing(self, name):
        return subprocess.CompletedProcess([], 1, "", f'Error: Failed to fetch instance "{name}" in project "{PROJECT}": Instance not found\n')

    def __call__(self, argv, **_):
        assert argv[0] == "incus", argv
        args = argv[1:]
        if args[0] == "query" and args[1].startswith("/1.0/instances?"):
            rows = [{"project": PROJECT, "name": name, "status": "Running"} for name in sorted(self.names)]
            return subprocess.CompletedProcess(argv, 0, json.dumps(rows), "")
        if args[0] == "query" and args[1].startswith("/1.0/instances/"):
            name = args[1].split("/")[3].split("?")[0]
            if name not in self.names:
                return self.missing(name)
            if "/files?" in args[1]:
                return subprocess.CompletedProcess(argv, 1, "", "Error: file does not exist")
            return subprocess.CompletedProcess(argv, 0, json.dumps({"status": "Stopped" if name in self.stopped else "Running"}), "")
        target = next(arg for arg in args[1:] if arg.split("/")[0] in self.known)
        name = target.split("/")[0]
        if name in self.delete_at:
            self.delete_at.discard(name)
            self.names.discard(name)
        if name in self.stop_at:
            self.stop_at.discard(name)
            self.stopped.add(name)
        if name not in self.names:
            return self.missing(name)
        if name in self.stopped and args[0] == "exec":
            return subprocess.CompletedProcess(argv, 1, "", "Error: Instance is not running\n")
        if name in self.broken and args[0] in ("file", "exec") and (args[1] in ("push", "create") or args[0] == "exec"):
            return subprocess.CompletedProcess(argv, 1, "", "Error: Failed to write: input/output error")
        if args[0] == "file" and args[1] == "pull":
            return subprocess.CompletedProcess(argv, 1, "", "Error: file does not exist")
        if args[0] == "exec" and "is-active" in args:
            return subprocess.CompletedProcess(argv, 3, "inactive\n", "")
        return subprocess.CompletedProcess(argv, 0, "no\n" if "NeedDaemonReload" in args else "", "")


class InstanceContainerFilesGoneTest(unittest.TestCase):
    def setUp(self):
        temp = tempfile.TemporaryDirectory(prefix="orbit-container-files-")
        self.addCleanup(temp.cleanup)
        self.root = Path(temp.name)
        (self.root / "root.pem").write_text("-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n")
        hashed = patch.object(FILES, "subject_hash", return_value="0a1b2c3d")
        hashed.start()
        self.addCleanup(hashed.stop)

    def run_pass(self, incus, call):
        out = io.StringIO()
        with patch.object(FILES.subprocess, "run", incus), contextlib.redirect_stdout(out):
            code = call()
        return code, json.loads(out.getvalue())

    def shared(self, incus):
        trust = {"source": str(self.root / "root.pem"), "directory": "/usr/local/share/ca-certificates",
                 "path": "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt"}
        files = [{"path": "/etc/profile.d/orbit.sh", "content": "export ORBIT=1\n", "mode": "0644"}]
        return self.run_pass(incus, lambda: FILES.converge([PROJECT], files, trust, {}, str(self.root / "lib"), str(self.root / "state")))

    def t3(self, incus):
        entries = [{"project": PROJECT, "container": name, "files": [{"path": "/etc/systemd/system/t3.service.d/orbit.conf",
                                                                     "content": "[Service]\n", "mode": "0644"}]} for name in ("a", "b", "c")]
        return self.run_pass(incus, lambda: FILES.t3_files(entries, str(self.root / "t3-restart.json")))

    def test_the_shared_files_pass_skips_a_container_deleted_after_the_listing(self):
        code, summary = self.shared(Incus(["a", "b", "c"], delete_at=["b"]))
        self.assertEqual(code, 0, summary)
        self.assertEqual(summary["gone"], [f"{PROJECT}/b"])
        self.assertEqual(summary["failed"], [])
        self.assertIn(f"{PROJECT}/c/etc/profile.d/orbit.sh", summary["written"])
        self.assertFalse(any(row["container"] == f"{PROJECT}/b" for row in summary["browser_trust"]))

    def test_the_shared_files_pass_still_fails_a_container_that_exists(self):
        code, summary = self.shared(Incus(["a", "b", "c"], delete_at=["a"], broken=["b"]))
        self.assertEqual((code, summary["error_code"]), (1, "instance.host_unavailable"))
        self.assertEqual(summary["gone"], [f"{PROJECT}/a"])
        self.assertEqual([line.split(":")[0] for line in summary["failed"]], [f"{PROJECT}/b"])

    def test_the_t3_pass_skips_a_container_deleted_after_the_listing(self):
        code, summary = self.t3(Incus(["a", "b", "c"], delete_at=["b"]))
        self.assertEqual(code, 0, summary)
        self.assertEqual((summary["gone"], summary["failed"]), ([f"{PROJECT}/b"], []))
        self.assertEqual(sorted(summary["written"]), [f"{PROJECT}/{name}/etc/systemd/system/t3.service.d/orbit.conf" for name in ("a", "c")])

    def test_the_t3_pass_still_fails_a_container_that_exists(self):
        code, summary = self.t3(Incus(["a", "b", "c"], broken=["c"]))
        self.assertEqual((code, summary["error_code"]), (1, "instance.host_unavailable"))
        self.assertEqual(([line.split(":")[0] for line in summary["failed"]], summary["gone"]), ([f"{PROJECT}/c"], []))

    def test_the_t3_restart_drops_a_deleted_container_and_keeps_a_failed_one(self):
        state = self.root / "t3-restart.json"
        state.write_text(json.dumps([{"project": PROJECT, "name": name} for name in ("a", "b")]) + "\n")
        targets = [{"project": PROJECT, "name": name} for name in ("a", "b")]
        code, summary = self.run_pass(Incus(["a", "b"], delete_at=["a"], broken=["b"]),
                                      lambda: FILES.t3_restart(targets, [], str(state)))
        self.assertEqual(code, 1)
        self.assertEqual(summary["gone"], [{"project": PROJECT, "name": "a"}])
        self.assertEqual([line.split(":")[0] for line in summary["failed"]], [f"{PROJECT}/b"])
        self.assertEqual(json.loads(state.read_text()), [{"project": PROJECT, "name": "b"}])

    def test_a_deleted_container_whose_runs_cannot_be_read_is_not_deferred(self):
        state = self.root / "t3-restart.json"
        targets = [{"project": PROJECT, "name": "a"}]
        programs = [{"project": PROJECT, "container": "a", "program": "/opt/t3/bin/t3"}]
        code, summary = self.run_pass(Incus(["a"], delete_at=["a"]), lambda: FILES.t3_restart(targets, programs, str(state)))
        self.assertEqual(code, 0, summary)
        self.assertEqual((summary["gone"], summary["deferred"]), ([{"project": PROJECT, "name": "a"}], []))
        self.assertFalse(state.exists())

    def test_the_shared_files_pass_gives_a_container_stopped_after_the_listing_the_stopped_work(self):
        code, summary = self.shared(Incus(["a", "b", "c"], stop_at=["b"]))
        self.assertEqual(code, 0, summary)
        self.assertEqual((summary["failed"], summary["gone"]), ([], []))
        self.assertIn(f"{PROJECT}/b/etc/profile.d/orbit.sh", summary["written"])
        self.assertIn(f"{PROJECT}/b", summary["cache_dropped"])
        self.assertNotIn(f"{PROJECT}/b", summary["ca_bundle"])
        self.assertIn({"container": f"{PROJECT}/b", "reason": "not running"}, summary["browser_trust"])

    def test_the_shared_files_pass_still_fails_a_running_container_whose_exec_fails(self):
        code, summary = self.shared(Incus(["a", "b"], broken=["b"]))
        self.assertEqual((code, [line.split(":")[0] for line in summary["failed"]]), (1, [f"{PROJECT}/b"]))

    def test_the_t3_pass_gives_a_container_stopped_after_the_listing_the_stopped_work(self):
        code, summary = self.t3(Incus(["a", "b", "c"], stop_at=["b"]))
        self.assertEqual(code, 0, summary)
        self.assertEqual((summary["failed"], summary["gone"]), ([], []))
        self.assertIn(f"{PROJECT}/b/etc/systemd/system/t3.service.d/orbit.conf", summary["written"])
        self.assertNotIn({"project": PROJECT, "name": "b"}, summary["reload"])
        self.assertIn({"project": PROJECT, "name": "a"}, summary["reload"])

    def test_the_t3_restart_keeps_a_stopped_container_on_the_list_without_failing_or_deferring(self):
        state = self.root / "t3-restart.json"
        targets = [{"project": PROJECT, "name": name} for name in ("a", "b")]
        programs = [{"project": PROJECT, "container": "b", "program": "/opt/t3/bin/t3"}]
        code, summary = self.run_pass(Incus(["a", "b"], stop_at=["a", "b"]), lambda: FILES.t3_restart(targets, programs, str(state)))
        self.assertEqual(code, 0, summary)
        self.assertEqual((summary["stopped"], summary["deferred"], summary["failed"]), (targets, [], []))
        self.assertEqual(json.loads(state.read_text()), targets)

    def test_a_confirmed_state_is_the_status_incus_reports(self):
        with patch.object(FILES.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, '{"status": "Stopped"}', "")):
            self.assertEqual(FILES.confirmed_state(PROJECT, "a"), "Stopped")
        with patch.object(FILES.subprocess, "run", return_value=subprocess.CompletedProcess([], 0, "not json", "")):
            self.assertIsNone(FILES.confirmed_state(PROJECT, "a"))

    def test_only_incus_own_not_found_answer_counts_as_gone(self):
        answers = {"Error: Instance not found": True,
                   f'Error: Failed to fetch instance "a" in project "{PROJECT}": Instance not found': True,
                   f'Error: Failed to fetch instance "b" in project "{PROJECT}": Instance not found': False,
                   "Error: Get \"http://unix.socket/1.0\": dial unix: connection refused": False}
        for stderr, expected in answers.items():
            with patch.object(FILES.subprocess, "run", return_value=subprocess.CompletedProcess([], 1, "", stderr + "\n")):
                self.assertEqual(FILES.gone(PROJECT, "a"), expected, stderr)


if __name__ == "__main__":
    unittest.main()
