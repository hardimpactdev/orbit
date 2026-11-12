"""Bounded mail convergence, shared by the Ansible phase and native clone launch.

Two guest entries batch preflight and installation. Network admission always precedes
unit writes. Only public intent and status cross this boundary.
"""

import json
import os
import stat
import subprocess
import sys
import tempfile
from pathlib import Path

PORTS = (1025, 2525)
MARKER = "# Managed by Orbit: development mail.\n"


def command(argv, content=None, public_guest=False):
    result = subprocess.run(
        argv, input=content, text=True, capture_output=True, check=False, timeout=30
    )
    if result.returncode and argv[:2] != ["systemctl", "show"]:
        # Only our guest entry emits these complete public diagnostics. Tool output stays private.
        code = result.stderr.removesuffix("\n")
        raise RuntimeError(
            code
            if public_guest
            and code in ("instance.host_unavailable", "process.target_inactive")
            else "instance.mail_update_failed"
        )
    return result.stdout


def atomic(path, content, mode):
    path.parent.mkdir(parents=True, exist_ok=True, mode=0o700)
    fd, temporary = tempfile.mkstemp(dir=path.parent)
    try:
        with os.fdopen(fd, "w") as stream:
            os.fchmod(stream.fileno(), mode)
            stream.write(content)
        os.replace(temporary, path)
    finally:
        Path(temporary).unlink(missing_ok=True)


def sockets(run):
    names = ["orbit-mail-" + str(p) + ".socket" for p in PORTS]
    output = run(
        [
            "systemctl",
            "show",
            "--property=Id",
            "--property=ActiveState",
            "--property=Listen",
            "--property=UnitFileState",
            "--",
        ]
        + names
    )
    return {
        row.get("Id"): row
        for block in output.strip().split("\n\n")
        if (
            row := dict(
                line.split("=", 1) for line in block.splitlines() if "=" in line
            )
        )
    }


def owns_listener(rows, port):
    row = rows.get("orbit-mail-" + str(port) + ".socket", {})
    return (
        row.get("ActiveState") == "active"
        and row.get("Listen") == "127.0.0.1:" + str(port) + " (Stream)"
    )


def ready(rows, port):
    row = rows.get("orbit-mail-" + str(port) + ".socket", {})
    return owns_listener(rows, port) and row.get("UnitFileState") == "enabled"


def guest(action, plan, root=Path("/"), run=command):
    def path(name):
        return root / name.lstrip("/")

    if not os.access(path("/usr/lib/systemd/systemd-socket-proxyd"), os.X_OK):
        raise RuntimeError("instance.host_unavailable")
    units = plan["mail_units"]
    if action == "inspect":
        changed = []
        for unit in units:
            target = path("/etc/systemd/system/" + unit["name"])
            if target.exists():
                content = target.read_text()
                if not content.startswith(MARKER):
                    return {"status": "skipped", "reason": "mail_unit_conflict"}
                info = target.stat()
                if (
                    content == unit["content"]
                    and info.st_uid == 0
                    and info.st_gid == 0
                    and stat.S_IMODE(info.st_mode) == 0o644
                ):
                    continue
            changed.append(unit["name"])
        rows = sockets(run)
        # Read both ports once; matching local port is parsed from ss's local-address field.
        listeners = run(["ss", "-H", "-ltn", "( sport = :1025 or sport = :2525 )"])
        for line in listeners.splitlines():
            fields = line.split()
            local = fields[3] if len(fields) > 3 else ""
            for port in PORTS:
                if local.rsplit(":", 1)[-1] == str(port) and not owns_listener(
                    rows, port
                ):
                    return {"status": "skipped", "reason": "mail_port_in_use"}
        return {"changed_units": changed, "sockets": rows}
    if action != "install":
        raise RuntimeError("instance.mail_update_failed")
    # The Node saved every pending name BEFORE this first write, including partial earlier attempts.
    for unit in units:
        if unit["name"] in plan["changed_units"]:
            atomic(path("/etc/systemd/system/" + unit["name"]), unit["content"], 0o644)
            os.chown(path("/etc/systemd/system/" + unit["name"]), 0, 0)
    pending = plan["activation_units"]
    if pending:
        run(["systemctl", "daemon-reload"])
        services = [name for name in pending if name.endswith(".service")]
        if services:
            run(["systemctl", "try-restart", "--"] + services)
    drift = [
        "orbit-mail-" + str(port) + ".socket"
        for port in PORTS
        if not ready(plan["sockets"], port)
    ]
    if drift:
        run(["systemctl", "enable", "--now", "--"] + drift)
    installed = sockets(run)
    if not all(ready(installed, port) for port in PORTS):
        raise RuntimeError("process.target_inactive")
    return {"changed": bool(pending or drift)}


def effective_rules(acl):
    # Admission timestamps are ownership metadata, not a change to the usable mail path.
    return sorted(
        json.dumps(
            {k: v for k, v in rule.items() if k != "description" and v != ""},
            sort_keys=True,
        )
        for rule in acl.get("egress", [])
    )


def apply(container, bundle, run=command, state=Path("/var/lib/orbit/instance-mail")):
    if container.get("mail") is None:
        return None
    name = container["name"]
    current = json.loads(
        run(
            [
                "incus",
                "query",
                "--",
                "/1.0/instances/" + name + "?project=" + container["project"],
            ]
        )
    )
    if current["status"] != "Running":
        raise RuntimeError("process.target_inactive")
    exec_argv = [
        "incus",
        "exec",
        "--project",
        container["project"],
        name,
        "--",
        "python3",
        "-c",
        bundle["mail"],
    ]
    probe = json.loads(
        run(exec_argv + ["inspect"], json.dumps(container), public_guest=True)
    )
    if probe.get("status") == "skipped":
        return probe
    bridge_route = (
        "/1.0/network-acls/" + container["network"] + "-egress?project=default"
    )
    before = effective_rules(json.loads(run(["incus", "query", "--", bridge_route])))
    incoming = {
        "network": container["network"],
        "cidr": container["bridge_cidr"],
        "endpoints": [container["mail"]],
        "admitted_at": container["bridge_admitted_at"],
        "baseline": {},
    }
    # The hardened shared helper retains its lock, exact DNAT resolution, persisted tuples and refresh timer.
    # Additive only: no full reconciliation, timer wait or retry loop here.
    run(
        [
            "python3",
            "-c",
            bundle["bridge"],
            "--state-dir",
            "/var/lib/orbit/instance-bridge",
            "--intent",
        ],
        json.dumps(incoming),
    )
    after = effective_rules(json.loads(run(["incus", "query", "--", bridge_route])))
    route = "/1.0/network-acls/" + container["acl"] + "?project=default"
    acl = json.loads(run(["incus", "query", "--", route]))
    rule = {
        "action": "allow",
        "destination": container["mail"]["host"] + "/32",
        "protocol": "tcp",
        "destination_port": str(container["mail"]["port"]),
        "state": "enabled",
        "description": "Orbit development SMTP",
    }
    previous = [r for r in acl["egress"] if r.get("description") == rule["description"]]
    acl_changed = (
        len(previous) != 1 or {k: v for k, v in previous[0].items() if v != ""} != rule
    )
    if acl_changed:
        acl = {k: acl[k] for k in ["description", "ingress", "egress"]}
        acl["egress"] = [
            r for r in acl["egress"] if r.get("description") != rule["description"]
        ] + [rule]
        run(
            [
                "incus",
                "network",
                "acl",
                "edit",
                container["acl"],
                "--project",
                "default",
            ],
            json.dumps(acl),
        )
    pending = state / name / "activation.json"
    names = {unit["name"] for unit in container["mail_units"]}
    activation = sorted(
        (set(json.loads(pending.read_text())) if pending.exists() else set())
        | set(probe["changed_units"])
    )
    activation = [name for name in activation if name in names]
    if activation:
        pending.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        os.chmod(pending.parent, 0o700)
        atomic(pending, json.dumps(activation), 0o600)
    # No guest write before both network gates succeed. An unfinished attempt retains activation.json.
    result = {"changed": False}
    if activation or not all(ready(probe["sockets"], port) for port in PORTS):
        result = json.loads(
            run(
                exec_argv + ["install"],
                json.dumps(container | probe | {"activation_units": activation}),
                public_guest=True,
            )
        )
    pending.unlink(missing_ok=True)
    return {
        "status": "applied"
        if result["changed"] or acl_changed or before != after
        else "unchanged"
    }


def main():
    try:
        if sys.argv[1] in ("inspect", "install"):
            result = guest(sys.argv[1], json.load(sys.stdin))
        else:
            result = apply(json.loads(sys.argv[1]), json.loads(sys.argv[2]))
        print(json.dumps(result))
    except Exception as error:  # noqa: BLE001 - output-free public diagnostic boundary.
        code = str(error)
        print(
            code
            if code in ("instance.host_unavailable", "process.target_inactive")
            else "instance.mail_update_failed",
            file=sys.stderr,
        )
        raise SystemExit(1)


if __name__ == "__main__":
    main()
