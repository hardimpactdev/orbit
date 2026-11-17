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
from pathlib import Path

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
            return int(re.search(r"^Pid:\s*(-?\d+)", handle.read(), re.MULTILINE).group(1))

    def pids(self):
        return {pid for _, pid in self.calls}


class AdviceKernel(CountingKernel):
    """process_madvise answers in full or one page short, so both statuses run on every host, whatever its CAP_SYS_NICE."""

    def __init__(self, short):
        super().__init__()
        self.short = short
        self.advised = []

    def process_madvise(self, pidfd, iovecs, count):
        self.calls.append(("madvise", self.pid_of(pidfd)))
        lengths = [iovecs[i].length for i in range(count)]
        self.advised.extend(lengths)
        return sum(lengths) - (os.sysconf("SC_PAGESIZE") if self.short else 0), 0


class SignalRefusingKernel(AdviceKernel):
    """The kernel as the helper's unit meets a container process of another uid: a signal, even signal 0, is refused (EPERM)
    without CAP_KILL, which the unit does not hold. Any syscall the helper makes through `_call` with a signal number is refused."""

    def _call(self, *args):
        if args[0] == 424:  # pidfd_send_signal
            return -1, 1
        return super()._call(*args)


class ProbeHost(power.Host):
    """The probe's target is a child of the test, in place of the systemd unit of another uid."""

    def probe_target(self):
        child = subprocess.Popen([sys.executable, "-I", "-c", CHILD], stdin=subprocess.PIPE, stdout=subprocess.PIPE)
        child.stdout.readline()

        def stop():
            child.kill()
            child.wait()
            child.stdin.close()
            child.stdout.close()
        return child.pid, stop


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
        self.host = ProbeHost(state=self.state, requests=os.path.join(self.root, "shm"), cgroups=self.cgroups, proc=self.proc,
                               incus=self.incus, kernel=self.kernel, helper=HELPER,
                               fleet_node=os.path.join(self.root, "instance-fleet-node"))
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

    def test_an_instance_of_an_instance_fleet_node_records_no_plan(self):
        self.write(self.host.fleet_node, "")
        result = self.prepare()
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


class DeadlineTest(Fixture):
    def test_the_prepare_deadline_is_checked_for_each_pagemap_chunk(self):
        pagemap = os.open("/proc/self/pagemap", os.O_RDONLY)
        self.addCleanup(os.close, pagemap)
        with self.assertRaises(power.Bound) as bound:
            power.resident_runs(pagemap, 1 << 30, (1 << 30) + (1 << 20), 4096, time.monotonic() - 1)
        self.assertEqual(bound.exception.reason, "prepare_timeout")


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
        before = Path(path).read_text()
        self.assertEqual(power.residual(self.host, ID, 12)["outcome"], "not_recorded")
        self.incus.status = "running"
        self.assertEqual(power.residual(self.host, ID, 11)["outcome"], "not_recorded")
        self.incus.status, self.incus.incarnation = "frozen", "uuid-2"
        self.assertEqual(power.residual(self.host, ID, 11)["state_after"], "missing")
        self.assertEqual(Path(path).read_text(), before)
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

    def test_the_subtree_is_sorted_whatever_order_the_filesystem_lists_it_in(self):
        walk = power.os.walk
        power.os.walk = lambda root: reversed(list(walk(root)))
        try:
            cgroups = power.subtree_cgroups(self.host, RELATIVE)
        finally:
            power.os.walk = walk
        self.assertEqual(cgroups, [RELATIVE, f"{RELATIVE}/app", f"{RELATIVE}/app/worker", f"{RELATIVE}/init.scope"])

    def test_a_record_of_another_boot_closes_only_the_container_and_an_open_record_is_closed_before_the_next_sleep(self):
        self.prepare()
        self.boot("boot-2")
        self.incus.status = "running"
        closed = power.close_swap(self.host, ID, RELATIVE)
        self.assertEqual(closed, [])
        self.assertEqual(power.read_value(os.path.join(self.container, "app/worker/memory.swap.max")), "max")
        self.assertEqual(power.read_value(os.path.join(self.container, "memory.swap.max")), "0")

        self.boot("boot-1")
        self.write(os.path.join(self.container, "app/worker/memory.swap.max"), "0\n")
        self.prepare(13)
        # A record that no resume closed, of a container that runs again: the next sleep closes it first, so the path it
        # opened is recorded again and the next resume closes it.
        self.incus.status = "running"
        self.prepare(15)
        self.restore(16)
        self.assertEqual(power.read_value(os.path.join(self.container, "app/worker/memory.swap.max")), "0")

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

    def test_full_advice_and_reads_are_complete_and_short_advice_is_partial_never_complete(self):
        for short, status in ((False, "complete"), (True, "partial")):
            with self.subTest(short=short):
                self.kernel = self.host.kernel = AdviceKernel(short)
                self.incus.status = "running"
                self.prepare()
                result = self.restore()
                self.assertEqual(result["prepared_restore"]["status"], status)
                self.assertEqual({call for call, _ in self.kernel.calls}, {"madvise", "readv"})

    def test_a_target_that_refuses_every_signal_is_still_restored_in_full(self):
        # A container process runs under another uid; the liveness check before each batch must need no privilege.
        self.kernel = self.host.kernel = SignalRefusingKernel(short=False)
        self.prepare()
        result = self.restore()
        self.assertEqual(result["prepared_restore"]["status"], "complete")
        self.assertGreater(result["prepared_restore"]["pages_read"], 0)

    def test_a_restore_that_reads_no_page_is_skipped_never_prepared(self):
        class Refusing(AdviceKernel):
            def process_vm_readv(self, pid, local, remote, count):
                self.calls.append(("readv", pid))
                return -1, 1
        self.kernel = self.host.kernel = Refusing(short=True)
        self.prepare()
        prepared = self.restore()["prepared_restore"]
        self.assertEqual((prepared["status"], prepared["reason"], prepared["pages_read"]), ("skipped", "nothing_read", 0))

    def test_another_incarnation_after_the_restore_is_never_unfrozen(self):
        self.prepare()
        reads = []
        original = self.incus.instance

        def instance(project, name):
            reads.append(name)
            live = original(project, name)
            return dict(live, incarnation="uuid-2") if len(reads) > 1 else live

        self.incus.instance = instance
        result = self.restore(intent=False)
        self.assertEqual(result["outcome"], "missing")
        self.assertEqual(self.incus.actions, ["freeze"])
        self.assertTrue(self.kernel.calls, "the restore ran while frozen; only the unfreeze is refused")

    def test_a_recreated_cgroup_makes_the_plan_stale_without_memory_calls(self):
        self.prepare()
        moved = self.container + ".old"
        os.rename(self.container, moved)
        shutil.copytree(moved, self.container)
        result = self.restore()
        self.assertEqual(result["prepared_restore"], {"version": 1, "status": "skipped", "reason": "plan_stale"})
        self.assertEqual(self.kernel.calls, [])

    def test_a_plan_of_another_sleep_is_stale(self):
        self.prepare()
        path = os.path.join(self.state, "instances", str(ID), "sleep.json")
        with open(path) as handle:
            sleep = json.load(handle)
        sleep["prepared_restore"]["generation"] = "00000000-0000-4000-8000-000000000000"
        self.write(path, json.dumps(sleep))
        self.assertEqual(self.restore()["prepared_restore"]["reason"], "plan_stale")
        self.assertEqual(self.kernel.calls, [])

    def test_a_container_neither_frozen_running_nor_stopped_gets_no_memory_call_and_no_resume(self):
        self.prepare()
        self.incus.status = "error"
        result = self.restore()
        self.assertEqual((result["outcome"], result["state_after"]), ("failed", "error"))
        self.assertEqual(self.incus.actions, ["freeze"])
        self.assertEqual(self.kernel.calls, [])
        self.assertTrue(os.path.exists(self.plan()))

    def test_advice_splits_a_range_over_one_gib_instead_of_cutting_it(self):
        kernel = AdviceKernel(False)
        host = power.Host(proc="/proc", kernel=kernel, incus=self.incus, helper=HELPER)
        target = power.Target(0, {"host_pid": os.getpid(), "start_ticks": power.start_ticks(host, os.getpid())},
                              [(0, 0, 1 << 30, (5 << 29) + 4096)])
        target.pidfd = kernel.pidfd_open(os.getpid())[0]
        target.stat_fd = os.open("/proc/self/stat", os.O_RDONLY)
        self.addCleanup(os.close, target.pidfd)
        self.addCleanup(os.close, target.stat_fd)
        self.assertIsNotNone(power._advise(host, target, time.monotonic() + 10, kernel.iov_max))
        self.assertEqual(sum(kernel.advised), (5 << 29) + 4096)
        self.assertLessEqual(max(kernel.advised), power.MAX_ADVISE_BYTES)

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
        # Every target was abandoned, so no page came back: nothing was prepared.
        self.assertEqual((result["prepared_restore"]["status"], result["prepared_restore"]["reason"]), ("skipped", "nothing_read"))
        self.assertEqual(self.kernel.calls, [])
        self.assertEqual(self.incus.actions, ["freeze", "unfreeze"])

    def test_a_run_that_finds_a_recreated_stopped_container_drops_the_old_files_and_a_frozen_one_stays_untouched(self):
        self.prepare(11)
        base = os.path.join(self.state, "instances", str(ID))
        self.incus.status, self.incus.incarnation = "frozen", "uuid-2"
        self.assertEqual(self.restore(12)["outcome"], "missing", "another frozen incarnation is never touched")
        self.assertTrue(os.path.isdir(self.plan()))
        self.assertEqual(self.restore(12, intent=False)["outcome"], "missing", "the fast worker never trusts a stale descriptor")
        self.incus.status = "stopped"
        self.assertEqual(self.restore(12, intent=False)["outcome"], "missing", "without the run's intent nothing is rewritten")
        result = self.restore(13)
        self.assertEqual((result["outcome"], result["prepared_restore"]["status"]), ("stopped", "not_applicable"))
        for name in ("plan", "limits.json", "sleep.json"):
            self.assertFalse(os.path.exists(os.path.join(base, name)), name)
        with open(os.path.join(base, "target.json")) as handle:
            self.assertEqual(json.load(handle)["incarnation"], "uuid-2")
        self.assertEqual(self.kernel.calls, [])

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
        victim = min(self.children, key=lambda c: c.pid)
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
        output = subprocess.run([sys.executable, "-I", __file__, "--cold-reload"], capture_output=True, text=True, timeout=60, check=False)
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


class KernelFreezeTest(Fixture):
    """Incus reads a container's state from its liblxc monitor, which can lose a freeze: on 2026-11-17 Instance 2454 read Running while
    the kernel held its cgroup frozen, so the wake skipped the resume and its first exec hung until the run's bound. The helper reads
    the kernel's own freeze and thaws such a container within its bound, or fails `instance.container_frozen_unmanaged` at once."""

    def setUp(self):
        super().setUp()
        self.addCleanup(setattr, power, "THAW_SECONDS", power.THAW_SECONDS)
        power.THAW_SECONDS = 0.3

    def freeze_files(self, frozen):
        self.write(os.path.join(self.container, "cgroup.freeze"), "1\n" if frozen else "0\n")
        self.write(os.path.join(self.container, "cgroup.events"), f"populated 1\nfrozen {1 if frozen else 0}\n")

    def lose_the_freeze(self):
        """A sleep froze the container; then Incus's view went back to running while the kernel kept it frozen."""
        self.prepare()
        self.incus.status = "running"
        self.freeze_files(True)

    def thaws(self, delay=0.05):
        """The kernel: once the helper wrote 0 to cgroup.freeze, `cgroup.events` says `frozen 0` after `delay`."""
        def watch():
            deadline = time.monotonic() + 5
            while time.monotonic() < deadline:
                if power.read_value(os.path.join(self.container, "cgroup.freeze")) == "0":
                    time.sleep(delay)
                    self.write(os.path.join(self.container, "cgroup.events"), "populated 1\nfrozen 0\n")
                    return
                time.sleep(0.005)
        thread = threading.Thread(target=watch)
        thread.start()
        self.addCleanup(thread.join)

    def test_a_container_incus_calls_running_but_the_kernel_holds_frozen_is_thawed_without_incus_or_memory_calls(self):
        self.lose_the_freeze()
        self.thaws()
        result = self.restore()
        self.assertEqual((result["outcome"], result["state_before"], result["state_after"]), ("resumed", "running", "running"))
        self.assertTrue(result["kernel_frozen"])
        self.assertEqual(result["prepared_restore"], {"version": 1, "status": "skipped", "reason": "plan_stale"})
        self.assertFalse(power.kernel_frozen(self.host, RELATIVE))
        self.assertEqual(self.incus.actions, ["freeze"], "no Incus call: Incus refuses to unfreeze a container it calls running")
        self.assertEqual(self.kernel.calls, [])
        # The resume closes the swap the sleep opened and drops its files, as any resume does.
        for path in ("", "init.scope", "app/worker"):
            self.assertEqual(power.read_value(os.path.join(self.container, path, "memory.swap.max")), "0")
        self.assertEqual(sorted(os.listdir(os.path.join(self.state, "instances", str(ID)))), ["target.json"])

    def test_a_container_that_stays_frozen_fails_clearly_within_the_bound_and_keeps_its_sleep(self):
        self.lose_the_freeze()
        started = time.monotonic()
        result = self.restore()
        self.assertLess(time.monotonic() - started, 2)
        self.assertEqual((result["outcome"], result["error_code"], result["state_after"]), ("failed", power.UNMANAGED, "frozen"))
        self.assertEqual(power.read_value(os.path.join(self.container, "memory.swap.max")), "max", "the sleep's swap stays open")
        self.assertTrue(os.path.exists(os.path.join(self.state, "instances", str(ID), "sleep.json")))
        # A later wake, once the kernel thaws, resumes it.
        self.thaws()
        self.assertEqual(self.restore()["outcome"], "resumed")

    def test_a_sleep_never_freezes_a_container_the_kernel_already_holds_frozen(self):
        self.lose_the_freeze()
        result = power.prepare(self.host, ID, 13, self.intent(13))
        self.assertEqual((result["outcome"], result["error_code"], result["state_after"]), ("failed", power.UNMANAGED, "frozen"))
        self.assertEqual(self.incus.actions, ["freeze"])
        with open(os.path.join(self.state, "instances", str(ID), "sleep.json")) as handle:
            self.assertEqual(json.load(handle)["sleep_operation_id"], 11, "the first sleep's record stays for the wake")

    def test_a_resume_waits_for_a_thaw_the_kernel_has_not_made_yet_and_fails_one_that_never_comes(self):
        self.prepare()
        # Incus's unfreeze marks the container running at once; the kernel still holds it frozen.
        self.freeze_files(True)
        self.thaws(delay=0.1)
        result = self.restore()
        self.assertEqual(result["outcome"], "resumed")
        self.assertEqual(self.incus.actions, ["freeze", "unfreeze"])
        self.assertFalse(power.kernel_frozen(self.host, RELATIVE))
        self.prepare(operation=21)
        self.freeze_files(True)
        result = self.restore(operation=22)
        self.assertEqual((result["outcome"], result["error_code"], result["state_after"]), ("failed", power.UNMANAGED, "frozen"))
        # The plan went before the resume; the sleep's record and its swap limits stay for the next wake.
        base = os.path.join(self.state, "instances", str(ID))
        self.assertEqual([os.path.exists(os.path.join(base, f)) for f in ("plan", "sleep.json", "limits.json")], [False, True, True])

    def test_a_cgroup_without_freeze_files_keeps_incus_word(self):
        self.prepare()
        self.incus.status = "running"
        self.assertFalse(power.kernel_frozen(self.host, RELATIVE))
        self.assertEqual(self.restore()["outcome"], "already_running")
        self.freeze_files(False)
        self.assertEqual(self.restore()["outcome"], "already_running")


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


    def test_a_plan_that_cannot_be_read_is_invalid_and_the_wake_still_resumes(self):
        def link():
            os.unlink(os.path.join(self.plan(), "manifest.json"))
            os.symlink(os.path.join(self.root, "elsewhere"), os.path.join(self.plan(), "manifest.json"))

        def folder(name):
            os.unlink(os.path.join(self.plan(), name))
            os.mkdir(os.path.join(self.plan(), name))

        cases = {"symlinked manifest": link, "manifest is a folder": lambda: folder("manifest.json"),
                 "ranges is a folder": lambda: folder("ranges.bin"),
                 "deeply nested manifest": lambda: self.write(os.path.join(self.plan(), "manifest.json"), "[" * 100000)}
        for name, damage in cases.items():
            with self.subTest(name):
                self.incus.status = "running"
                self.prepare()
                damage()
                result = self.restore()
                self.assertEqual(result["prepared_restore"], {"version": 1, "status": "skipped", "reason": "plan_invalid"})
                self.assertEqual(result["outcome"], "resumed")
                self.assertFalse(os.path.lexists(self.plan()), "the unreadable plan is gone, so no later wake trips on it")
        self.assertEqual(self.kernel.calls, [])


class EntryTest(Fixture):
    def test_the_probe_target_stops_a_leftover_first_and_ends_after_thirty_seconds(self):
        calls = []

        def run(argv, **_):
            calls.append(argv)
            if argv[0] == "systemd-run":
                raise subprocess.TimeoutExpired(argv, 10)
        original, power.subprocess.run = power.subprocess.run, run
        try:
            with self.assertRaises(subprocess.TimeoutExpired):
                power.Host(cgroups=self.cgroups).probe_target()
        finally:
            power.subprocess.run = original
        self.assertEqual(calls[0], ["systemctl", "stop", "orbit-power-probe-target.service"])
        self.assertIn("--property=RuntimeMaxSec=30", calls[1])

    def test_the_probe_runs_only_in_its_own_unit(self):
        self.assertEqual(self.call(["capabilities"], unit=f"orbit-instance-power-{ID}.service")[0], 70)
        code, out = self.call(["capabilities"], unit="orbit-instance-power-probe.service")
        self.assertEqual((code, json.loads(out)["outcome"]), (0, "checked"))

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
        sleep = Path(self.state, "instances", str(ID), "sleep.json").read_text()
        code, restored = self.call(["restore", "--instance", "7", "--operation", "12"])
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(restored)["outcome"], "resumed")
        public = [prepared, restored, sleep]
        for name in os.listdir(os.path.join(self.state, "instances", str(ID), "receipts")):
            public.append(Path(self.state, "instances", str(ID), "receipts", name).read_text())
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
        code, out = self.call(["discard", "--instance", "7"])
        self.assertEqual((code, json.loads(out)["removed"]), (0, True))
        self.assertFalse(os.path.exists(os.path.join(self.state, "instances", "7")))
        self.assertEqual(os.listdir(outcomes), ["app-instance-70-op-12.done"])
        self.assertFalse(json.loads(self.call(["discard", "--instance", "7"])[1])["removed"], "a repeated discard changes nothing")


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
