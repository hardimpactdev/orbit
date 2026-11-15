"""The power helper of development containers (docs/reference/prepared-restore.md) against real processes.

Each test starts real child processes and runs the helper's capture and restore engine on them. A private /proc view links each
child's stat, maps and pagemap to the real files and states its cgroup, and a private cgroup tree lists the children, so the
container's identity, cgroup membership and boot id can change under the helper. Incus is a fake. The kernel wrapper counts every
memory syscall per PID and passes it to the real kernel; where this host refuses process_madvise (it needs CAP_SYS_NICE in the
initial user namespace) the tests state what they expect from both answers.
"""

import importlib.util
import io
import json
import os
import re
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import unittest

HELPER = os.path.join(os.path.dirname(os.path.abspath(__file__)), "..", "ansible", "files", "instance-container-power.py")
spec = importlib.util.spec_from_file_location("power", HELPER)
power = importlib.util.module_from_spec(spec)
spec.loader.exec_module(power)

PROJECT, NAME, ID = "orbit-instances", "instance-7", 7
RELATIVE = f"lxc.payload.{PROJECT}_{NAME}"
# A child that reserves 64 MiB, touches the first 4 MiB of it and prints its PID line once it is ready.
CHILD = ("import mmap, sys\n"
         "m = mmap.mmap(-1, 64 << 20)\n"
         "m[0:4 << 20] = b'x' * (4 << 20)\n"
         "print('ready', flush=True)\n"
         "sys.stdin.read()\n")


class CountingKernel(power.Kernel):
    def __init__(self, short_read_pid=None):
        super().__init__()
        self.calls = []
        self.short_read_pid = short_read_pid

    def process_madvise(self, pidfd, iovecs, count):
        self.calls.append(("madvise", self.pid_of(pidfd)))
        return super().process_madvise(pidfd, iovecs, count)

    def process_vm_readv(self, pid, local, remote, count):
        self.calls.append(("readv", pid))
        if pid == self.short_read_pid:
            return count - 1, 14
        return super().process_vm_readv(pid, local, remote, count)

    def pid_of(self, pidfd):
        with open(f"/proc/self/fdinfo/{pidfd}") as handle:
            return int(re.search(r"^Pid:\s*(-?\d+)", handle.read(), re.M).group(1))

    def pids(self):
        return {pid for _, pid in self.calls}


class FakeIncus:
    def __init__(self, init_pid):
        self.status, self.incarnation, self.init_pid, self.actions = "running", "uuid-1", init_pid, []

    def instance(self, project, name):
        if self.status == "missing":
            return None
        return {"status": self.status, "incarnation": self.incarnation, "init_pid": self.init_pid}

    def change(self, project, name, action, seconds):
        self.actions.append(action)
        self.status = "frozen" if action == "freeze" else "running"
        return True


class Fixture(unittest.TestCase):
    def setUp(self):
        self.root = tempfile.mkdtemp()
        self.addCleanup(shutil.rmtree, self.root)
        self.children = [self.child() for _ in range(3)]
        self.proc = os.path.join(self.root, "proc")
        self.cgroups = os.path.join(self.root, "cgroup")
        os.makedirs(os.path.join(self.proc, "sys/kernel/random"))
        self.boot("boot-1")
        self.container = os.path.join(self.cgroups, RELATIVE)
        os.makedirs(os.path.join(self.container, "init.scope"))
        os.makedirs(os.path.join(self.container, "app", "worker"))
        for path, pids in (("init.scope", [0]), ("app", [1]), ("app/worker", [2, 1])):
            self.write(os.path.join(self.container, path, "cgroup.procs"), "".join(f"{self.children[i].pid}\n" for i in pids))
        self.write(os.path.join(self.container, "cgroup.procs"), "")
        for path in ("", "init.scope", "app", "app/worker"):
            self.write(os.path.join(self.container, path, "memory.swap.max"), "0\n")
        self.write(os.path.join(self.container, "memory.current"), "1000\n")
        self.write(os.path.join(self.container, "memory.reclaim"), "")
        self.write(os.path.join(self.container, "memory.max"), "max\n")
        for child, path in zip(self.children, ("init.scope", "app/worker", "app/worker")):
            self.view(child.pid, f"{RELATIVE}/{path}")
        self.state = os.path.join(self.root, "state")
        self.incus = FakeIncus(self.children[0].pid)
        self.kernel = CountingKernel()
        self.host = power.Host(state=self.state, requests=os.path.join(self.root, "shm"), cgroups=self.cgroups, proc=self.proc,
                               incus=self.incus, kernel=self.kernel, helper=HELPER)
        self.supported(True)

    def child(self):
        child = subprocess.Popen([sys.executable, "-I", "-c", CHILD], stdin=subprocess.PIPE, stdout=subprocess.PIPE)
        self.assertEqual(child.stdout.readline(), b"ready\n")
        self.addCleanup(child.stdout.close)
        self.addCleanup(child.stdin.close)
        self.addCleanup(child.wait)
        self.addCleanup(child.kill)
        return child

    def write(self, path, text):
        with open(path, "w") as handle:
            handle.write(text)

    def boot(self, value):
        self.write(os.path.join(self.proc, "sys/kernel/random/boot_id"), value + "\n")

    def view(self, pid, cgroup):
        folder = os.path.join(self.proc, str(pid))
        os.makedirs(folder, exist_ok=True)
        for name in ("stat", "maps", "pagemap"):
            if not os.path.lexists(os.path.join(folder, name)):
                os.symlink(f"/proc/{pid}/{name}", os.path.join(folder, name))
        self.write(os.path.join(folder, "cgroup"), f"0::/{cgroup}\n")

    def supported(self, value):
        os.makedirs(self.state, exist_ok=True)
        self.write(os.path.join(self.state, "capabilities.json"), json.dumps(
            {"supported": value, "helper_sha256": power.helper_digest(self.host), "plan_schema_version": 1}))

    def intent(self, operation):
        return {"schema_version": 1, "instance_id": ID, "operation_id": operation, "incus_project": PROJECT,
                "incus_name": NAME, "web": {"address": "10.0.0.2", "port": 8080}}

    def prepare(self, operation=11):
        result = power.prepare(self.host, ID, operation, self.intent(operation))
        self.assertEqual(result["outcome"], "frozen")
        return result

    def restore(self, operation=12, intent=True):
        return power.restore(self.host, ID, operation, self.intent(operation) if intent else None)

    def plan(self):
        return os.path.join(self.state, "instances", str(ID), "plan")

    def manifest(self):
        with open(os.path.join(self.plan(), "manifest.json")) as handle:
            return json.load(handle)

    def rewrite(self, manifest=None, ranges=None):
        if manifest is not None:
            self.write(os.path.join(self.plan(), "manifest.json"), json.dumps(manifest))
        if ranges is not None:
            with open(os.path.join(self.plan(), "ranges.bin"), "wb") as handle:
                handle.write(ranges)


class CaptureTest(Fixture):
    def test_every_process_of_the_subtree_counts_once_with_its_resident_pages_only(self):
        result = self.prepare()
        prepared = result["prepared_restore"]
        self.assertEqual(prepared["status"], "ready")
        manifest, ranges = power.load_plan(self.host, ID)
        self.assertEqual(sorted(p["host_pid"] for p in manifest["processes"]), sorted(c.pid for c in self.children))
        self.assertEqual(prepared["processes"], 3)
        page = manifest["page_size"]
        for child, process in zip(sorted(self.children, key=lambda c: c.pid), manifest["processes"]):
            with open(f"/proc/{child.pid}/maps") as handle:
                maps = handle.read().splitlines()
            reserved = next(line for line in maps if int(line.split()[0].split("-")[1], 16) -
                            int(line.split()[0].split("-")[0], 16) == 64 << 20)
            low, high = (int(v, 16) for v in reserved.split()[0].split("-"))
            inside = sum(length for _, _, start, length in ranges[process["first_range"]:][:process["range_count"]]
                         if low <= start < high)
            # The touched 4 MiB are resident; the untouched 60 MiB of the same mapping are not recorded.
            self.assertGreaterEqual(inside, 4 << 20)
            self.assertLess(inside, 8 << 20)
            special = [line for line in maps if line.split()[-1] in power.SPECIAL_MAPPINGS]
            for line in special:
                low, high = (int(v, 16) for v in line.split()[0].split("-"))
                self.assertFalse(any(low <= r[2] < high for r in ranges))
            self.assertEqual(process["resident_pages"] * page,
                             sum(r[3] for r in ranges[process["first_range"]:][:process["range_count"]]))
        self.assertEqual(self.incus.actions, ["freeze"])
        self.assertEqual(self.kernel.calls, [], "capture makes no memory syscall")

    def test_a_process_outside_the_container_cgroup_is_left_out(self):
        self.view(self.children[2].pid, "other")
        self.prepare()
        manifest, _ = power.load_plan(self.host, ID)
        self.assertNotIn(self.children[2].pid, [p["host_pid"] for p in manifest["processes"]])

    def test_files_are_private_and_published_whole(self):
        self.prepare()
        base = os.path.join(self.state, "instances", str(ID))
        self.assertEqual(os.stat(base).st_mode & 0o777, 0o700)
        for name in ("manifest.json", "ranges.bin"):
            self.assertEqual(os.stat(os.path.join(self.plan(), name)).st_mode & 0o777, 0o600)
        self.assertEqual(sorted(os.listdir(base)), ["limits.json", "plan", "sleep.json", "target.json"])

    def test_missing_capability_records_no_plan(self):
        os.unlink(os.path.join(self.state, "capabilities.json"))
        result = self.prepare()
        self.assertEqual(result["prepared_restore"], {"version": 1, "status": "unsupported", "reason": "unsupported"})
        self.assertFalse(os.path.exists(self.plan()))

    def test_a_nested_fleet_instance_records_no_plan(self):
        intent = dict(self.intent(11), incus_project="orbit-instances-nested")
        relative = f"lxc.payload.orbit-instances-nested_{NAME}"
        os.rename(self.container, os.path.join(self.cgroups, relative))
        result = power.prepare(self.host, ID, 11, intent)
        self.assertEqual(result["prepared_restore"]["reason"], "placement_unproven")
        self.assertFalse(os.path.exists(self.plan()))

    def test_a_bound_abandons_the_hint_and_keeps_the_sleep(self):
        self.write(os.path.join(self.container, "memory.max"), str(1 << 20))
        result = self.prepare()
        self.assertEqual(result["prepared_restore"], {"version": 1, "status": "skipped", "reason": "bound_residency"})
        self.assertFalse(os.path.exists(self.plan()))

    def test_a_repeated_sleep_of_a_frozen_container_returns_the_first_result(self):
        first = self.prepare()
        again = power.prepare(self.host, ID, 13, self.intent(13))
        self.assertEqual(again["outcome"], "already_frozen")
        self.assertEqual(again["prepared_restore"], first["prepared_restore"])
        self.assertEqual(self.incus.actions, ["freeze"])


class ResidualTest(Fixture):
    def stat(self, anon, file):
        self.write(os.path.join(self.container, "memory.stat"), f"anon {anon}\nfile {file}\nanon_thp 0\n")

    def test_each_call_reads_the_counters_once_and_a_repeated_sleep_returns_the_last_read(self):
        self.prepare(11)
        self.stat(8192, 4096)
        first = power.residual(self.host, ID, 11)
        self.assertEqual(first["outcome"], "recorded")
        self.assertEqual((first["prepared_restore"]["residual_anon_bytes"], first["prepared_restore"]["residual_file_bytes"]),
                         (8192, 4096))
        self.stat(0, 0)
        last = power.residual(self.host, ID, 11)
        self.assertEqual(last["prepared_restore"]["status"], "ready")
        self.assertEqual((last["prepared_restore"]["residual_anon_bytes"], last["prepared_restore"]["residual_file_bytes"]), (0, 0))
        again = power.prepare(self.host, ID, 13, self.intent(13))
        self.assertEqual(again["prepared_restore"], last["prepared_restore"])
        self.assertEqual(self.incus.actions, ["freeze"])

    def test_another_sleep_or_a_container_that_is_not_frozen_changes_nothing(self):
        self.prepare(11)
        self.stat(8192, 0)
        path = os.path.join(self.state, "instances", str(ID), "sleep.json")
        before = open(path).read()
        self.assertEqual(power.residual(self.host, ID, 12)["outcome"], "not_recorded")
        self.incus.status = "running"
        self.assertEqual(power.residual(self.host, ID, 11)["outcome"], "not_recorded")
        self.incus.status, self.incus.incarnation = "frozen", "uuid-2"
        self.assertEqual(power.residual(self.host, ID, 11)["state_after"], "missing")
        self.assertEqual(open(path).read(), before)
        self.assertEqual(self.kernel.calls, [])


class SwapTest(Fixture):
    def test_only_zero_limits_open_and_only_those_close_and_a_vanished_cgroup_does_not_fail(self):
        self.write(os.path.join(self.container, "app", "memory.swap.max"), "33554432\n")
        result = self.prepare()
        self.assertEqual(result["swap_subtree"], [f"{RELATIVE}/app/worker", f"{RELATIVE}/init.scope"])
        for path, value in (("", "max"), ("init.scope", "max"), ("app", "33554432"), ("app/worker", "max")):
            self.assertEqual(power.read_value(os.path.join(self.container, path, "memory.swap.max")), value)
        shutil.rmtree(os.path.join(self.container, "init.scope"))
        restored = self.restore()
        self.assertEqual(restored["outcome"], "resumed")
        self.assertEqual(restored["swap_subtree"], [f"{RELATIVE}/app/worker"])
        for path, value in (("", "0"), ("app", "33554432"), ("app/worker", "0")):
            self.assertEqual(power.read_value(os.path.join(self.container, path, "memory.swap.max")), value)
        self.assertFalse(os.path.exists(os.path.join(self.state, "instances", str(ID), "limits.json")))

    def test_an_empty_subtree_opens_only_the_container(self):
        for path in ("init.scope", "app/worker", "app"):
            self.write(os.path.join(self.container, path, "memory.swap.max"), "max\n")
        self.assertEqual(self.prepare()["swap_subtree"], [])
        self.assertEqual(power.read_value(os.path.join(self.container, "memory.swap.max")), "max")


class RestoreTest(Fixture):
    def test_a_valid_plan_restores_every_process_while_frozen_then_unfreezes_once(self):
        self.prepare()
        result = self.restore()
        self.assertEqual(result["outcome"], "resumed")
        self.assertEqual(self.kernel.pids(), {c.pid for c in self.children})
        prepared = result["prepared_restore"]
        # process_madvise needs CAP_SYS_NICE in the initial user namespace; without it advice fails and the restore is partial.
        expected = "complete" if all(self.madvise_works()) else "partial"
        self.assertEqual(prepared["status"], expected)
        self.assertGreaterEqual(prepared["pages_read"], 3 * (4 << 20) // os.sysconf("SC_PAGESIZE"))
        self.assertEqual(self.incus.actions, ["freeze", "unfreeze"])
        self.assertFalse(os.path.exists(self.plan()), "the plan is consumed")

    def madvise_works(self):
        return [power.probe(self.host)["process_madvise"]]

    def test_a_changed_start_time_exited_process_or_other_cgroup_gets_no_memory_call(self):
        self.prepare()
        manifest = self.manifest()
        changed, exited, moved = sorted(self.children, key=lambda c: c.pid)
        index = [p["host_pid"] for p in manifest["processes"]].index(changed.pid)
        manifest["processes"][index]["start_ticks"] += 1
        self.rewrite(manifest)
        exited.kill()
        exited.wait()
        self.view(moved.pid, "elsewhere")
        result = self.restore()
        self.assertEqual(result["prepared_restore"]["status"], "partial")
        self.assertEqual(self.kernel.calls, [])
        self.assertEqual(self.incus.actions, ["freeze", "unfreeze"])

    def test_a_changed_boot_id_init_or_incarnation_makes_the_plan_stale_without_memory_calls(self):
        for change in ("boot", "init", "incarnation"):
            with self.subTest(change):
                self.incus.status = "running"
                self.incus.incarnation = "uuid-1"
                self.boot("boot-1")
                self.prepare()
                if change == "boot":
                    self.boot("boot-2")
                elif change == "init":
                    manifest = self.manifest()
                    manifest["init_start_ticks"] += 1
                    self.rewrite(manifest)
                else:
                    self.incus.incarnation = "uuid-2"
                result = self.restore(intent=change != "incarnation")
                if change == "incarnation":
                    self.assertEqual(result["outcome"], "missing")
                    self.assertEqual(self.incus.actions[-1], "freeze", "another incarnation is never unfrozen")
                    power.discard(self.host, ID, False)
                    self.incus.incarnation = "uuid-1"
                    self.incus.status = "running"
                else:
                    self.assertEqual(result["prepared_restore"], {"version": 1, "status": "skipped", "reason": "plan_stale"})
                    self.assertEqual(result["outcome"], "resumed")
                self.assertEqual(self.kernel.calls, [])

    def test_a_short_read_is_partial_and_never_retried(self):
        self.prepare()
        manifest, _ = power.load_plan(self.host, ID)
        victim = self.children[1].pid
        pages = next(p["resident_pages"] for p in manifest["processes"] if p["host_pid"] == victim)
        self.kernel.short_read_pid = victim
        result = self.restore()
        self.assertEqual(result["prepared_restore"]["status"], "partial")
        # Each short read abandons its batch and the worker goes on: one read per batch, never a retry.
        calls = [c for c in self.kernel.calls if c == ("readv", victim)]
        self.assertEqual(len(calls), -(-pages // self.kernel.iov_max))

    def test_a_process_that_exits_during_the_restore_gets_no_further_call(self):
        self.prepare()
        victim = sorted(self.children, key=lambda c: c.pid)[0]
        original = self.kernel.process_vm_readv

        def exit_after_first(pid, local, remote, count):
            result = original(pid, local, remote, count)
            if pid == victim.pid and victim.poll() is None:
                victim.kill()
                victim.wait()
            return result

        self.kernel.process_vm_readv = exit_after_first
        result = self.restore()
        self.assertEqual(result["prepared_restore"]["status"], "partial")
        # The first batch ran; the check before the next batch found the process gone, though it had more batches.
        self.assertGreater(4 << 20, self.kernel.iov_max * os.sysconf("SC_PAGESIZE"))
        self.assertEqual(self.kernel.calls.count(("readv", victim.pid)), 1)

    def test_a_passed_deadline_stops_all_work_and_says_timed_out(self):
        self.prepare()
        manifest, ranges = power.load_plan(self.host, ID)
        status, counts = power.restore_pages(self.host, manifest, ranges, time.monotonic() - 1)
        self.assertEqual(status, "timed_out")
        self.assertEqual(counts["pages_read"], 0)
        self.assertEqual([c for c in self.kernel.calls if c[0] == "readv"], [])

    def test_a_stalled_read_ends_at_the_deadline_with_less_work_done(self):
        self.prepare()
        manifest, ranges = power.load_plan(self.host, ID)
        original = self.kernel.process_vm_readv
        stall = threading.Event()

        def slow(pid, local, remote, count):
            stall.wait(0.05)
            return original(pid, local, remote, count)

        self.kernel.process_vm_readv = slow
        started = time.monotonic()
        status, counts = power.restore_pages(self.host, manifest, ranges, started + 0.2)
        self.assertEqual(status, "timed_out")
        self.assertLess(time.monotonic() - started, 0.5)
        self.assertLess(counts["pages_read"], sum(p["resident_pages"] for p in manifest["processes"]))

    def test_a_fresh_process_restores_from_the_plan_on_disk(self):
        # One process prepares, then replaces itself with a fresh interpreter (same PID, so still the children's parent, which
        # Yama's ptrace scope needs here); the new image knows only the plan on disk.
        output = subprocess.run([sys.executable, "-I", __file__, "--cold-reload"], capture_output=True, text=True, timeout=60)
        self.assertEqual(output.returncode, 0, output.stderr)
        result = json.loads(output.stdout)
        self.assertIn(result["status"], ("complete", "partial"))
        self.assertEqual(result["processes_read"], 3)
        self.assertGreaterEqual(result["pages"], 3 * (4 << 20) // os.sysconf("SC_PAGESIZE"))

    def test_running_stopped_and_missing_containers(self):
        self.prepare()
        self.incus.status = "running"
        result = self.restore()
        self.assertEqual((result["outcome"], result["prepared_restore"]["status"]), ("already_running", "not_applicable"))
        self.assertEqual(power.read_value(os.path.join(self.container, "memory.swap.max")), "0")
        self.incus.status = "stopped"
        self.assertEqual(self.restore()["outcome"], "stopped")
        self.incus.status = "missing"
        self.assertEqual(self.restore()["outcome"], "missing")
        self.assertEqual(self.kernel.calls, [])

    def test_the_fast_worker_finds_its_target_only_in_the_descriptor(self):
        self.prepare()
        self.assertEqual(self.restore(intent=False)["outcome"], "resumed")
        power.discard(self.host, ID, False)
        with self.assertRaises(power.Refused):
            self.restore(intent=False)


class PlanValidationTest(Fixture):
    def setUp(self):
        super().setUp()
        self.prepare()
        with open(os.path.join(self.plan(), "ranges.bin"), "rb") as handle:
            self.ranges = handle.read()

    def sealed(self, ranges, **changes):
        import hashlib
        manifest = dict(self.manifest(), ranges_sha256=hashlib.sha256(ranges).hexdigest(), ranges_file_bytes=len(ranges),
                        **changes)
        self.rewrite(manifest, ranges)

    def record(self, index, process, start, length):
        return power.RANGES_RECORD.pack(process, 0, start, length)

    def test_each_malformed_plan_fails_closed_and_the_wake_still_resumes(self):
        header = power.RANGES_HEADER.size
        size = power.RANGES_RECORD.size
        first = power.RANGES_RECORD.unpack_from(self.ranges, header)
        page = os.sysconf("SC_PAGESIZE")
        cases = {
            "truncated": lambda: self.sealed(self.ranges[:-1]),
            "trailing record": lambda: self.sealed(self.ranges + self.ranges[-size:]),
            "bad digest": lambda: self.rewrite(ranges=self.ranges[:-1] + bytes([self.ranges[-1] ^ 1])),
            "unknown version": lambda: self.sealed(self.ranges[:8] + (2).to_bytes(4, "little") + self.ranges[12:]),
            "unknown manifest schema": lambda: self.rewrite(dict(self.manifest(), schema_version=2)),
            "unknown manifest field": lambda: self.rewrite(dict(self.manifest(), extra=1)),
            "oversized manifest": lambda: self.write(os.path.join(self.plan(), "manifest.json"),
                                                     " " * (power.MAX_METADATA + 1)),
            "unaligned": lambda: self.sealed(self.ranges[:header] + power.RANGES_RECORD.pack(first[0], 0, first[2] + 1, first[3])
                                             + self.ranges[header + size:]),
            "overflow": lambda: self.sealed(self.ranges[:header] + power.RANGES_RECORD.pack(first[0], 0, (1 << 64) - page,
                                                                                             first[3])
                                            + self.ranges[header + size:]),
            "reserved": lambda: self.sealed(self.ranges[:header] + power.RANGES_RECORD.pack(first[0], 1, first[2], first[3])
                                            + self.ranges[header + size:]),
            "overlap": lambda: self.sealed(self.ranges[:header + size] + power.RANGES_RECORD.pack(first[0], 0, first[2], page)
                                           + self.ranges[header + 2 * size:]),
            "wrong count": lambda: self.rewrite(dict(self.manifest(), range_count=self.manifest()["range_count"] + 1)),
            "not JSON": lambda: self.write(os.path.join(self.plan(), "manifest.json"), "{"),
        }
        original = self.manifest()
        for name, damage in cases.items():
            with self.subTest(name):
                self.rewrite(original, self.ranges)
                self.assertIsNotNone(power.load_plan(self.host, ID))
                damage()
                with self.assertRaises((power.Invalid, ValueError)):
                    power.load_plan(self.host, ID)
        result = self.restore()
        self.assertEqual(result["prepared_restore"], {"version": 1, "status": "skipped", "reason": "plan_invalid"})
        self.assertEqual(result["outcome"], "resumed")
        self.assertEqual(self.kernel.calls, [])


class EntryTest(Fixture):
    def call(self, argv, stdin=b"", unit=None):
        out = io.StringIO()
        stdout, sys.stdout = sys.stdout, out
        try:
            code = power.main(argv, self.host, io.BytesIO(stdin), unit or f"orbit-instance-power-{ID}.service")
        finally:
            sys.stdout = stdout
        return code, out.getvalue()

    def test_bad_arguments_and_intent_are_refused_before_any_change(self):
        intent = json.dumps(self.intent(11)).encode()
        for argv, stdin in ((["prepare", "--instance", "07", "--operation", "11"], intent),
                            (["prepare", "--instance", "7", "--operation", "-1"], intent),
                            (["prepare", "--instance", "7", "--operation", "11", "--pid", "1"], intent),
                            (["restore", "--instance=7", "--operation", "11"], intent),
                            (["residual", "--instance", "7", "--operation", "11"], b""),
                            (["prepare", "--instance", "7", "--operation", "11"], b"x" * (power.MAX_INPUT + 1)),
                            (["prepare", "--instance", "7", "--operation", "11"],
                             json.dumps(dict(self.intent(11), incus_name="instance-8")).encode()),
                            (["prepare", "--instance", "7", "--operation", "11"],
                             json.dumps(dict(self.intent(11), web={"address": "../x", "port": 80})).encode())):
            with self.subTest(argv=argv):
                code, out = self.call(argv, stdin)
                self.assertEqual(code, 70)
                self.assertEqual(json.loads(out)["error_code"], "instance.power_helper_failed")
        self.assertEqual(self.incus.actions, [])
        code, _ = self.call(["prepare", "--instance", "7", "--operation", "11"], intent, unit="t3.service")
        self.assertEqual(code, 70)
        self.assertEqual(self.incus.actions, [])

    def test_no_address_or_pid_leaves_the_helper(self):
        code, prepared = self.call(["prepare", "--instance", "7", "--operation", "11"], json.dumps(self.intent(11)).encode())
        self.assertEqual(code, 0)
        manifest, ranges = power.load_plan(self.host, ID)
        sleep = open(os.path.join(self.state, "instances", str(ID), "sleep.json")).read()
        code, restored = self.call(["restore", "--instance", "7", "--operation", "12"])
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(restored)["outcome"], "resumed")
        public = [prepared, restored, sleep]
        for name in os.listdir(os.path.join(self.state, "instances", str(ID), "receipts")):
            public.append(open(os.path.join(self.state, "instances", str(ID), "receipts", name)).read())
        secrets = {str(p["host_pid"]) for p in manifest["processes"]} | {str(r[2]) for r in ranges} | \
            {format(r[2], "x") for r in ranges}
        for text in public:
            for token in re.findall(r"[0-9a-f]+", text):
                self.assertNotIn(token, secrets)
        self.assertLessEqual(max(len(prepared), len(restored)), power.MAX_OUTPUT)

    def test_discard_removes_the_instance_files_and_its_outcomes_only(self):
        self.prepare()
        outcomes = os.path.join(self.state, "outcomes")
        os.makedirs(outcomes)
        for name in ("app-instance-7-op-12.done", "app-instance-70-op-12.done"):
            self.write(os.path.join(outcomes, name), "")
        self.assertEqual(self.call(["discard", "--instance", "7", "--plan-only"])[0], 0)
        self.assertFalse(os.path.exists(self.plan()))
        self.assertTrue(os.path.exists(os.path.join(self.state, "instances", "7", "limits.json")))
        self.assertEqual(self.call(["discard", "--instance", "7"])[0], 0)
        self.assertFalse(os.path.exists(os.path.join(self.state, "instances", "7")))
        self.assertEqual(os.listdir(outcomes), ["app-instance-70-op-12.done"])


def cold_reload_prepare():
    fixture = Fixture("setUp")
    fixture.setUp()
    fixture.prepare()
    for child in fixture.children:
        os.set_inheritable(child.stdin.fileno(), True)
    os.execv(sys.executable, [sys.executable, "-I", __file__, "--cold-restore", fixture.root,
                              *(str(child.pid) for child in fixture.children)])


def cold_reload_restore(root, pids):
    kernel = CountingKernel()
    host = power.Host(state=os.path.join(root, "state"), cgroups=os.path.join(root, "cgroup"), proc=os.path.join(root, "proc"),
                      kernel=kernel)
    manifest, ranges = power.load_plan(host, ID)
    status, counts = power.restore_pages(host, manifest, ranges, time.monotonic() + 2)
    shutil.rmtree(root)
    for pid in pids:
        os.kill(pid, 9)
        os.waitpid(pid, 0)
    print(json.dumps({"status": status, "pages": counts["pages_read"],
                      "processes_read": len({pid for call, pid in kernel.calls if call == "readv"})}))


if __name__ == "__main__":
    if sys.argv[1:2] == ["--cold-reload"]:
        cold_reload_prepare()
    elif sys.argv[1:2] == ["--cold-restore"]:
        cold_reload_restore(sys.argv[2], [int(pid) for pid in sys.argv[3:]])
    else:
        unittest.main()
