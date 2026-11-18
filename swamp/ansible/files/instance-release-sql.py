#!/usr/bin/python3
"""Project admitted SQL listeners into one unprivileged release guest, then authenticate from that guest."""
import ipaddress
import json
import re
import subprocess

PROJECT = "orbit-releases"
PREFIX = "orbit-sql-"
GRANTS = "user.orbit.release.sql-grants"
NETWORKS = tuple(map(ipaddress.IPv4Network, ("10.0.0.0/8", "172.16.0.0/12", "192.168.0.0/16")))
PROBE = """
try {
    $c = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $dsn = $c['driver'].':host='.$c['listen'].';port='.$c['port'].';dbname='.$c['database'];
    if ($c['driver'] === 'pgsql') $dsn .= ';connect_timeout=4';
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 4];
    $pdo = new PDO($dsn, $c['username'], $c['password'], $options);
    exit((int) $pdo->query('SELECT 1')->fetchColumn() === 1 ? 0 : 1);
} catch (Throwable $failure) {}
exit(1);
"""


def incus(*args, data=None, timeout=30):
    project = [] if args[0] == "query" else ["--project", PROJECT]
    result = subprocess.run(["incus", "--force-local", *project, *args], input=data,
                            stdin=subprocess.DEVNULL if data is None else None,
                            capture_output=True, text=True, timeout=timeout, check=False)
    if result.returncode:
        raise ValueError("deployment.database_unreachable: could not verify the attached SQL endpoint")
    return result.stdout


def grants(rows):
    if not isinstance(rows, list):
        raise ValueError("invalid production Database grants")
    devices = {}
    for row in rows:
        target, port = row["target_id"], row["port"]
        if type(target) is not int or target < 1 or type(port) is not int or not 1 <= port <= 65535:
            raise ValueError("invalid SQL attachment")
        host, listen = map(ipaddress.IPv4Address, (row["host"], row["listen"]))
        expected = ipaddress.IPv4Address(0x7f000000 | ((0x400000 + target) & 0xffffff))
        if not any(host in network for network in NETWORKS) or listen != expected:
            raise ValueError("invalid private SQL endpoint")
        if (row["driver"] not in ("pgsql", "mysql") or not re.fullmatch(r"[A-Za-z0-9_.$-]{1,128}", row["database"])
                or not isinstance(row["username"], str) or not row["username"]
                or not isinstance(row["password"], str) or not row["password"]):
            raise ValueError("invalid SQL login")
        key = PREFIX + str(target)
        spec = {"type": "proxy", "bind": "instance", "listen": f"tcp:{listen}:{port}", "connect": f"tcp:{host}:{port}"}
        if key in devices or any(device["listen"] == spec["listen"] for device in devices.values()):
            raise ValueError("duplicate SQL attachment")
        devices[key] = spec
    return devices


def approved_devices(record):
    admitted = json.loads(record["config"].get(GRANTS, "{}"))
    existing = {name: spec for name, spec in record.get("expanded_devices", record["devices"]).items() if name.startswith(PREFIX)}
    for name, spec in existing.items():
        if not re.fullmatch(r"orbit-sql-[1-9][0-9]*", name) or spec not in admitted.get(name, []):
            raise ValueError("unrecognized production SQL device")
        listen = re.fullmatch(r"tcp:([0-9.]+):([1-9][0-9]{0,4})", spec.get("listen", ""))
        remote = re.fullmatch(r"tcp:([0-9.]+):([1-9][0-9]{0,4})", spec.get("connect", ""))
        target = int(name.removeprefix(PREFIX))
        if (set(spec) != {"type", "bind", "listen", "connect"} or spec["type"] != "proxy" or spec["bind"] != "instance"
                or not listen or not remote or listen[2] != remote[2] or int(remote[2]) > 65535
                or ipaddress.IPv4Address(listen[1]) != ipaddress.IPv4Address(0x7f000000 | ((0x400000 + target) & 0xffffff))
                or not any(ipaddress.IPv4Address(remote[1]) in network for network in NETWORKS)
                or record["devices"].get(name) != spec):
            raise ValueError("unrecognized SQL proxy shape")
    return existing


def reconcile(instance, machine, php, rows, *, verify_only=False, run=incus):
    if (not re.fullmatch(r"[1-9][0-9]*", str(instance))
            or not re.fullmatch(rf"prepare-instance-{instance}|instance-{instance}-[0-9a-f]{{12}}", machine)
            or not re.fullmatch(r"[0-9]+[.][0-9]+", php)):
        raise ValueError("invalid production SQL target")
    desired = grants(rows)
    record = json.loads(run("query", f"/1.0/instances/{machine}?project={PROJECT}"))
    if record["config"].get("user.orbit.release.target") != f"instance-{instance}":
        raise ValueError("production SQL target ownership changed")
    existing = approved_devices(record)
    if verify_only and existing != desired:
        raise ValueError("deployment.database_endpoint_changed: deploy the changed SQL route before syncing this environment")
    def record_grants(admitted):
        value = json.dumps(admitted, separators=(",", ":"), sort_keys=True)
        if value != record["config"].get(GRANTS, "{}"):
            run("config", "set", machine, GRANTS + "=" + value)
            record["config"][GRANTS] = value

    if not verify_only and existing != desired:
        # Record both approved shapes before hotplug so a retry recognizes either side of an interruption.
        pending = {name: [spec] for name, spec in existing.items()}
        for name, spec in desired.items():
            if spec not in pending.setdefault(name, []):
                pending[name].append(spec)
        record_grants(pending)
    for name in existing:
        if existing[name] != desired.get(name):
            run("config", "device", "remove", machine, name)
    for name, spec in desired.items():
        if existing.get(name) != spec:
            run("config", "device", "add", machine, name, "proxy", *[key + "=" + val for key, val in spec.items() if key != "type"])
    if not verify_only:
        record_grants({name: [spec] for name, spec in desired.items()})
    for row in rows:
        run("exec", machine, "--", "runuser", "-u", "orbit", "--", "/opt/orbit-runtime/current/php/" + php + "/bin/php",
            "-d", "display_errors=0", "-d", "log_errors=0", "-r", PROBE, data=json.dumps(row), timeout=20)
