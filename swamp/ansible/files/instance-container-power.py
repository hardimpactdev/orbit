#!/usr/bin/python3 -I
# The power helper of development containers (docs/reference/prepared-restore.md, ADR 0261). The app-dev role installs it as
# /usr/local/libexec/orbit-instance-power. Every caller (the sleep and wake runs, deploy and start, destroy and the Node's fast
# wake) runs it as the transient unit orbit-instance-power-<id>.service; the unit name is the Instance's lock. Commands:
#   capabilities                                  probe the kernel primitives and write the capability manifest
#   prepare --instance <id> --operation <opid>    open swap, pause, record the plan, reclaim (intent on stdin)
#   restore --instance <id> --operation <opid>    validate the plan, restore pages while frozen, unfreeze, close swap
#   residual --instance <id> --operation <opid>   read the frozen container's memory once and store it in the sleep's result
#   discard --instance <id> [--plan-only]         remove the Instance's helper files, or only its plan
# The only dynamic arguments are positive decimal ids. stdout is one JSON object of at most 64 KiB. No address, PID or raw /proc
# data leaves the helper: results carry counts, sizes and durations only. The kernel is called through ctypes; the scan works on
# whole pagemap buffers, and the restore uses bounded worker threads with a deadline checked before each batch.
import array
import ctypes
import hashlib
import http.client
import ipaddress
import json
import os
import queue
import re
import select
import shutil
import socket
import struct
import subprocess
import sys
import threading
import time
import uuid
from datetime import datetime, timezone

HELPER_VERSION = 1
SCHEMA_VERSION = 1
PLAN_SCHEMA = 1

# Global safety bounds (docs/reference/prepared-restore.md#bounds). Hitting one abandons the hint with a reason code.
PREPARE_SECONDS = 30.0
RESTORE_SECONDS = 2.0
MAX_WORKERS = 32
MAX_PROCESSES = 4096
MAX_RANGES = 1_048_576
MAX_METADATA = 64 << 20
MAX_RESIDENCY = 8 << 30
MAX_INPUT = 16 << 10
MAX_OUTPUT = 64 << 10
USER_LIMIT = 1 << 56
# Bytes of one process_madvise call stay well below the kernel's MAX_RW_COUNT.
MAX_ADVISE_BYTES = 1 << 30
PAGEMAP_CHUNK = 1 << 16
RESUME_SECONDS = 1
# The bound of a thaw that the helper writes itself, when the kernel holds a container frozen that Incus no longer calls frozen.
THAW_SECONDS = 5.0
UNMANAGED = "instance.container_frozen_unmanaged"
RECEIPTS_KEPT = 16

PROJECTS = ("orbit-instances", "orbit-instances-nested")
NESTED_PROJECT = "orbit-instances-nested"
SPECIAL_MAPPINGS = ("[vvar]", "[vvar_vclock]", "[vdso]", "[vsyscall]")
PRIMITIVES = ("cgroup_v2", "proc_maps", "proc_pagemap", "pidfd_open", "process_madvise", "process_vm_readv")
REASONS = ("no_plan", "plan_invalid", "plan_stale", "unsupported", "placement_unproven", "bound_processes", "bound_ranges",
           "bound_metadata", "bound_residency", "prepare_timeout", "capture_failed", "stopped", "deadline", "nothing_read")

# The capability probe's target unit and its program: it touches 1 MiB, tells systemd it is ready, then waits for its stop.
PROBE_TARGET = "orbit-power-probe-target"
PROBE_CHILD = ("import os, signal, socket\n"
               "data = bytearray(1 << 20)\n"
               "data[::4096] = b'x' * (len(data) // 4096)\n"
               "path = os.environ['NOTIFY_SOCKET']\n"
               "sock = socket.socket(socket.AF_UNIX, socket.SOCK_DGRAM)\n"
               "sock.connect('\\0' + path[1:] if path.startswith('@') else path)\n"
               "sock.send(b'READY=1')\n"
               "signal.pause()\n")

RANGES_MAGIC = b"ORBRNG1\0"
RANGES_HEADER = struct.Struct("<8sIIQII")
RANGES_RECORD = struct.Struct("<IIQQ")

ID = re.compile(r"[1-9][0-9]{0,18}\Z")
SYS_PIDFD_OPEN, SYS_PROCESS_VM_READV, SYS_PROCESS_MADVISE = 434, 310, 440
MADV_WILLNEED = 3


class Refused(Exception):
    """The input is invalid: the helper touched nothing."""


class Invalid(Exception):
    """A plan failed validation; the whole generation is rejected."""


class Bound(Exception):
    """A capture hit a bound; its reason is the bounded code."""

    def __init__(self, reason):
        super().__init__(reason)
        self.reason = reason


# ---------------------------------------------------------------------------------------------------------------------------
# Kernel calls


class Iovec(ctypes.Structure):
    _fields_ = [("base", ctypes.c_void_p), ("length", ctypes.c_size_t)]


class Kernel:
    """The four syscalls of the engine. Tests replace it to count or refuse memory calls."""

    def __init__(self):
        self.libc = ctypes.CDLL(None, use_errno=True)
        self.libc.syscall.restype = ctypes.c_long
        self.iov_max = min(512, os.sysconf("SC_IOV_MAX"))

    def _call(self, *args):
        result = self.libc.syscall(*args)
        return (result, 0) if result >= 0 else (-1, ctypes.get_errno())

    def pidfd_open(self, pid):
        return self._call(SYS_PIDFD_OPEN, ctypes.c_int(pid), ctypes.c_uint(0))

    def pidfd_alive(self, pidfd):
        """A pidfd turns readable once its process exited. Polling it needs no privilege, unlike a signal 0, which the kernel refuses
        to a process of another uid without CAP_KILL: every container process under an isolated idmap."""
        poller = select.poll()
        poller.register(pidfd, select.POLLIN)
        return not poller.poll(0)

    def process_madvise(self, pidfd, iovecs, count):
        return self._call(SYS_PROCESS_MADVISE, ctypes.c_int(pidfd), iovecs, ctypes.c_size_t(count), ctypes.c_int(MADV_WILLNEED),
                          ctypes.c_uint(0))

    def process_vm_readv(self, pid, local, remote, count):
        return self._call(SYS_PROCESS_VM_READV, ctypes.c_int(pid), ctypes.byref(local), ctypes.c_ulong(1), remote,
                          ctypes.c_ulong(count), ctypes.c_ulong(0))


# ---------------------------------------------------------------------------------------------------------------------------
# Host: fixed paths and the Incus API. Tests build one with their own folders and a fake Incus.


class Incus:
    """The Incus REST API on its Unix socket; every request opens a fresh connection."""

    def __init__(self, path="/var/lib/incus/unix.socket"):
        self.path = path

    def _request(self, method, url, body=None, timeout=5):
        class Connection(http.client.HTTPConnection):
            def connect(conn):
                conn.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
                conn.sock.settimeout(timeout)
                conn.sock.connect(self.path)

        connection = Connection("incus", timeout=timeout)
        try:
            payload = None if body is None else json.dumps(body)
            connection.request(method, url, body=payload, headers={"Content-Type": "application/json"})
            response = connection.getresponse()
            return response.status, json.loads(response.read(1 << 20) or b"{}")
        finally:
            connection.close()

    def instance(self, project, name):
        """{'status': 'running|frozen|stopped', 'incarnation': ..., 'init_pid': int|None}, or None when missing."""
        status, data = self._request("GET", f"/1.0/instances/{name}?project={project}&recursion=1")
        if status == 404:
            return None
        if status != 200:
            raise RuntimeError("incus instance read failed")
        meta = data.get("metadata") or {}
        state = meta.get("state") or {}
        pid = state.get("pid")
        return {"status": str(meta.get("status", "")).lower(), "incarnation": (meta.get("config") or {}).get("volatile.uuid"),
                "init_pid": pid if isinstance(pid, int) and pid > 0 else None}

    def change(self, project, name, action, seconds):
        """Freeze or unfreeze within a bound; True when the operation succeeded."""
        status, data = self._request("PUT", f"/1.0/instances/{name}/state?project={project}",
                                     {"action": action, "timeout": seconds}, timeout=seconds + 1)
        if status not in (200, 202):
            return False
        operation = data.get("operation")
        if not operation:
            return data.get("status_code") == 200
        status, data = self._request("GET", f"{operation}/wait?timeout={seconds}", timeout=seconds + 1)
        return status == 200 and (data.get("metadata") or {}).get("status_code") == 200


class Host:
    def __init__(self, state="/var/lib/orbit/hibernation", requests="/dev/shm/orbit/hibernation", cgroups="/sys/fs/cgroup",
                 proc="/proc", incus=None, kernel=None, helper=None, fleet_node="/etc/orbit/instance-fleet-node"):
        self.state = state
        self.fleet_node = fleet_node
        self.requests = requests
        self.cgroups = cgroups
        self.proc = proc
        self.incus = incus or Incus()
        self.kernel = kernel or Kernel()
        self.helper = helper or os.path.realpath(__file__)

    def unproven(self, project):
        """Whether the fast path stays off (ADR 0261): a nested-fleet Instance, or any Instance of an Instance fleet Node, a third level
        below the host, whose marker the container branch writes (instance-fleet-node.yml)."""
        return project == NESTED_PROJECT or os.path.exists(self.fleet_node)

    def instance_dir(self, instance_id):
        return os.path.join(self.state, "instances", str(instance_id))

    def container_cgroup(self, project, name):
        """The container's cgroup relative to the cgroup root, as Incus names it."""
        return f"lxc.payload.{project}_{name}"

    def boot_id(self):
        with open(os.path.join(self.proc, "sys/kernel/random/boot_id")) as handle:
            return handle.read().strip()

    def probe_target(self):
        """The capability probe's target: a process of another uid that systemd starts as its own unit, as a container's processes
        run under another uid. It reserves memory, touches it, reports ready and waits. Returns (pid, stop). A target that an earlier
        probe left behind is stopped first, and systemd ends any target after 30 s, so a dead probe never blocks the next one."""
        stop = lambda: subprocess.run(["systemctl", "stop", f"{PROBE_TARGET}.service"], timeout=10,  # noqa: E731
                                      stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        stop()
        subprocess.run(["systemd-run", f"--unit={PROBE_TARGET}", "--uid=nobody", "--gid=nogroup", "--property=Type=notify",
                        "--property=NotifyAccess=main", "--property=RuntimeMaxSec=30", "--quiet", "--collect", sys.executable, "-I",
                        "-c", PROBE_CHILD], check=True, timeout=10, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
        try:
            with open(os.path.join(self.cgroups, "system.slice", f"{PROBE_TARGET}.service", "cgroup.procs")) as handle:
                return int(handle.read().split()[0]), stop
        except (OSError, IndexError, ValueError):
            stop()
            raise


# ---------------------------------------------------------------------------------------------------------------------------
# Files: private, written whole and fsynced


def ensure_dir(path, mode=0o700):
    os.makedirs(path, mode=mode, exist_ok=True)
    os.chmod(path, mode)


def fsync_dir(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def write_all(fd, data):
    """os.write may write less than asked: write until every byte is out."""
    view = memoryview(data)
    while view:
        view = view[os.write(fd, view):]


def write_file(path, data, mode=0o600):
    """Write a whole file beside its target, fsync it and rename it into place."""
    temporary = f"{path}.tmp-{uuid.uuid4().hex}"
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, mode)
    try:
        write_all(fd, data)
        os.fsync(fd)
    finally:
        os.close(fd)
    os.replace(temporary, path)
    fsync_dir(os.path.dirname(path))


def write_json(path, value, mode=0o600):
    write_file(path, json.dumps(value, sort_keys=True).encode(), mode)


def read_json(path, limit):
    """A regular file, not a symlink, at most `limit` bytes; None when it does not exist."""
    try:
        fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW)
    except FileNotFoundError:
        return None
    with os.fdopen(fd, "rb") as handle:
        data = handle.read(limit + 1)
    if len(data) > limit:
        raise Invalid("file too large")
    return json.loads(data)


def remove(path):
    if os.path.isdir(path) and not os.path.islink(path):
        shutil.rmtree(path)
    elif os.path.lexists(path):
        os.unlink(path)


# ---------------------------------------------------------------------------------------------------------------------------
# Processes


def start_ticks(host, pid):
    """Field 22 of /proc/<pid>/stat, or None when the process is gone."""
    try:
        with open(os.path.join(host.proc, str(pid), "stat"), "rb") as handle:
            stat = handle.read()
    except (FileNotFoundError, ProcessLookupError):
        return None
    return int(stat[stat.rindex(b")") + 2:].split()[19])


def in_cgroup(host, pid, relative):
    """True when the process's unified cgroup is `relative` or one of its descendants."""
    try:
        with open(os.path.join(host.proc, str(pid), "cgroup")) as handle:
            lines = handle.read().splitlines()
    except (FileNotFoundError, ProcessLookupError):
        return False
    for line in lines:
        if line.startswith("0::"):
            path = line[3:].strip("/")
            return path == relative or path.startswith(relative + "/")
    return False


def subtree_pids(host, relative):
    """Every process of the cgroup subtree, recursively, deduplicated and sorted. A cgroup that vanishes is skipped."""
    pids = set()
    for directory, _, _ in os.walk(os.path.join(host.cgroups, relative)):
        try:
            with open(os.path.join(directory, "cgroup.procs")) as handle:
                pids.update(int(line) for line in handle.read().split())
        except (FileNotFoundError, ProcessLookupError):
            continue
    return sorted(pids)


def subtree_cgroups(host, relative):
    """The relative path of each cgroup in the subtree, sorted, so the root comes first and the order is the same on every
    filesystem."""
    root = os.path.join(host.cgroups, relative)
    return sorted(os.path.relpath(directory, host.cgroups) for directory, _, _ in os.walk(root))


# ---------------------------------------------------------------------------------------------------------------------------
# Capture

_PRESENT = bytes(1 if value & 0x80 else 0 for value in range(256))
_RUN = re.compile(b"\x01+")


def resident_runs(pagemap, start, end, page_size, deadline=None):
    """Runs (start, length) of present pages in [start, end). Bit 63 of each 8-byte entry is the present bit; its top byte is
    every eighth byte, so a strided slice and one translate find the runs without a loop per page."""
    runs = []
    first = start // page_size
    pages = (end - start) // page_size
    offset = 0
    while offset < pages:
        if deadline is not None and time.monotonic() > deadline:
            raise Bound("prepare_timeout")
        count = min(PAGEMAP_CHUNK, pages - offset)
        try:
            data = os.pread(pagemap, count * 8, (first + offset) * 8)
        except OSError:
            return runs
        flags = data[7::8].translate(_PRESENT)
        base = start + offset * page_size
        for match in _RUN.finditer(flags):
            run_start = base + match.start() * page_size
            run_length = (match.end() - match.start()) * page_size
            if runs and runs[-1][0] + runs[-1][1] == run_start:
                runs[-1] = (runs[-1][0], runs[-1][1] + run_length)
            else:
                runs.append((run_start, run_length))
        if len(data) < count * 8:
            return runs
        offset += count
    return runs


def readable_mappings(maps_text):
    """(start, end) of each readable mapping that is not a kernel special mapping."""
    mappings = []
    for line in maps_text.splitlines():
        fields = line.split(None, 5)
        if len(fields) < 5 or not fields[1].startswith("r"):
            continue
        if len(fields) == 6 and fields[5].strip() in SPECIAL_MAPPINGS:
            continue
        low, high = fields[0].split("-")
        mappings.append((int(low, 16), int(high, 16)))
    return mappings


def capture_process(host, pid, page_size, deadline):
    """The process's resident readable runs, or None when it exited during the scan."""
    try:
        with open(os.path.join(host.proc, str(pid), "maps")) as handle:
            maps_text = handle.read()
        pagemap = os.open(os.path.join(host.proc, str(pid), "pagemap"), os.O_RDONLY)
    except (FileNotFoundError, ProcessLookupError):
        return None
    try:
        runs = []
        for start, end in readable_mappings(maps_text):
            for run in resident_runs(pagemap, start, end, page_size, deadline):
                if runs and runs[-1][0] + runs[-1][1] == run[0]:
                    runs[-1] = (runs[-1][0], runs[-1][1] + run[1])
                else:
                    runs.append(run)
        return runs
    finally:
        os.close(pagemap)


def capture(host, relative, residency_limit, deadline):
    """Every process of the subtree with its identity and resident runs. Raises Bound when a bound is hit."""
    page_size = os.sysconf("SC_PAGESIZE")
    processes, ranges, resident = [], [], 0
    for pid in subtree_pids(host, relative):
        if time.monotonic() > deadline:
            raise Bound("prepare_timeout")
        ticks = start_ticks(host, pid)
        if ticks is None or not in_cgroup(host, pid, relative):
            continue
        runs = capture_process(host, pid, page_size, deadline)
        if runs is None or start_ticks(host, pid) != ticks:
            continue
        if not runs:
            continue
        if len(processes) + 1 > MAX_PROCESSES:
            raise Bound("bound_processes")
        if len(ranges) + len(runs) > MAX_RANGES:
            raise Bound("bound_ranges")
        pages = sum(length for _, length in runs) // page_size
        resident += pages * page_size
        if resident > residency_limit:
            raise Bound("bound_residency")
        processes.append({"host_pid": pid, "start_ticks": ticks, "first_range": len(ranges), "range_count": len(runs),
                          "resident_pages": pages})
        ranges.extend((len(processes) - 1, start, length) for start, length in runs)
    return page_size, processes, ranges, resident


# ---------------------------------------------------------------------------------------------------------------------------
# The plan: manifest.json and ranges.bin, one generation


def encode_ranges(ranges, page_size):
    header = RANGES_HEADER.pack(RANGES_MAGIC, 1, RANGES_RECORD.size, len(ranges), page_size, 0)
    return header + b"".join(RANGES_RECORD.pack(index, 0, start, length) for index, start, length in ranges)


def publish_plan(host, instance_id, manifest, ranges_data):
    """Write a new generation in a temporary folder, fsync both files and the folder, then rename it into place."""
    base = host.instance_dir(instance_id)
    temporary = os.path.join(base, f"plan.tmp-{manifest['generation']}")
    ensure_dir(temporary)
    for name, data in (("ranges.bin", ranges_data), ("manifest.json", json.dumps(manifest, sort_keys=True).encode())):
        fd = os.open(os.path.join(temporary, name), os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        try:
            write_all(fd, data)
            os.fsync(fd)
        finally:
            os.close(fd)
    fsync_dir(temporary)
    remove(os.path.join(base, "plan"))
    os.rename(temporary, os.path.join(base, "plan"))
    fsync_dir(base)


def _positive(value):
    return isinstance(value, int) and not isinstance(value, bool) and value > 0


def _natural(value):
    return isinstance(value, int) and not isinstance(value, bool) and value >= 0


MANIFEST_KEYS = {"schema_version", "generation", "instance_id", "sleep_operation_id", "incus_project", "incus_name", "incarnation",
                 "host_boot_id", "cgroup_relative_path", "cgroup_device", "cgroup_inode", "init_host_pid", "init_start_ticks",
                 "page_size", "created_at_utc", "processes", "range_count", "resident_mapping_bytes", "ranges_file_bytes",
                 "ranges_sha256", "prepare_metrics"}
PROCESS_KEYS = {"host_pid", "start_ticks", "first_range", "range_count", "resident_pages"}


def load_plan(host, instance_id):
    """The committed plan as (manifest, ranges), None when there is none. Raises Invalid on any failed check."""
    folder = os.path.join(host.instance_dir(instance_id), "plan")
    if not os.path.lexists(folder):
        return None
    if os.path.islink(folder) or not os.path.isdir(folder):
        raise Invalid("plan folder")
    manifest = read_json(os.path.join(folder, "manifest.json"), MAX_METADATA)
    if manifest is None:
        raise Invalid("manifest missing")
    if not isinstance(manifest, dict) or set(manifest) != MANIFEST_KEYS or manifest["schema_version"] != PLAN_SCHEMA:
        raise Invalid("manifest schema")
    page_size = manifest["page_size"]
    if page_size != os.sysconf("SC_PAGESIZE") or manifest["instance_id"] != instance_id:
        raise Invalid("manifest identity")
    for key in ("sleep_operation_id", "cgroup_device", "cgroup_inode", "init_host_pid", "init_start_ticks"):
        if not _positive(manifest[key]) and not (key == "cgroup_device" and _natural(manifest[key])):
            raise Invalid("manifest field")
    processes = manifest["processes"]
    if not isinstance(processes, list) or len(processes) > MAX_PROCESSES:
        raise Invalid("processes")
    count = manifest["range_count"]
    if not _natural(count) or count > MAX_RANGES:
        raise Invalid("range count")
    expected = RANGES_HEADER.size + RANGES_RECORD.size * count
    if manifest["ranges_file_bytes"] != expected or expected > MAX_METADATA:
        raise Invalid("ranges size")
    try:
        fd = os.open(os.path.join(folder, "ranges.bin"), os.O_RDONLY | os.O_NOFOLLOW)
    except FileNotFoundError as error:
        raise Invalid("ranges missing") from error
    with os.fdopen(fd, "rb") as handle:
        data = handle.read(expected + 1)
    if len(data) != expected or hashlib.sha256(data).hexdigest() != manifest["ranges_sha256"]:
        raise Invalid("ranges digest")
    magic, version, record_size, record_count, table_page, reserved = RANGES_HEADER.unpack_from(data)
    if (magic, version, record_size, record_count, table_page, reserved) != (RANGES_MAGIC, 1, RANGES_RECORD.size, count,
                                                                              page_size, 0):
        raise Invalid("ranges header")
    ranges = list(RANGES_RECORD.iter_unpack(memoryview(data)[RANGES_HEADER.size:]))
    next_range, total = 0, 0
    for index, process in enumerate(processes):
        if not isinstance(process, dict) or set(process) != PROCESS_KEYS:
            raise Invalid("process schema")
        if not (_positive(process["host_pid"]) and _positive(process["start_ticks"]) and _natural(process["resident_pages"])):
            raise Invalid("process field")
        first, length = process["first_range"], process["range_count"]
        if first != next_range or not _natural(length) or first + length > count:
            raise Invalid("process tiling")
        next_range = first + length
        end, pages = 0, 0
        for record_index, reserved_field, start, size in ranges[first:first + length]:
            if record_index != index or reserved_field != 0:
                raise Invalid("record")
            if start == 0 or size == 0 or start % page_size or size % page_size or start + size > USER_LIMIT:
                raise Invalid("range")
            if start < end:
                raise Invalid("order")
            end = start + size
            pages += size // page_size
        if pages != process["resident_pages"]:
            raise Invalid("process totals")
        total += pages * page_size
    if next_range != count or total != manifest["resident_mapping_bytes"]:
        raise Invalid("totals")
    return manifest, ranges


# ---------------------------------------------------------------------------------------------------------------------------
# Swap limits: opened at the sleep, recorded in limits.json, closed at every resume. The one place of this rule.


def read_value(path):
    with open(path) as handle:
        return handle.read().strip()


def kernel_frozen(host, relative):
    """Whether the kernel holds the container's own cgroup frozen: its `cgroup.freeze` is 1, or `cgroup.events` still says `frozen 1`.
    Incus reads a container's state only from its liblxc monitor, which can lose a freeze: on 2026-11-17 Instance 2454 read Running
    while its cgroup stayed frozen, and every exec into it hung. A cgroup that cannot be read is not frozen: Incus's word stands."""
    path = os.path.join(host.cgroups, relative)
    try:
        if read_value(os.path.join(path, "cgroup.freeze")) == "1":
            return True
        events = read_value(os.path.join(path, "cgroup.events"))
    except OSError:
        return False
    return any(line.split() == ["frozen", "1"] for line in events.splitlines())


def thaw(host, relative, seconds):
    """Thaw the container's own cgroup in the kernel and wait until `cgroup.events` says `frozen 0`, at most `seconds`. Only for a
    container that Incus calls running: Incus refuses to unfreeze it. True when the kernel thawed it within the bound."""
    path = os.path.join(host.cgroups, relative)
    deadline = time.monotonic() + seconds
    try:
        with open(os.path.join(path, "cgroup.freeze"), "w") as handle:
            handle.write("0")
    except OSError:
        return False
    while True:
        if not kernel_frozen(host, relative):
            return True
        if time.monotonic() >= deadline:
            return False
        time.sleep(0.01)


def open_swap(host, instance_id, relative, boot_id):
    """Open `max` on each cgroup of the subtree whose memory.swap.max holds 0; record exactly what was opened."""
    zero = []
    for path in subtree_cgroups(host, relative):
        try:
            if read_value(os.path.join(host.cgroups, path, "memory.swap.max")) == "0":
                zero.append(path)
        except (FileNotFoundError, PermissionError):
            continue
    record = {"host_boot_id": boot_id, "cgroup_inode": os.stat(os.path.join(host.cgroups, relative)).st_ino,
              "opened": [{"relative_cgroup_path": path, "previous_value": "0"} for path in zero]}
    # Recorded before the writes: closing a path that was never opened writes the 0 it already holds.
    write_json(os.path.join(host.instance_dir(instance_id), "limits.json"), record)
    opened = []
    for path in zero:
        try:
            with open(os.path.join(host.cgroups, path, "memory.swap.max"), "w") as handle:
                handle.write("max")
            opened.append(path)
        except OSError:
            continue
    record["opened"] = [{"relative_cgroup_path": path, "previous_value": "0"} for path in opened]
    write_json(os.path.join(host.instance_dir(instance_id), "limits.json"), record)
    return [path for path in opened if path != relative]


def close_swap(host, instance_id, relative):
    """Write 0 to the container's own cgroup and to each recorded inner path that still exists; then remove the record.
    A record of another boot or another cgroup of the same name is stale: its paths name cgroups that sleep never opened, so only
    the container's own cgroup is closed. A vanished cgroup is not a failure. Returns the inner paths it closed."""
    record_path = os.path.join(host.instance_dir(instance_id), "limits.json")
    try:
        record = read_json(record_path, MAX_INPUT * 64) or {}
    except (Invalid, ValueError):
        record = {}
    try:
        inode = os.stat(os.path.join(host.cgroups, relative)).st_ino
    except FileNotFoundError:
        inode = None
    if not isinstance(record, dict) or record.get("host_boot_id") != host.boot_id() or record.get("cgroup_inode") != inode:
        record = {}
    inner = []
    for entry in record.get("opened", []) if isinstance(record.get("opened"), list) else []:
        path = entry.get("relative_cgroup_path") if isinstance(entry, dict) else None
        if isinstance(path, str) and path != relative and (path + "/").startswith(relative + "/") and ".." not in path.split("/"):
            inner.append(path)
    closed = []
    for path in [relative] + inner:
        try:
            with open(os.path.join(host.cgroups, path, "memory.swap.max"), "w") as handle:
                handle.write("0")
            if path != relative:
                closed.append(path)
        except FileNotFoundError:
            continue
    remove(record_path)
    return closed


# ---------------------------------------------------------------------------------------------------------------------------
# Restore engine


class Target:
    """One process of the plan with its pidfd and its work."""

    def __init__(self, index, process, ranges):
        self.index = index
        self.pid = process["host_pid"]
        self.ticks = process["start_ticks"]
        self.ranges = ranges
        self.pidfd = -1
        self.stat_fd = -1
        self.result = "pending"
        self.pages_read = 0
        self.ranges_advised = 0


def _identity(host, target, relative):
    return start_ticks(host, target.pid) == target.ticks and in_cgroup(host, target.pid, relative)


def _alive(host, target, relative):
    return host.kernel.pidfd_alive(target.pidfd) and _identity(host, target, relative)


def _current(host, target):
    """The check before each batch: the pidfd's process still lives, and the stat file opened with it still shows its start time.
    That file stays bound to the process it was opened for, so a reused PID cannot pass; a gone process fails the read."""
    if not host.kernel.pidfd_alive(target.pidfd):
        return False
    try:
        stat = os.pread(target.stat_fd, 4096, 0)
    except OSError:
        return False
    return int(stat[stat.rindex(b")") + 2:].split()[19]) == target.ticks


def _advise(host, target, deadline, iov_max):
    """process_madvise(MADV_WILLNEED) over every range in batches. False when the target must stop."""
    batch = []
    batch_bytes = 0
    complete = True

    def submit():
        nonlocal complete
        if time.monotonic() > deadline:
            return None
        if not _current(host, target):
            return None
        vector = (Iovec * len(batch))(*[Iovec(start, length) for start, length in batch])
        result, _ = host.kernel.process_madvise(target.pidfd, vector, len(batch))
        if result == sum(length for _, length in batch):
            target.ranges_advised += len(batch)
        else:
            complete = False
        return True

    # A range over MAX_ADVISE_BYTES is split into pieces, so every recorded byte is advised.
    pieces = ((start + at, min(MAX_ADVISE_BYTES, length - at)) for _, _, start, length in target.ranges
              for at in range(0, length, MAX_ADVISE_BYTES))
    for start, length in pieces:
        if batch and (len(batch) == iov_max or batch_bytes + length > MAX_ADVISE_BYTES):
            if submit() is None:
                return None
            batch, batch_bytes = [], 0
        batch.append((start, length))
        batch_bytes += length
    if batch and submit() is None:
        return None
    return complete


def _page_vector(ranges, page_size):
    """One iovec of one byte per recorded page, for all of a process's ranges: pairs of (address, 1), built once per range with
    C-level array operations, so a batch is only a view at an offset."""
    total = sum(length for _, _, _, length in ranges) // page_size
    pairs = array.array("Q", bytes(16 * total))
    at = 0
    for _, _, start, length in ranges:
        count = length // page_size
        pairs[2 * at:2 * (at + count):2] = array.array("Q", range(start, start + length, page_size))
        at += count
    pairs[1::2] = array.array("Q", [1]) * total
    return pairs, total


def _populate(host, target, deadline, iov_max, page_size):
    """process_vm_readv of one byte per page, in batches of up to iov_max iovecs that may span ranges. None when the target must
    stop."""
    pairs, total = _page_vector(target.ranges, page_size)
    scratch = ctypes.create_string_buffer(iov_max)
    local = Iovec(ctypes.addressof(scratch), 0)
    complete = True
    for offset in range(0, total, iov_max):
        if time.monotonic() > deadline or not _current(host, target):
            return None
        count = min(iov_max, total - offset)
        remote = (Iovec * count).from_buffer(pairs, offset * ctypes.sizeof(Iovec))
        local.length = count
        result, _ = host.kernel.process_vm_readv(target.pid, local, remote, count)
        target.pages_read += max(result, 0)
        if result != count:
            # An unmapped or unreadable page, a short read or ENOMEM: abandon this batch and go on with safe work.
            complete = False
    return complete


def _next(work):
    try:
        return work.get_nowait()
    except queue.Empty:
        return None


def _work(host, work, deadline, relative, iov_max, page_size):
    for target in iter(lambda: _next(work), None):
        try:
            advised = _advise(host, target, deadline, iov_max)
            populated = None if advised is None else _populate(host, target, deadline, iov_max, page_size)
        except Exception:  # noqa: BLE001 - one target's error never stops the others or counts as done
            target.result = "failed"
            continue
        if advised is None or populated is None:
            target.result = "deadline" if time.monotonic() > deadline else "skipped"
        else:
            target.result = "complete" if advised and populated else "partial"


def restore_pages(host, manifest, ranges, deadline):
    """Restore the plan's pages in parallel; every target is checked before any memory call and before each batch."""
    relative = manifest["cgroup_relative_path"]
    targets = []
    for index, process in enumerate(manifest["processes"]):
        first = process["first_range"]
        targets.append(Target(index, process, ranges[first:first + process["range_count"]]))
    counts = {"complete": 0, "partial": 0, "skipped": 0, "failed": 0}
    work = queue.SimpleQueue()
    for target in sorted(targets, key=lambda t: -len(t.ranges)):
        if not _identity(host, target, relative):
            target.result = "skipped"
            continue
        fd, _ = host.kernel.pidfd_open(target.pid)
        if fd < 0:
            target.result = "failed"
            continue
        target.pidfd = fd
        try:
            target.stat_fd = os.open(os.path.join(host.proc, str(target.pid), "stat"), os.O_RDONLY)
        except OSError:
            target.result = "skipped"
            continue
        # The pidfd now pins this incarnation: a second identity check rules out a PID reused before the open.
        if not _alive(host, target, relative):
            target.result = "skipped"
            continue
        if target.ranges:
            work.put(target)
        else:
            target.result = "complete"
    page_size = manifest["page_size"]
    workers = [threading.Thread(target=_work, args=(host, work, deadline, relative, host.kernel.iov_max, page_size), daemon=True)
               for _ in range(min(MAX_WORKERS, sum(1 for t in targets if t.result == "pending")))]
    for worker in workers:
        worker.start()
    for worker in workers:
        worker.join(max(0.0, deadline - time.monotonic()))
    # Every worker stops at its next batch; one stuck in uninterruptible I/O keeps this unit, and so the lock, active.
    for worker in workers:
        worker.join()
    timed_out = False
    for target in targets:
        if target.pidfd >= 0:
            os.close(target.pidfd)
        if target.stat_fd >= 0:
            os.close(target.stat_fd)
        if target.result in ("deadline", "pending"):
            timed_out = True
            counts["partial"] += 1
        else:
            counts[target.result] += 1
    pages = sum(t.pages_read for t in targets)
    if timed_out:
        status = "timed_out"
    elif counts["partial"] or counts["skipped"] or counts["failed"]:
        status = "partial"
    else:
        status = "complete"
    return status, {"processes": counts, "pages_read": pages, "workers": len(workers)}


# ---------------------------------------------------------------------------------------------------------------------------
# Capabilities


def probe(host):
    """Check each primitive as the restore uses it, from this unit and against a process of another uid (Host.probe_target)."""
    results = dict.fromkeys(PRIMITIVES, False)
    results["cgroup_v2"] = os.path.isfile(os.path.join(host.cgroups, "cgroup.controllers")) and \
        os.access(os.path.join(host.cgroups, "cgroup.procs"), os.R_OK)
    try:
        pid, stop = host.probe_target()
    except (OSError, subprocess.SubprocessError, ValueError, IndexError):
        return results
    pidfd = -1
    try:
        page_size = os.sysconf("SC_PAGESIZE")
        maps_text = None
        try:
            with open(os.path.join(host.proc, str(pid), "maps")) as handle:
                maps_text = handle.read()
            results["proc_maps"] = bool(readable_mappings(maps_text))
        except OSError:
            pass
        run = None
        if maps_text:
            try:
                pagemap = os.open(os.path.join(host.proc, str(pid), "pagemap"), os.O_RDONLY)
                try:
                    for start, end in readable_mappings(maps_text):
                        runs = resident_runs(pagemap, start, end, page_size)
                        if runs:
                            run = runs[0]
                            break
                finally:
                    os.close(pagemap)
                results["proc_pagemap"] = run is not None
            except OSError:
                pass
        pidfd, _ = host.kernel.pidfd_open(pid)
        # The restore's liveness check of every target, before each batch.
        results["pidfd_open"] = pidfd >= 0 and host.kernel.pidfd_alive(pidfd)
        if run is not None and pidfd >= 0:
            vector = (Iovec * 1)(Iovec(run[0], page_size))
            results["process_madvise"] = host.kernel.process_madvise(pidfd, vector, 1)[0] == page_size
            scratch = ctypes.create_string_buffer(1)
            local = Iovec(ctypes.addressof(scratch), 1)
            remote = (Iovec * 1)(Iovec(run[0], 1))
            results["process_vm_readv"] = host.kernel.process_vm_readv(pid, local, remote, 1)[0] == 1
    finally:
        if pidfd >= 0:
            os.close(pidfd)
        stop()
    return results


def helper_digest(host):
    with open(host.helper, "rb") as handle:
        return hashlib.sha256(handle.read()).hexdigest()


def capabilities(host):
    results = probe(host)
    manifest = {"helper_version": HELPER_VERSION, "plan_schema_version": PLAN_SCHEMA, "helper_sha256": helper_digest(host),
                "primitives": results, "supported": all(results.values())}
    ensure_dir(host.state, 0o755)
    write_json(os.path.join(host.state, "capabilities.json"), manifest, 0o644)
    return {"outcome": "checked", "capabilities": results}


def supported(host):
    """True only when the manifest of this helper says every primitive works. Missing means unsupported."""
    try:
        manifest = read_json(os.path.join(host.state, "capabilities.json"), MAX_INPUT)
    except (Invalid, ValueError):
        return False
    return isinstance(manifest, dict) and manifest.get("supported") is True and \
        manifest.get("helper_sha256") == helper_digest(host) and manifest.get("plan_schema_version") == PLAN_SCHEMA


# ---------------------------------------------------------------------------------------------------------------------------
# Intent and target descriptor


def parse_intent(raw, instance_id, operation_id):
    """The run's intent on stdin: one JSON object of at most 16 KiB with exactly the documented fields."""
    if len(raw) > MAX_INPUT:
        raise Refused("intent too large")
    try:
        intent = json.loads(raw)
    except ValueError as error:
        raise Refused("intent is not JSON") from error
    keys = {"schema_version", "instance_id", "operation_id", "incus_project", "incus_name", "web"}
    if not isinstance(intent, dict) or set(intent) != keys or intent["schema_version"] != 1:
        raise Refused("intent fields")
    if intent["instance_id"] != instance_id or intent["operation_id"] != operation_id:
        raise Refused("intent ids")
    if intent["incus_project"] not in PROJECTS or intent["incus_name"] != f"instance-{instance_id}":
        raise Refused("intent target")
    web = intent["web"]
    if web is not None:
        if not isinstance(web, dict) or set(web) != {"address", "port"} or not isinstance(web["address"], str):
            raise Refused("intent web")
        try:
            ipaddress.ip_address(web["address"])
        except ValueError as error:
            raise Refused("intent web address") from error
        if not isinstance(web["port"], int) or isinstance(web["port"], bool) or not 0 < web["port"] < 65536:
            raise Refused("intent web port")
    return intent


def read_target(host, instance_id):
    """The target descriptor, or None when it is missing or invalid: an invalid file never authorizes its fields."""
    try:
        target = read_json(os.path.join(host.instance_dir(instance_id), "target.json"), MAX_INPUT)
        intent = {"schema_version": target["schema_version"], "instance_id": target["instance_id"], "operation_id": 1,
                  "incus_project": target["incus_project"], "incus_name": target["incus_name"], "web": target["web"]}
        parse_intent(json.dumps(intent), instance_id, 1)
        if set(target) != {"schema_version", "instance_id", "incus_project", "incus_name", "incarnation", "web"}:
            return None
        if not isinstance(target["incarnation"], str) or not target["incarnation"]:
            return None
        return target
    except (Invalid, Refused, ValueError, TypeError, KeyError):
        return None


def write_target(host, instance_id, intent, incarnation):
    write_json(os.path.join(host.instance_dir(instance_id), "target.json"),
               {"schema_version": 1, "instance_id": instance_id, "incus_project": intent["incus_project"],
                "incus_name": intent["incus_name"], "incarnation": incarnation, "web": intent["web"]})


def write_receipt(host, instance_id, operation_id, result):
    folder = os.path.join(host.instance_dir(instance_id), "receipts")
    ensure_dir(folder)
    write_json(os.path.join(folder, f"op-{operation_id}.json"), result)
    receipts = sorted((os.stat(os.path.join(folder, name)).st_mtime_ns, name) for name in os.listdir(folder)
                      if re.fullmatch(r"op-[1-9][0-9]*\.json", name))
    for _, name in receipts[:-RECEIPTS_KEPT]:
        remove(os.path.join(folder, name))


# ---------------------------------------------------------------------------------------------------------------------------
# Commands


def skipped(reason, status="skipped"):
    return {"version": 1, "status": status, "reason": reason}


def reclaim(host, relative):
    """Ask the kernel to reclaim the frozen container's memory once; EAGAIN when nothing more can go is normal."""
    current = int(read_value(os.path.join(host.cgroups, relative, "memory.current")))
    try:
        with open(os.path.join(host.cgroups, relative, "memory.reclaim"), "w") as handle:
            handle.write(str(max(current, 1)))
    except BlockingIOError:
        pass
    except OSError as error:
        if error.errno != 11:
            raise


def residency_limit(host, relative):
    try:
        value = read_value(os.path.join(host.cgroups, relative, "memory.max"))
    except FileNotFoundError:
        return MAX_RESIDENCY
    return MAX_RESIDENCY if value == "max" else min(int(value), MAX_RESIDENCY)


def prepare(host, instance_id, operation_id, intent):
    project, name = intent["incus_project"], intent["incus_name"]
    relative = host.container_cgroup(project, name)
    base = host.instance_dir(instance_id)
    result = {"outcome": None, "error_code": None, "swap_subtree": [], "prepared_restore": None, "seconds": {}}
    live = host.incus.instance(project, name)
    result["state_before"] = live["status"] if live else "missing"
    if live is None:
        return dict(result, outcome="missing", state_after="missing")
    ensure_dir(base)
    if live["status"] == "frozen":
        sleep = read_json(os.path.join(base, "sleep.json"), MAX_INPUT * 4)
        if isinstance(sleep, dict):
            return dict(result, outcome="already_frozen", state_after="frozen", swap_subtree=sleep.get("swap_subtree", []),
                        prepared_restore=sleep.get("prepared_restore"))
        return dict(result, outcome="already_frozen", state_after="frozen", prepared_restore=skipped("no_plan"))
    if live["status"] == "stopped":
        remove(os.path.join(base, "plan"))
        return dict(result, outcome="stopped", state_after="stopped", prepared_restore={"version": 1, "status": "not_applicable"})
    # A container that Incus calls running but the kernel holds frozen is never frozen again or changed: its earlier sleep's files stay
    # for the wake, which thaws it. The sleep fails with the asleep marker in place, so a visitor gets the wake page and starts that wake.
    if live["status"] == "running" and kernel_frozen(host, relative):
        return dict(result, outcome="failed", error_code=UNMANAGED, state_after="frozen")
    remove(os.path.join(base, "plan"))
    remove(os.path.join(base, "sleep.json"))
    write_target(host, instance_id, intent, live["incarnation"])
    # A running container with a record left open (a resume that never closed it) gets it closed first, so no inner path stays
    # at `max` once the new record replaces it.
    if os.path.lexists(os.path.join(base, "limits.json")):
        close_swap(host, instance_id, relative)
    boot = host.boot_id()
    result["swap_subtree"] = open_swap(host, instance_id, relative, boot)
    started = time.monotonic()
    if not host.incus.change(project, name, "freeze", 5):
        after = host.incus.instance(project, name)
        state = after["status"] if after else "missing"
        if state != "frozen":
            close_swap(host, instance_id, relative)
            result["swap_subtree"] = []
        return dict(result, outcome="failed", error_code="instance.container_freeze_failed", state_after=state)
    paused = time.monotonic()
    result["seconds"]["pause"] = round(paused - started, 3)
    result["prepared_restore"] = prepare_plan(host, instance_id, operation_id, intent, live, relative, boot, paused, result)
    reclaimed = time.monotonic()
    try:
        reclaim(host, relative)
    except OSError:
        return dict(result, outcome="failed", error_code="instance.container_freeze_failed", state_after="frozen")
    result["seconds"]["reclaim"] = round(time.monotonic() - reclaimed, 3)
    write_json(os.path.join(base, "sleep.json"), {"sleep_operation_id": operation_id, "swap_subtree": result["swap_subtree"],
                                                  "prepared_restore": result["prepared_restore"]})
    return dict(result, outcome="frozen", state_after="frozen")


def prepare_plan(host, instance_id, operation_id, intent, live, relative, boot, paused, result):
    """Capture and publish the plan of the frozen container; returns the prepared_restore object."""
    if host.unproven(intent["incus_project"]):
        return skipped("placement_unproven")
    if not supported(host):
        return skipped("unsupported", "unsupported")
    deadline = paused + PREPARE_SECONDS
    try:
        init_pid = live["init_pid"]
        init_ticks = start_ticks(host, init_pid) if init_pid else None
        if not init_ticks:
            raise Bound("capture_failed")
        cgroup = os.stat(os.path.join(host.cgroups, relative))
        page_size, processes, ranges, resident = capture(host, relative, residency_limit(host, relative), deadline)
        captured = time.monotonic()
        result["seconds"]["capture"] = round(captured - paused, 3)
        data = encode_ranges(ranges, page_size)
        if len(data) > MAX_METADATA:
            raise Bound("bound_metadata")
        manifest = {"schema_version": PLAN_SCHEMA, "generation": str(uuid.uuid4()), "instance_id": instance_id,
                    "sleep_operation_id": operation_id, "incus_project": intent["incus_project"],
                    "incus_name": intent["incus_name"], "incarnation": live["incarnation"], "host_boot_id": boot,
                    "cgroup_relative_path": relative, "cgroup_device": cgroup.st_dev, "cgroup_inode": cgroup.st_ino,
                    "init_host_pid": init_pid, "init_start_ticks": init_ticks, "page_size": page_size,
                    "created_at_utc": datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%S.%fZ"), "processes": processes,
                    "range_count": len(ranges), "resident_mapping_bytes": resident, "ranges_file_bytes": len(data),
                    "ranges_sha256": hashlib.sha256(data).hexdigest(),
                    "prepare_metrics": {"capture_seconds": round(captured - paused, 3), "processes": len(processes),
                                        "ranges": len(ranges)}}
        if time.monotonic() > deadline:
            raise Bound("prepare_timeout")
        publish_plan(host, instance_id, manifest, data)
        result["seconds"]["publish"] = round(time.monotonic() - captured, 3)
        if time.monotonic() > deadline:
            raise Bound("prepare_timeout")
    except Bound as bound:
        remove(os.path.join(host.instance_dir(instance_id), "plan"))
        return skipped(bound.reason)
    except (OSError, ValueError):
        remove(os.path.join(host.instance_dir(instance_id), "plan"))
        return skipped("capture_failed")
    metadata = len(data) + os.path.getsize(os.path.join(host.instance_dir(instance_id), "plan", "manifest.json"))
    return {"version": 1, "status": "ready", "generation": manifest["generation"], "processes": len(processes),
            "resident_mapping_bytes": resident, "metadata_bytes": metadata,
            "prepare_seconds": round(time.monotonic() - paused, 3)}


def stale(host, manifest, live, target, relative):
    """True when the plan no longer describes this container incarnation."""
    try:
        cgroup = os.stat(os.path.join(host.cgroups, relative))
    except FileNotFoundError:
        return True
    return (manifest["host_boot_id"] != host.boot_id() or manifest["incarnation"] != live["incarnation"]
            or manifest["incarnation"] != target["incarnation"] or manifest["incus_project"] != target["incus_project"]
            or manifest["incus_name"] != target["incus_name"] or manifest["cgroup_relative_path"] != relative
            or (manifest["cgroup_device"], manifest["cgroup_inode"]) != (cgroup.st_dev, cgroup.st_ino)
            or live["init_pid"] != manifest["init_host_pid"]
            or start_ticks(host, manifest["init_host_pid"]) != manifest["init_start_ticks"]
            or not in_cgroup(host, manifest["init_host_pid"], relative))


def sleep_generation(host, instance_id):
    """The plan generation that the last sleep reported in sleep.json, or None."""
    sleep = read_json(os.path.join(host.instance_dir(instance_id), "sleep.json"), MAX_INPUT * 4)
    prepared = sleep.get("prepared_restore") if isinstance(sleep, dict) else None
    return prepared.get("generation") if isinstance(prepared, dict) else None


def restore(host, instance_id, operation_id, intent):
    started = time.monotonic()
    deadline = started + RESTORE_SECONDS
    base = host.instance_dir(instance_id)
    result = {"outcome": None, "error_code": None, "swap_subtree": [], "prepared_restore": None, "seconds": {}}
    target = read_target(host, instance_id)
    if intent is None and target is None:
        raise Refused("no target descriptor")
    source = intent or target
    project, name = source["incus_project"], source["incus_name"]
    relative = host.container_cgroup(project, name)
    live = host.incus.instance(project, name)
    result["state_before"] = live["status"] if live else "missing"
    other = target is not None and (live is None or target["incarnation"] != live["incarnation"]
                                    or (target["incus_project"], target["incus_name"]) != (project, name))
    # A stopped container that a run names holds no memory to protect: files of an earlier incarnation go, so a recreated container is
    # never blocked. A live process tree of another incarnation is never touched.
    if live is not None and other and intent is not None and live["status"] == "stopped":
        for file in ("plan", "limits.json", "sleep.json", "target.json"):
            remove(os.path.join(base, file))
        target, other = None, False
    if live is None or other:
        return dict(result, outcome="missing", state_after=result["state_before"])
    if intent is not None:
        ensure_dir(base)
        write_target(host, instance_id, intent, live["incarnation"])
        target = read_target(host, instance_id)
    if live["status"] == "running" and kernel_frozen(host, relative):
        return thaw_unmanaged(host, instance_id, relative, result)
    if live["status"] == "running":
        result["swap_subtree"] = close_swap(host, instance_id, relative)
        return dict(result, outcome="already_running", state_after="running",
                    prepared_restore={"version": 1, "status": "not_applicable"})
    if live["status"] == "stopped":
        remove(os.path.join(base, "plan"))
        remove(os.path.join(base, "limits.json"))
        remove(os.path.join(base, "sleep.json"))
        return dict(result, outcome="stopped", state_after="stopped",
                    prepared_restore={"version": 1, "status": "not_applicable"})
    if live["status"] != "frozen":
        # Freezing, error or any other state: no memory call and no resume; the caller's existing error path decides.
        return dict(result, outcome="failed", error_code="instance.container_start_failed", state_after=live["status"])
    prepared = None
    try:
        plan = load_plan(host, instance_id)
        if plan is None:
            prepared = skipped("no_plan")
        elif host.unproven(project):
            prepared = skipped("placement_unproven")
        elif not supported(host):
            prepared = skipped("unsupported")
        elif stale(host, plan[0], live, target, relative) or plan[0]["generation"] != sleep_generation(host, instance_id):
            prepared = skipped("plan_stale")
    except Exception:  # noqa: BLE001 - a plan is a hint: one that cannot be read never keeps the container frozen
        prepared = skipped("plan_invalid")
    result["seconds"]["load"] = round(time.monotonic() - started, 3)
    if prepared is None:
        loaded = time.monotonic()
        status, counts = restore_pages(host, plan[0], plan[1], deadline)
        reason = "deadline" if status == "timed_out" else None
        # A restore that read no page back prepared nothing: it is `skipped`, so the wake says `ordinary`.
        if counts["pages_read"] == 0 and plan[0]["range_count"] > 0:
            status, reason = "skipped", reason or "nothing_read"
        prepared = {"version": 1, "status": status, **({"reason": reason} if reason else {}),
                    "processes": len(plan[0]["processes"]), "pages_read": counts["pages_read"]}
        result["seconds"]["restore"] = round(time.monotonic() - loaded, 3)
        result["counts"] = counts["processes"]
    remove(os.path.join(base, "plan"))
    result["prepared_restore"] = prepared
    # A replacement container is never unfrozen with this request.
    again = host.incus.instance(project, name)
    if again is None or again["incarnation"] != live["incarnation"]:
        return dict(result, outcome="missing", state_after="missing" if again is None else again["status"])
    resuming = time.monotonic()
    if again["status"] == "frozen":
        host.incus.change(project, name, "unfreeze", RESUME_SECONDS)
    after = host.incus.instance(project, name)
    if after is None or after["incarnation"] != live["incarnation"] or after["status"] != "running":
        return dict(result, outcome="failed", error_code="instance.container_start_failed",
                    state_after=after["status"] if after else "missing")
    # liblxc marks the container running before it writes the thaw; a thaw that the kernel has not made yet is waited for, within the
    # bound, and one that never comes fails clearly instead of a later exec hanging.
    if kernel_frozen(host, relative) and not thaw(host, relative, THAW_SECONDS):
        return dict(result, outcome="failed", error_code=UNMANAGED, state_after="frozen")
    result["seconds"]["resume"] = round(time.monotonic() - resuming, 3)
    closing = time.monotonic()
    result["swap_subtree"] = close_swap(host, instance_id, relative)
    remove(os.path.join(base, "sleep.json"))
    result["seconds"]["close"] = round(time.monotonic() - closing, 3)
    return dict(result, outcome="resumed", state_after="running")


def thaw_unmanaged(host, instance_id, relative, result):
    """The resume of a container that the kernel holds frozen while Incus calls it running: no memory call and no Incus call (Incus
    refuses to unfreeze a container it thinks runs). The helper thaws the container's own cgroup within THAW_SECONDS, drops the plan
    as stale and closes the swap the sleep opened, as any resume does. One that stays frozen fails `instance.container_frozen_unmanaged`
    and keeps its files, so a later wake tries again."""
    base = host.instance_dir(instance_id)
    result = dict(result, kernel_frozen=True)
    resuming = time.monotonic()
    if not thaw(host, relative, THAW_SECONDS):
        return dict(result, outcome="failed", error_code=UNMANAGED, state_after="frozen")
    result["seconds"]["resume"] = round(time.monotonic() - resuming, 3)
    remove(os.path.join(base, "plan"))
    result["prepared_restore"] = skipped("plan_stale")
    closing = time.monotonic()
    result["swap_subtree"] = close_swap(host, instance_id, relative)
    remove(os.path.join(base, "sleep.json"))
    result["seconds"]["close"] = round(time.monotonic() - closing, 3)
    return dict(result, outcome="resumed", state_after="running")


def residual(host, instance_id, operation_id):
    """Read the frozen container's memory.stat once and store its anonymous and file bytes in sleep.json of the sleep
    `operation_id`. The sleep phase calls it until both are 0; Ansible owns that wait. It changes nothing when the container is not
    frozen or sleep.json belongs to another sleep."""
    target = read_target(host, instance_id)
    if target is None:
        raise Refused("no target descriptor")
    project, name = target["incus_project"], target["incus_name"]
    live = host.incus.instance(project, name)
    state = "missing" if live is None or live["incarnation"] != target["incarnation"] else live["status"]
    result = {"outcome": "not_recorded", "error_code": None, "state_before": state, "state_after": state,
              "prepared_restore": None}
    path = os.path.join(host.instance_dir(instance_id), "sleep.json")
    sleep = read_json(path, MAX_INPUT * 4)
    if state != "frozen" or not isinstance(sleep, dict) or sleep.get("sleep_operation_id") != operation_id or \
            not isinstance(sleep.get("prepared_restore"), dict):
        return result
    stat = read_value(os.path.join(host.cgroups, host.container_cgroup(project, name), "memory.stat"))
    counters = dict(line.split() for line in stat.splitlines() if line.count(" ") == 1)
    prepared = dict(sleep["prepared_restore"], residual_anon_bytes=int(counters["anon"]),
                    residual_file_bytes=int(counters["file"]))
    write_json(path, dict(sleep, prepared_restore=prepared))
    return dict(result, outcome="recorded", prepared_restore=prepared)


def discard(host, instance_id, plan_only):
    base = host.instance_dir(instance_id)
    if plan_only:
        removed = os.path.lexists(os.path.join(base, "plan"))
        remove(os.path.join(base, "plan"))
        return {"outcome": "discarded", "removed": removed}
    removed = os.path.lexists(base)
    remove(base)
    pattern = rf"app-instance-{instance_id}-op-[1-9][0-9]*\.[a-z-]+"
    for folder in (os.path.join(host.state, "outcomes"), host.requests):
        try:
            names = os.listdir(folder)
        except FileNotFoundError:
            continue
        for name in names:
            if re.fullmatch(pattern, name):
                removed = True
                remove(os.path.join(folder, name))
    # `removed` says whether anything was there, so a repeated destroy reports no change.
    return {"outcome": "discarded", "removed": removed}


# ---------------------------------------------------------------------------------------------------------------------------
# Entry


def parse_args(argv):
    """Exactly the documented shapes; ids are strictly positive decimals."""
    def number(value):
        if not ID.match(value or ""):
            raise Refused("id")
        return int(value)

    if argv == ["capabilities"]:
        return "capabilities", None, None, False
    if len(argv) == 5 and argv[0] in ("prepare", "restore", "residual") and argv[1] == "--instance" and argv[3] == "--operation":
        return argv[0], number(argv[2]), number(argv[4]), False
    if argv[:2] == ["discard", "--instance"] and len(argv) in (3, 4) and (len(argv) == 3 or argv[3] == "--plan-only"):
        return "discard", number(argv[2]), None, len(argv) == 4
    raise Refused("arguments")


def own_unit(proc="/proc"):
    with open(os.path.join(proc, "self/cgroup")) as handle:
        for line in handle:
            if line.startswith("0::"):
                return line.strip().rsplit("/", 1)[-1]
    return None


def run(host, argv, stdin, unit):
    command, instance_id, operation_id, plan_only = parse_args(argv)
    if unit != (f"orbit-instance-power-{instance_id}.service" if command != "capabilities" else "orbit-instance-power-probe.service"):
        raise Refused("outside its unit")
    if command == "capabilities":
        body = capabilities(host)
    elif command == "discard":
        body = discard(host, instance_id, plan_only)
    elif command == "residual":
        body = residual(host, instance_id, operation_id)
    else:
        raw = stdin.read(MAX_INPUT + 1) if stdin is not None else b""
        intent = parse_intent(raw, instance_id, operation_id) if raw.strip() or command == "prepare" else None
        os.umask(0o077)
        ensure_dir(os.path.join(host.state, "instances"))
        body = (prepare if command == "prepare" else restore)(host, instance_id, operation_id, intent)
    result = {"schema_version": SCHEMA_VERSION, "command": command, "instance_id": instance_id,
              "operation_id": operation_id, "outcome": None, "error_code": None, **body}
    if command in ("prepare", "restore"):
        write_receipt(host, instance_id, operation_id, result)
        result.pop("counts", None)
    return result


def main(argv=None, host=None, stdin=None, unit=None):
    argv = sys.argv[1:] if argv is None else argv
    try:
        result = run(host or Host(), argv, sys.stdin.buffer if stdin is None else stdin, own_unit() if unit is None else unit)
        code = 0
    except Exception as error:  # noqa: BLE001 - the one top-level handler of the contract
        # The class name only: an exception's text may carry a path or an address.
        print(f"orbit-instance-power: {type(error).__name__}", file=sys.stderr)
        result = {"schema_version": SCHEMA_VERSION, "command": argv[0] if argv else None, "outcome": "failed",
                  "error_code": "instance.power_helper_failed"}
        code = 70
    output = json.dumps(result, sort_keys=True)
    if len(output) > MAX_OUTPUT:
        output = json.dumps({"schema_version": SCHEMA_VERSION, "outcome": "failed", "error_code": "instance.power_helper_failed"})
        code = 70
    print(output)
    return code


if __name__ == "__main__":
    sys.exit(main())
