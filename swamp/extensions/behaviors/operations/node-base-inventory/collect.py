"""Read the fixed release inventory. Only this module's explicit fields leave the host."""

import datetime
import grp
import hashlib
import hmac
import ipaddress
import json
import os
import platform
import pwd
import re
import stat
import subprocess
import sys
import time

AGENT = "/usr/local/bin/orbit-agent"
SECRET = "/etc/orbit/agent/secret"
EXECUTOR = "/usr/local/libexec/orbit-executor"
AGENT_UNIT = "orbit-agent.service"
EXECUTOR_UNIT = "orbit-executor.service"
SYSCTL = "/etc/sysctl.d/60-orbit-nonlocal-bind.conf"
COMMON_PUBLIC = (
    AGENT, "/etc/orbit/agent/config.toml", "/etc/orbit/agent/ca.pem",
    "/etc/systemd/system/orbit-agent.service", "/etc/modprobe.d/orbit-no-sctp.conf",
    SYSCTL, "/etc/apt/apt.conf.d/20auto-upgrades",
    "/etc/apt/apt.conf.d/52orbit-unattended-upgrades",
    "/etc/apt/apt.conf.d/53orbit-reboot-due", "/etc/needrestart/conf.d/50-orbit.conf",
    "/usr/local/lib/orbit/reboot-due",
)
COMMON_METADATA = (
    "/etc/orbit/agent", SECRET, "/root/.orbit/config.json",
    EXECUTOR, "/run/orbit-executor.sock",
)
SERVICES_PUBLIC = (
    EXECUTOR, "/etc/orbit/executor/gateway.pub", "/etc/orbit/executor/node",
    "/etc/orbit/executor/enabled", "/etc/orbit/executor/restore-threads",
    "/etc/systemd/system/orbit-executor.service", "/etc/sudoers.d/orbit-executor-relay",
    "/etc/systemd/system/orbit-websocket.service",
)
SWAMP_PUBLIC = (
    "/etc/systemd/system/orbit-swamp-worker@.service",
    "/etc/systemd/system/orbit-swamp-serve.service",
)
WORKERS = tuple(f"orbit-swamp-worker@{slot}.service" for slot in "abc")
CONTAINERS = (
    "orbit-process-264-powersync-hauzer-dev",
    "orbit-process-265-powersync-mealou-dev",
    "orbit-process-267-powersync-mealou",
    "orbit-process-268-powersync-hauzer",
)
MANAGED_CLI = r"orbit-0[.][1-9][0-9]{0,9}[.]0"
SYSTEMD_FIELDS = {
    "LoadState": "load", "ActiveState": "active", "UnitFileState": "enabled",
    "SubState": "sub", "MainPID": "pid", "NRestarts": "restarts",
    "ActiveEnterTimestampMonotonic": "start_monotonic_us",
    "ExecMainStartTimestampMonotonic": "main_start_monotonic_us",
    "LastTriggerUSecMonotonic": "last_trigger_monotonic_us",
}
NUMBER_FIELDS = {
    "MainPID", "NRestarts", "ActiveEnterTimestampMonotonic",
    "ExecMainStartTimestampMonotonic", "LastTriggerUSecMonotonic",
}
FIXED_PLATFORM = re.compile(r"[A-Za-z0-9][A-Za-z0-9_.+~-]{0,100}")
LIMIT = 128 * 1024 * 1024
READ_ERROR = {"status": "read_error"}
MISSING = {"status": "missing"}


def valid_text(value, pattern=FIXED_PLATFORM):
    if not isinstance(value, str) or pattern.fullmatch(value) is None:
        raise ValueError
    return value


def observed(read):
    try:
        return {"status": "ok", **read()}
    except FileNotFoundError:
        return MISSING
    except (OSError, ValueError, KeyError, TypeError, IndexError, subprocess.SubprocessError):
        return READ_ERROR


def command(tool, *args, **kwargs):
    options = {
        "capture_output": True, "text": True, "encoding": "utf-8", "timeout": 8,
        "env": {"PATH": "/usr/sbin:/usr/bin:/sbin:/bin", "LC_ALL": "C", **kwargs.pop("env", {})},
        **kwargs,
    }
    try:
        match tool:
            case "systemctl":
                return subprocess.run(["/usr/bin/systemctl", *args], check=False, **options)
            case "dpkg-query":
                return subprocess.run(["/usr/bin/dpkg-query", *args], check=False, **options)
            case "ip":
                return subprocess.run(["/usr/sbin/ip", *args], check=False, **options)
            case "wg":
                return subprocess.run(["/usr/bin/wg", *args], check=False, **options)
            case "nft":
                return subprocess.run(["/usr/sbin/nft", *args], check=False, **options)
            case "docker":
                return subprocess.run(["/usr/bin/docker", *args], check=False, **options)
            case "curl":
                return subprocess.run(["/usr/bin/curl", *args], check=False, **options)
            case "swamp":
                return subprocess.run(["/usr/local/bin/swamp", *args], check=False, **options)
            case "journalctl":
                return subprocess.run(["/usr/bin/journalctl", *args], check=False, **options)
            case _:
                raise ValueError
    except FileNotFoundError as exc:
        raise ValueError from exc


def output(*argv, **kwargs):
    result = command(*argv, **kwargs)
    if result.returncode:
        raise ValueError
    return result.stdout


def safe_open(path, flags):
    """Walk fixed paths without following a substituted directory or final symlink."""
    parts = path.split("/")[1:]
    fd = os.open("/", os.O_RDONLY | os.O_DIRECTORY)
    try:
        for part in parts[:-1]:
            child = os.open(part, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW, dir_fd=fd)
            os.close(fd)
            fd = child
        return os.open(parts[-1], flags | os.O_NOFOLLOW, dir_fd=fd)
    finally:
        os.close(fd)


def regular_file(path):
    fd = safe_open(path, os.O_RDONLY | os.O_NOATIME | os.O_NONBLOCK)
    st = os.fstat(fd)
    if not stat.S_ISREG(st.st_mode) or st.st_uid != 0 or st.st_size > LIMIT:
        os.close(fd)
        raise ValueError
    return os.fdopen(fd, "rb")


def digest(path):
    with regular_file(path) as file:
        if os.fstat(file.fileno()).st_nlink != 1:
            raise ValueError
        return hashlib.file_digest(file, "sha256").hexdigest()


def metadata(path):
    st = os.lstat(path)
    kind = next((name for name, test in (
        ("regular", stat.S_ISREG), ("directory", stat.S_ISDIR),
        ("symlink", stat.S_ISLNK), ("socket", stat.S_ISSOCK),
    ) if test(st.st_mode)), "other")
    try:
        owner = pwd.getpwuid(st.st_uid).pw_name
    except KeyError:
        owner = str(st.st_uid)
    try:
        group = grp.getgrgid(st.st_gid).gr_name
    except KeyError:
        group = str(st.st_gid)
    return {"owner": owner, "group": group, "mode": f"{stat.S_IMODE(st.st_mode):04o}", "type": kind}


def file_observation(path, public, expected):
    entry = observed(lambda: metadata(path))
    if not public or entry["status"] != "ok":
        return entry
    checksum = observed(lambda: {"sha256": digest(path)})
    if "sha256" in checksum and path in expected:
        checksum["matches_intent"] = hmac.compare_digest(checksum["sha256"], expected[path])
    return {**entry, "digest": checksum}


def read_secret_match(verifier):
    with regular_file(SECRET) as file:
        secret = file.read(513)
    if len(secret) > 512:
        raise ValueError
    return {"match": hmac.compare_digest(hashlib.sha256(secret).hexdigest(), verifier)}


def unit(name):
    properties = [f"--property={key}" for key in SYSTEMD_FIELDS]
    result = command("systemctl", "show", *properties, "--", name)
    data = dict(line.split("=", 1) for line in result.stdout.splitlines() if "=" in line)
    if data.get("LoadState") == "not-found":
        raise FileNotFoundError
    if result.returncode or not data.get("LoadState") or not data.get("ActiveState"):
        raise ValueError
    return {
        field: int(data[key]) if key in NUMBER_FIELDS else valid_text(data[key])
        for key, field in SYSTEMD_FIELDS.items() if data.get(key)
    }


def discover_units(patterns):
    result = output(
        "systemctl", "list-units", "--state=active", "--type=service",
        "--plain", "--no-legend", "--no-pager", "--", *patterns,
    )
    names = sorted({line.split()[0] for line in result.splitlines() if line.strip()})
    if len(names) > 128 or any(not any(re.fullmatch(pattern, name) for pattern in patterns.values()) for name in names):
        raise ValueError
    return {"units": {name: observed(lambda n=name: unit(n)) for name in names}}


def running_digest(name, binary, state, expected):
    if state["status"] != "ok":
        return {"status": state["status"]}
    pid = state.get("pid", 0)
    if pid <= 0:
        return MISSING

    def read():
        proc = f"/proc/{pid}/exe"
        permitted = (binary, binary + " (deleted)")
        if os.readlink(proc) not in permitted:
            raise ValueError
        with open(proc, "rb") as executable:
            if os.fstat(executable.fileno()).st_size > LIMIT:
                raise ValueError
            sha256 = hashlib.file_digest(executable, "sha256").hexdigest()
        fresh = unit(name)
        if fresh.get("pid") != pid or fresh.get("main_start_monotonic_us") != state.get("main_start_monotonic_us"):
            raise ValueError
        return {"sha256": sha256, **({"matches_intent": sha256 == expected} if expected else {})}

    return observed(read)


def cli():
    path = "/usr/local/bin/orbit"
    entry = observed(lambda: metadata(path))
    if entry.get("type") == "symlink":
        link = observed(lambda: {"target": os.readlink(path)})
        if link["status"] != "ok":
            entry["link"] = link
        else:
            target = link["target"]
            managed = re.fullmatch(rf"(?:/usr/local/bin/)?{MANAGED_CLI}", target) is not None
            entry["link"] = {"status": "ok", "managed": managed, **({"target": target} if managed else {})}

    def managed_files():
        names = sorted(name for name in os.listdir("/usr/local/bin") if re.fullmatch(MANAGED_CLI, name))
        if len(names) > 64:
            raise ValueError
        paths = ["/usr/local/bin/" + name for name in names]
        return {"files": {
            path: file_observation(path, True, {}) for path in paths
            if stat.S_ISREG(os.lstat(path).st_mode)
        }}

    return {"link": entry, "versions": observed(managed_files)}


def package(name):
    result = command("dpkg-query", "--show", "--showformat=${db:Status-Status}\t${Version}", name)
    if result.returncode == 1 and result.stderr.strip() == f"dpkg-query: no packages found matching {name}":
        raise FileNotFoundError
    state, version = result.stdout.strip().split("\t")
    if result.returncode:
        raise ValueError
    return {"installed": state == "installed", "state": valid_text(state), "version": valid_text(version, re.compile(r"[A-Za-z0-9.+~:-]{1,100}"))}


def kernel_policy():
    def effective():
        with open("/proc/sys/net/ipv4/ip_nonlocal_bind", encoding="ascii") as file:
            value = file.read(16).strip()
        if value not in ("0", "1"):
            raise ValueError
        return {"value": int(value), "matches_intent": value == "1"}

    def recorded():
        with regular_file(SYSCTL) as file:
            matches = re.findall(rb"^\s*net[.]ipv4[.]ip_nonlocal_bind\s*=\s*([01])\s*$", file.read(8193), re.MULTILINE)
        if len(matches) != 1:
            raise ValueError
        return {"value": int(matches[0]), "matches_intent": matches[0] == b"1"}

    return {"effective": observed(effective), "recorded": observed(recorded)}


def normalized_digest(value):
    return {"sha256": hashlib.sha256(json.dumps(value, sort_keys=True, separators=(",", ":")).encode()).hexdigest()}


def normalized_wireguard():
    links = json.loads(output("ip", "-j", "-details", "link", "show", "dev", "orbit"))
    if len(links) != 1 or links[0].get("linkinfo", {}).get("info_kind") != "wireguard":
        raise ValueError
    routes = []
    for family in ("-4", "-6"):
        values = json.loads(output("ip", "-j", family, "route", "show", "table", "all", "dev", "orbit"))
        routes.extend(sorted([
            {key: value for key, value in route.items()
             if key in {"dst", "gateway", "prefsrc", "table", "scope", "protocol", "metric", "type"}}
            for route in values
        ], key=lambda row: json.dumps(row, sort_keys=True)))
    addresses = json.loads(output("ip", "-j", "address", "show", "dev", "orbit"))
    local = sorted((row["family"], row["local"], row["prefixlen"]) for row in addresses[0]["addr_info"])
    def peer_values(field):
        return {
            valid_text(key, re.compile(r"[A-Za-z0-9+/]{43}=")): value
            for key, value in (line.split(None, 1) for line in
                               output("wg", "show", "orbit", field).splitlines())
        }

    # Only public fields: no dump, showconf or private key read.
    local_key = valid_text(output("wg", "show", "orbit", "public-key").strip(),
                           re.compile(r"[A-Za-z0-9+/]{43}="))
    port = int(output("wg", "show", "orbit", "listen-port").strip())
    allowed = peer_values("allowed-ips")
    keepalive = peer_values("persistent-keepalive")
    endpoints = peer_values("endpoints")
    if allowed.keys() != keepalive.keys() or allowed.keys() != endpoints.keys():
        raise ValueError
    peers = {}
    for key, networks in allowed.items():
        peers[key] = [
            sorted(str(ipaddress.ip_network(network)) for network in networks.replace(",", " ").split()
                   if network != "(none)"),
            0 if keepalive[key] == "off" else int(keepalive[key]),
            endpoints[key],
        ]
    return normalized_digest([local_key, port, links[0]["mtu"], "UP" in links[0]["flags"], local, routes, peers])


def firewall():
    def normalize(value):
        if isinstance(value, dict):
            return {key: normalize(item) for key, item in value.items() if key not in
                    {"handle", "packets", "bytes", "expires", "lastused", "position"}}
        if isinstance(value, list):
            return [normalize(item) for item in value]
        return value

    rows = json.loads(output("nft", "--json", "list", "ruleset"))["nftables"]
    return normalized_digest([normalize(row) for row in rows if "metainfo" not in row])


def caddy_publication():
    path = os.path.realpath("/etc/caddy/Caddyfile", strict=True)
    if not path.startswith("/etc/caddy/"):
        raise ValueError
    with regular_file(path) as file:
        if os.fstat(file.fileno()).st_nlink != 1:
            raise ValueError
        if file.readline(256).strip() != b"# Managed by Orbit: Node Caddy build":
            raise ValueError
        file.seek(0)
        return {"sha256": hashlib.file_digest(file, "sha256").hexdigest()}


def monotonic_ticks(pid):
    with open(f"/proc/{pid}/stat", encoding="utf-8") as file:
        return int(file.read(8192).rsplit(") ", 1)[1].split()[19])


def container(name):
    # Docker evaluates this fixed template. No Config, Env, Args or Mounts enter the receipt.
    fields = "{{json .Id}}\t{{json .State.Status}}\t{{json .State.Running}}\t{{json .State.Pid}}\t{{json .State.StartedAt}}\t{{json .RestartCount}}"
    result = command("docker", "inspect", "--type=container", "--format", fields, "--", name)
    if result.returncode:
        if result.stderr.strip() in (f"Error: No such container: {name}", f"Error: No such object: {name}"):
            raise FileNotFoundError
        raise ValueError
    ident, state, running, pid, started, restarts = (json.loads(field) for field in result.stdout.strip().split("\t"))
    valid_text(ident, re.compile("[a-f0-9]{64}"))
    valid_text(started, re.compile(r"[0-9TZ:+.-]{20,42}"))
    if type(running) is not bool or type(pid) is not int or pid < 0 or type(restarts) is not int or restarts < 0:
        raise ValueError
    return {
        "id": ident, "state": valid_text(state), "running": running,
        "pid": pid, "started_at": started, "restarts": restarts,
        "start_ticks": observed(lambda: {"value": monotonic_ticks(pid)}) if pid else MISSING,
    }


def probe(port, route, *, any_response=False):
    if not 1 <= port <= 65535:
        raise ValueError
    status = int(output(
        "curl", "--silent", "--output", "/dev/null", "--write-out", "%{http_code}",
        "--noproxy", "*", "--max-time", "5", f"http://127.0.0.1:{port}{route}",
    ))
    return {"ready": (100 <= status < 600) if any_response else (200 <= status < 300)}


def worker_pool():
    # The same driver token and read method used by the shipped worker renewal play.
    home = "/home/orbit"
    config = home + "/.config/swamp"
    token = config + "/serve-driver-token"
    if not stat.S_ISREG(os.stat(token).st_mode):
        raise ValueError
    worker_env = {
        "HOME": home, "SWAMP_HOME": home + "/.swamp", "SWAMP_CONFIG_DIR": config,
        "SWAMP_API_KEY_FILE": config + "/api-key", "SWAMP_NO_UPDATE_CHECK": "1", "SWAMP_NO_TELEMETRY": "1",
        "SWAMP_NO_INBOX_NOTICE": "1",
    }
    workers = json.loads(output(
        "swamp", "worker", "list", "--all", "--server", "http://127.0.0.1:9418",
        "--token-file", token, "--json", env=worker_env, user="orbit", group="orbit", extra_groups=[],
    ))["workers"]
    slots = {}
    for slot, service in zip("abc", WORKERS, strict=True):
        def read(unit_name=service):
            log = command("journalctl", "--boot=0", "--unit=" + unit_name,
                          "--grep=Enrolled as ", "--lines=1", "--output=json", "--no-pager", "--quiet")
            if log.returncode in (0, 1) and not log.stdout.strip() and not log.stderr.strip():
                raise FileNotFoundError
            if log.returncode:
                raise ValueError
            row = json.loads(log.stdout)
            match = re.search(r'Enrolled as "?([^"\s]+)', re.sub(r"\x1b\[[0-9;]*m", "", row["MESSAGE"]))
            if not match:
                raise ValueError
            entries = [worker for worker in workers if worker["name"] == match[1]]
            if not entries:
                raise FileNotFoundError
            if len(entries) != 1:
                raise ValueError
            worker = entries[0]
            return {"state": valid_text(worker["status"]), "connected_at_us": int(row["__REALTIME_TIMESTAMP"]),
                    "capacity": int(worker["capacity"]), "has_instance": bool(worker.get("instanceUuid"))}
        slots[slot] = observed(read)
    return {"slots": slots}


def account(name, *, group=False):
    try:
        record = grp.getgrnam(name) if group else pwd.getpwnam(name)
    except KeyError as exc:
        raise FileNotFoundError from exc
    return {"id": record.gr_gid if group else record.pw_uid,
            **({} if group else {"primary_gid": record.pw_gid})}


def snapshot(node_id, profile, expected, verifier, websocket_port, backing):
    public = COMMON_PUBLIC + {"router": (), "services": SERVICES_PUBLIC, "swamp": SWAMP_PUBLIC}[profile]
    metadata_only = list(COMMON_METADATA)
    units = [AGENT_UNIT, EXECUTOR_UNIT, "apt-daily.timer", "apt-daily-upgrade.timer", "wg-quick@orbit.service"]
    extra = {}
    if profile == "router":
        units += ["caddy.service", "orbit-node-worker.service", "docker.service", "containerd.service", "incus.service",
                  "dnsmasq.service", "systemd-resolved.service", "orbit-cluster-dns.service"]
        extra = {
            "active_services": observed(lambda: discover_units({
                "php*-fpm.service": r"php[0-9.]+-fpm[.]service",
                "orbit-process-*.service": r"orbit-process-[0-9]+-[A-Za-z0-9_.@-]+[.]service",
                "incus*.service": r"incus[A-Za-z0-9_.@-]*[.]service",
                "orbit-*-dns*.service": r"orbit-[A-Za-z0-9_.@-]*-dns[A-Za-z0-9_.@-]*[.]service",
                "orbit-*-ingress*.service": r"orbit-[A-Za-z0-9_.@-]*-ingress[A-Za-z0-9_.@-]*[.]service",
            })),
            "wireguard": observed(normalized_wireguard), "firewall": observed(firewall),
            "publication": observed(caddy_publication),
        }
    elif profile == "services":
        units += ["docker.service", "containerd.service", "orbit-websocket.service"]
        metadata_only += ["/usr/local/libexec", "/etc/orbit/executor", "/var/lib/orbit/executor",
                          "/var/lib/orbit/executor/journal"]
        extra = {
            "powersync": {name: observed(lambda n=name: container(n)) for name in CONTAINERS},
            "active_services": observed(lambda: discover_units({
                "postgresql*.service": r"postgresql[A-Za-z0-9_.@-]*[.]service",
                "mysql*.service": r"mysql[A-Za-z0-9_.@-]*[.]service",
                "clickhouse*.service": r"clickhouse[A-Za-z0-9_.@-]*[.]service",
                "plausible*.service": r"plausible[A-Za-z0-9_.@-]*[.]service",
            })),
            "backing_containers": {name: observed(lambda n=name: container(n)) for name in backing},
            "websocket_ready": observed(lambda: probe(websocket_port, "/", any_response=True)),
            "executor_relay_user": observed(lambda: account("orbit-executor-relay")),
            "executor_relay_group": observed(lambda: account("orbit-executor-relay", group=True)),
        }
    else:
        units += ["orbit-swamp-serve.service", *WORKERS, "orbit-swamp-worker-renewal.service",
                  "orbit-swamp-worker-renewal.timer"]
        extra = {
            "serve_ready": observed(lambda: probe(9418, "/ready")),
            "worker_pool": observed(worker_pool),
        }

    states = {name: observed(lambda n=name: unit(n)) for name in units}
    files = {path: file_observation(path, path in public, expected) for path in sorted(set(public) | set(metadata_only))}
    return {
        "node_id": node_id, "profile": profile, "captured_at": datetime.datetime.now(datetime.UTC).isoformat(),
        "os": observed(lambda: {"id": valid_text(platform.freedesktop_os_release()["ID"]),
                                "release": valid_text(platform.freedesktop_os_release()["VERSION_ID"])}),
        "architecture": valid_text(platform.machine()), "kernel": valid_text(platform.release()),
        "boot_id": observed(lambda: {"value": valid_text(
            read_proc("/proc/sys/kernel/random/boot_id"), re.compile(r"[a-f0-9-]{36}"))}),
        "uptime": observed(lambda: {"seconds": int(float(read_proc("/proc/uptime").split()[0]))}),
        "files": files, "units": states, "cli": cli(), "sysctl": kernel_policy(),
        "packages": {name: observed(lambda n=name: package(n)) for name in ("unattended-upgrades", "needrestart")},
        "apt_cache": observed(lambda: {"age_seconds": max(0, int(time.time() - os.stat(
            "/var/lib/apt/periodic/update-success-stamp").st_mtime))}),
        "orbit_env_group": observed(lambda: account("orbit-env", group=True)),
        "agent_secret_match": observed(lambda: read_secret_match(verifier)),
        "agent_running": running_digest(AGENT_UNIT, AGENT, states[AGENT_UNIT], expected.get(AGENT)),
        "executor_running": running_digest(EXECUTOR_UNIT, EXECUTOR, states[EXECUTOR_UNIT], expected.get(EXECUTOR)),
        **extra,
    }


def read_proc(path):
    with open(path, encoding="ascii") as file:
        return file.read(128).strip()


if __name__ == "__main__":
    try:
        private = json.loads(sys.stdin.read(32769))
        admitted = private["admission"]
        node_id, operation_id = map(int, sys.argv[1:])
        if admitted["node_id"] != node_id or admitted["operation_id"] != operation_id:
            raise ValueError
        verifier = valid_text(private["verifier"], re.compile("[a-f0-9]{64}"))
        backing = admitted["backing_containers"]
        if len(backing) > 64 or any(not re.fullmatch(r"orbit-process-[1-9][0-9]*-[a-z0-9-]+", name)
                                    for name in backing):
            raise ValueError
        report = snapshot(node_id, admitted["profile"], admitted["expected"], verifier, admitted["websocket_port"], backing)
        print(json.dumps(report, separators=(",", ":")))
    except (OSError, ValueError, IndexError, KeyError, TypeError):
        sys.exit("node.base_inventory_read_error")
