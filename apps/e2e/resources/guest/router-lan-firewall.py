#!/usr/bin/env python3
"""Fixed lease-scoped guest control for the TASK-1791 firewall acceptance fixture.

Invoked only by the harness after fresh Incus ownership checks. No shell, arbitrary
command/rule/path, install, host-network operation or product DNS change is exposed.
"""
import base64
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shutil
import shlex
import stat
import subprocess
import sys
import time

ISSUE = "TASK-1791"
ATTEMPT = "8b819011a28fd78fbb64149fa1b814ae"
OWNER = f"orbit-e2e:{ISSUE}:{ATTEMPT}:router-lan-firewall"
MOUNT = Path("/home/orbit/orbit")
ROOT = Path(f"/var/lib/orbit-e2e/router-lan-firewall-{ATTEMPT}")
BRIDGE, VETH, PEER, NAMESPACE = "ofw8b8190br", "ofw8b8190host", "ofw8b8190peer", "ofw8b8190client"
BRIDGE_IP, CLIENT_IP = "198.18.179.1", "198.18.179.2"
UNITS = {kind: f"orbit-e2e-8b819011-{kind}.service" for kind in ("dns", "https")}
USER_FILES = {"/etc/ufw/user.rules", "/etc/ufw/user6.rules"}


def run(argv, check=True, timeout=20):
    result = subprocess.run(argv, capture_output=True, text=True, timeout=timeout)
    if check and result.returncode:
        raise RuntimeError(f"{argv[0]} exited {result.returncode}: {result.stdout}{result.stderr}")
    return result


def canonical_runtime(text):
    return re.sub(r"(?m)^(:\S+\s+\S+\s+)\[\d+:\d+\]$", r"\1[0:0]", "\n".join(
        line for line in text.splitlines() if not line.startswith("#")))


def inventory_key(snapshot):
    files = {}
    for path, value in snapshot["persistent"].items():
        data = base64.b64decode(value["data"])
        if path in USER_FILES:
            data = re.sub(rb"(?m)^# (?:Updated|Created) on.*\n?", b"", data)
        files[path] = [base64.b64encode(data).decode(), value["mode"], value["uid"], value["gid"]]
    return {"runtime": {k: canonical_runtime(v) for k, v in snapshot["runtime"].items()},
            "persistent": files, "status": snapshot["status"], "added": snapshot["added"]}


def namespace_identity():
    path = Path(f"/run/netns/{NAMESPACE}")
    if not path.exists():
        return None
    info = path.stat()
    return [info.st_dev, info.st_ino]


def rule_arguments(kind):
    if kind not in ("bridge-udp", "bridge-tcp", "overlap-udp", "overlap-tcp"):
        raise RuntimeError("Unknown fixture rule.")
    proto = "udp" if kind.endswith("udp") else "tcp"
    comment = f"orbit-e2e:1791:8b819011:{kind}"
    if kind.startswith("bridge-"):
        return ["allow", "in", "on", BRIDGE, "proto", proto, "from", CLIENT_IP,
                "to", BRIDGE_IP, "port", "53", "comment", comment]
    return ["allow", "in", "proto", proto, "from", "0.0.0.0/0", "to", "0.0.0.0/0",
            "port", "53", "comment", comment]


def added_rule(kind):
    proto = "udp" if kind.endswith("udp") else "tcp"
    if kind.startswith("bridge-"):
        return ["ufw", "allow", "in", "on", BRIDGE, "from", CLIENT_IP, "to", BRIDGE_IP, "port", "53", "proto", proto]
    return ["ufw", "allow", "53/" + proto]


def owned_number(snapshot, kind):
    comment = rule_arguments(kind)[-1]
    matches = []
    for line in snapshot["status"].splitlines():
        body, separator, observed = line.partition("#")
        number = re.match(r"^\[\s*(\d+)\]", body)
        if separator and observed.strip() == comment and number:
            matches.append(number.group(1))
    if len(matches) != 1:
        raise RuntimeError("Owned rule comment is absent or ambiguous; mutation refused.")
    return matches[0]


class Fixture:
    def __init__(self, plan):
        self.plan = plan
        self.state = None

    def guard(self):
        if (self.plan.get("issue") != ISSUE or self.plan.get("attempt") != ATTEMPT
                or [self.plan.get(k) for k in ("router", "member", "nonmember", "vpn")]
                != ["10.232.1.11", "10.232.1.12", "10.232.1.14", "10.44.0.1"]):
            raise RuntimeError("Fixture scope is not the authorized retained attempt.")
        lease = json.loads((MOUNT / ".e2e/attempt.json").read_text())
        topology = json.loads((MOUNT / ".e2e/topology.json").read_text())
        token = hashlib.sha256((MOUNT / ".e2e/mount-token").read_bytes()).hexdigest()
        if (lease != self.plan["lease"] or lease["issue"] != ISSUE or lease["attempt_id"] != ATTEMPT
                or token != self.plan["mount_hash"] or topology["attempt_id"] != ATTEMPT
                or topology["instances"]["app-dev"] != self.plan["instance"]):
            raise RuntimeError("Lease or mounted source identity changed; mutation refused.")
        addresses = json.loads(run(["ip", "-j", "-4", "address", "show"]).stdout)
        if not any(a.get("local") == self.plan["router"] for link in addresses for a in link.get("addr_info", [])):
            raise RuntimeError("This is not the leased Router network namespace.")
        for path in (ROOT.parent, ROOT):
            if path.exists() or path.is_symlink():
                info = path.lstat()
                if not stat.S_ISDIR(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o022:
                    raise RuntimeError("Unsafe or foreign fixture directory.")
        if self.state is not None:
            if self.state["owner"] != OWNER:
                raise RuntimeError("Foreign resource journal.")
            for name, identity in self.state["resources"].items():
                if name in (BRIDGE, VETH) and identity == "owned":
                    link = Path("/sys/class/net") / name
                    if link.exists() and (link / "ifalias").read_text().strip() != OWNER:
                        raise RuntimeError("Nested link ownership changed.")
                elif name == NAMESPACE and isinstance(identity, list):
                    observed = namespace_identity()
                    if observed is not None and observed != identity:
                        raise RuntimeError("Nested namespace ownership changed.")
                elif name in UNITS.values():
                    loaded = run(["systemctl", "show", name, "--property=LoadState", "--value"], check=False).stdout.strip()
                    if loaded != "not-found" and run(["systemctl", "show", name, "--property=Description", "--value"]).stdout.strip() != OWNER:
                        raise RuntimeError("Endpoint unit ownership changed.")

    def mutate(self, argv):
        self.guard()
        if argv[0] == "ufw":
            self.assert_expected()
        return run(argv)

    def save(self):
        self.guard()
        temporary = ROOT / "state.next"
        fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
        with os.fdopen(fd, "w") as stream:
            json.dump(self.state, stream, sort_keys=True)
            stream.flush()
            os.fsync(stream.fileno())
        self.guard()
        os.replace(temporary, ROOT / "state.json")

    def snapshot(self):
        persistent = {}
        paths = [p for p in Path("/etc/ufw").rglob("*") if not p.is_dir()] + [Path("/etc/default/ufw")]
        for path in sorted(paths):
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode):
                raise RuntimeError(f"Nonregular UFW file: {path}")
            data = path.read_bytes()
            persistent[str(path)] = {"data": base64.b64encode(data).decode(), "sha256": hashlib.sha256(data).hexdigest(),
                                     "mode": stat.S_IMODE(info.st_mode), "uid": info.st_uid,
                                     "gid": info.st_gid, "mtime_ns": info.st_mtime_ns}
        return {"runtime": {cmd: run([cmd]).stdout for cmd in ("iptables-save", "ip6tables-save")},
                "persistent": persistent, "status": run(["ufw", "status", "numbered"]).stdout,
                "added": run(["ufw", "show", "added"]).stdout}

    def assert_expected(self):
        if inventory_key(self.snapshot()) != inventory_key(self.state["expected"]):
            raise RuntimeError("Unexpected firewall changes; refusing to overwrite intervening work.")
        if self.plan["operator_rules"] != self.state["operator_rules"]:
            raise RuntimeError("Operator records changed; restoration requires ownership review.")

    def rule(self, kind, add):
        self.assert_expected()
        before = self.snapshot()
        if add:
            for line in before["added"].splitlines():
                tokens = shlex.split(line)
                if "comment" in tokens:
                    tokens = tokens[:tokens.index("comment")]
                if tokens == added_rule(kind):
                    raise RuntimeError("Existing rule has the same shape; fixture adoption refused.")
            argv = ["ufw", *rule_arguments(kind)]
        else:
            argv = ["ufw", "--force", "delete", owned_number(before, kind)]
        self.state["intent"] = ["add" if add else "delete", kind]
        self.save()
        self.mutate(argv)
        after = self.snapshot()
        if add:
            owned_number(after, kind)  # A skipped duplicate must never become fixture-owned.
            self.state["rules"].append(kind)
        else:
            if rule_arguments(kind)[-1] in after["status"]:
                raise RuntimeError("Owned rule survived deletion; retain diagnostics.")
            self.state["rules"].remove(kind)
        self.state["expected"] = after
        self.state["intent"] = None
        self.save()

    def preflight(self):
        for tool in ("dnsmasq", "openssl", "ip", "dig", "curl", "ufw", "iptables-save", "ip6tables-save", "systemd-run"):
            if shutil.which(tool) is None:
                raise RuntimeError(f"Missing preinstalled tool {tool}; no install is permitted.")
        for flags in ("-ltn", "-lun"):
            for line in run(["ss", "-H", flags]).stdout.splitlines():
                endpoint = line.split()[3]
                address, port = endpoint.rsplit(":", 1)
                if port in ("53", "443") and address.strip("[]") in ("*", "::", "0.0.0.0", self.plan["router"], BRIDGE_IP):
                    raise RuntimeError(f"Bind overlap at {endpoint}; existing services are not moved or restarted.")
        links = json.loads(run(["ip", "-j", "link", "show"]).stdout)
        if any(link["ifname"] in (BRIDGE, VETH, PEER) for link in links) or namespace_identity() is not None:
            raise RuntimeError("Nested network resource already exists; cannot adopt it.")
        subnet = ipaddress.ip_network("198.18.179.0/30")
        for route in json.loads(run(["ip", "-j", "-4", "route", "show", "table", "all"]).stdout):
            destination = route.get("dst", "default")
            if destination != "default" and subnet.overlaps(ipaddress.ip_network(destination, strict=False)):
                raise RuntimeError("Nested bridge address overlaps an existing route.")
        for unit in UNITS.values():
            if run(["systemctl", "show", unit, "--property=LoadState", "--value"], check=False).stdout.strip() != "not-found":
                raise RuntimeError("Endpoint unit already exists; cannot adopt it.")

    def prepare(self):
        self.preflight()
        baseline = self.snapshot()
        if "Status: active" not in baseline["status"] or "orbit-e2e:1791:8b819011:" in baseline["status"]:
            raise RuntimeError("UFW must be active and free of preceding fixture rules.")
        self.guard()
        if ROOT.exists():
            if self.state is None or self.state["phase"] != "restored":
                raise RuntimeError("Fixture already active or incomplete; inspect/restore first.")
            self.guard()
            ROOT.rename(ROOT.with_name(ROOT.name + f"-history-{time.time_ns()}"))
        self.guard()
        ROOT.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        self.guard()
        ROOT.mkdir(mode=0o700)
        self.state = {"owner": OWNER, "phase": "preparing", "baseline": baseline, "expected": baseline,
                      "operator_rules": self.plan["operator_rules"], "rules": [], "resources": {}, "intent": None}
        self.save()
        for link, kind in ((BRIDGE, "bridge"), (VETH, "veth")):
            args = ["ip", "link", "add", link, "type", kind]
            if kind == "veth":
                args += ["peer", "name", PEER]
            self.state["resources"][link] = "intended"
            self.save()
            self.mutate(args)
            self.mutate(["ip", "link", "set", link, "alias", OWNER])
            self.state["resources"][link] = "owned"
            self.save()
        self.mutate(["ip", "address", "add", BRIDGE_IP + "/30", "dev", BRIDGE])
        self.mutate(["ip", "link", "set", BRIDGE, "up"])
        self.mutate(["ip", "link", "set", VETH, "master", BRIDGE])
        self.mutate(["ip", "link", "set", VETH, "up"])
        self.state["resources"][NAMESPACE] = "intended"
        self.save()
        self.mutate(["ip", "netns", "add", NAMESPACE])
        identity = namespace_identity()
        if identity is None:
            raise RuntimeError("Created namespace disappeared; retain diagnostics.")
        self.state["resources"][NAMESPACE] = identity
        self.save()
        self.mutate(["ip", "link", "set", PEER, "netns", NAMESPACE])
        for argv in (["ip", "address", "add", CLIENT_IP + "/30", "dev", PEER],
                     ["ip", "link", "set", PEER, "up"], ["ip", "link", "set", "lo", "up"]):
            self.mutate(["ip", "netns", "exec", NAMESPACE, *argv])
        self.rule("bridge-udp", True)
        self.rule("bridge-tcp", True)
        self.mutate(["openssl", "req", "-x509", "-newkey", "rsa:2048", "-nodes", "-days", "1",
                     "-subj", "/CN=router-firewall.invalid", "-addext", "subjectAltName=IP:" + self.plan["router"],
                     "-keyout", str(ROOT / "key.pem"), "-out", str(ROOT / "cert.pem")])
        services = {
            "dns": ["dnsmasq", "--keep-in-foreground", "--conf-file=/dev/null", "--no-resolv", "--no-hosts",
                    "--bind-interfaces", "--except-interface=lo", "--listen-address=" + self.plan["router"] + "," + BRIDGE_IP,
                    "--port=53", "--pid-file=", "--host-record=router-firewall.invalid,192.0.2.179"],
            "https": ["openssl", "s_server", "-accept", self.plan["router"] + ":443", "-www", "-quiet",
                      "-key", str(ROOT / "key.pem"), "-cert", str(ROOT / "cert.pem")],
        }
        for kind, argv in services.items():
            self.state["resources"][UNITS[kind]] = "intended"
            self.save()
            self.mutate(["systemd-run", "--unit=" + UNITS[kind], "--description=" + OWNER,
                         "--collect", "--property=Type=exec", "--property=RuntimeMaxSec=7200", "--", *argv])
        self.health()
        self.state["phase"] = "prepared"
        self.save()

    def health(self):
        observations = []
        for unit in UNITS.values():
            if run(["systemctl", "is-active", unit], check=False).stdout.strip() != "active":
                raise RuntimeError("Fixture listener unit is not healthy; this cannot prove denial.")
        for transport in ("+notcp", "+tcp"):
            for prefix, source, destination, answer in (
                ([], self.plan["router"], self.plan["router"], "192.0.2.179"),
                (["ip", "netns", "exec", NAMESPACE], CLIENT_IP, BRIDGE_IP, "192.0.2.179"),
                ([], "127.0.0.1", "127.0.0.53", self.plan["vpn"]),
            ):
                query = "gateway.orbit" if destination == "127.0.0.53" else "router-firewall.invalid"
                argv = [*prefix, "dig", "-b", source, "@" + destination, transport,
                        "+short", "+time=2", "+tries=1", query, "A"]
                result = run(argv)
                observations.append({"argv": argv, "exit_code": result.returncode, "stdout": result.stdout})
                if result.stdout.strip() != answer:
                    raise RuntimeError(f"Healthy-listener/bridge/loopback positive control failed: {result.stdout}")
        result = run(["curl", "--fail", "--silent", "--show-error", "--max-time", "3", "--cacert", str(ROOT / "cert.pem"),
                      "--output", "/dev/null", "--write-out", "%{http_code}", "https://" + self.plan["router"] + "/"])
        if result.stdout != "200":
            raise RuntimeError("Local HTTPS positive control failed.")
        return {"lan_udp_tcp": True, "nested_bridge_udp_tcp": True, "loopback_udp_tcp": True, "https": True,
                "dns_answers": observations, "https_status": result.stdout}

    def public_key_pin(self):
        public = run(["openssl", "x509", "-in", str(ROOT / "cert.pem"), "-pubkey", "-noout"]).stdout
        der = subprocess.run(["openssl", "pkey", "-pubin", "-outform", "DER"], input=public.encode(),
                             capture_output=True, check=True, timeout=10).stdout
        return "sha256//" + base64.b64encode(hashlib.sha256(der).digest()).decode()

    def checkpoint(self, target):
        current = self.snapshot()
        if inventory_key(current) != inventory_key(target):
            raise RuntimeError("Rule inventory/order differs from checkpoint; exact restoration refused.")
        for path in USER_FILES:
            original = target["persistent"][path]
            self.guard()
            fd = os.open(path, os.O_WRONLY | os.O_TRUNC | os.O_NOFOLLOW)
            with os.fdopen(fd, "wb") as stream:
                stream.write(base64.b64decode(original["data"]))
                stream.flush()
                os.fsync(stream.fileno())
            self.guard()
            os.utime(path, ns=(original["mtime_ns"], original["mtime_ns"]), follow_symlinks=False)
        exact = self.snapshot()
        if (inventory_key(exact) != inventory_key(target)
                or exact["persistent"] != target["persistent"]):
            raise RuntimeError("Persistent/runtime exact restoration verification failed.")
        self.state["expected"] = exact
        self.save()

    def cleanup(self):
        for unit in UNITS.values():
            if unit not in self.state["resources"]:
                continue
            description = run(["systemctl", "show", unit, "--property=Description", "--value"], check=False).stdout.strip()
            loaded = run(["systemctl", "show", unit, "--property=LoadState", "--value"], check=False).stdout.strip()
            if loaded != "not-found":
                if description != OWNER:
                    raise RuntimeError("Foreign endpoint unit; stop refused.")
                self.mutate(["systemctl", "stop", unit])
            del self.state["resources"][unit]
            self.save()
        for link in (VETH, BRIDGE):
            if link not in self.state["resources"]:
                continue
            path = Path("/sys/class/net") / link
            if path.exists():
                if (path / "ifalias").read_text().strip() != OWNER:
                    raise RuntimeError("Foreign nested link; delete refused.")
                self.mutate(["ip", "link", "delete", link])
            del self.state["resources"][link]
            self.save()
        if NAMESPACE in self.state["resources"]:
            identity = namespace_identity()
            if identity is not None:
                if identity != self.state["resources"][NAMESPACE]:
                    raise RuntimeError("Foreign namespace; delete refused.")
                self.mutate(["ip", "netns", "delete", NAMESPACE])
            del self.state["resources"][NAMESPACE]
            self.save()

    def operate(self, operation):
        self.guard()
        path = ROOT / "state.json"
        if path.exists():
            info = path.lstat()
            if not stat.S_ISREG(info.st_mode) or info.st_uid != 0 or info.st_mode & 0o077:
                raise RuntimeError("Unsafe journal file.")
            self.state = json.loads(path.read_text())
            self.guard()
        if operation == "prepare":
            self.prepare()
        elif self.state is None:
            raise RuntimeError("No fixture baseline; prepare first.")
        elif operation != "inspect":
            self.assert_expected()
            phase = self.state["phase"]
            if operation == "overlap" and phase == "prepared":
                self.rule("overlap-udp", True)
                self.rule("overlap-tcp", True)
                self.state["overlap"] = self.snapshot()
                self.state["phase"] = "overlap"
            elif operation == "narrow" and phase in ("overlap", "rolled-back"):
                self.rule("overlap-tcp", False)
                self.rule("overlap-udp", False)
                self.state["phase"] = "narrowed"
            elif operation == "rollback" and phase == "narrowed":
                self.rule("overlap-udp", True)
                self.rule("overlap-tcp", True)
                self.checkpoint(self.state["overlap"])
                self.state["phase"] = "rolled-back"
            elif operation == "restore" and phase != "restored":
                for kind in list(reversed(self.state["rules"])):
                    self.rule(kind, False)
                self.checkpoint(self.state["baseline"])
                self.cleanup()
                self.state["phase"] = "restored"
            else:
                raise RuntimeError("Operation not valid in this fixture phase.")
            self.save()
        active = self.state["phase"] in ("prepared", "overlap", "narrowed", "rolled-back")
        health = None
        try:
            health = self.health() if active else None
        except Exception as error:
            if operation != "inspect":
                raise
            health = {"error": str(error)}
        matches = (inventory_key(self.snapshot()) == inventory_key(self.state["expected"])
                   and self.plan["operator_rules"] == self.state["operator_rules"])
        if operation != "inspect":
            self.assert_expected()
        return {"owner": OWNER, "phase": self.state["phase"], "health": health, "inventory_matches": matches,
                "certificate": (ROOT / "cert.pem").read_text() if active else None,
                "public_key_pin": self.public_key_pin() if active else None,
                "baseline": self.state["baseline"], "overlap": self.state.get("overlap"),
                "current": self.snapshot(), "operator_rules": self.state["operator_rules"],
                "resources": self.state["resources"], "diagnostics": self.state.get("diagnostics"),
                "journal": str(path)}


if __name__ == "__main__":
    fixture = None
    try:
        if os.geteuid() != 0 or os.environ.get("ORBIT_ROUTER_LAN_FIREWALL_FIXTURE") != "1":
            raise RuntimeError("The owned harness control requires root transport and ORBIT_ROUTER_LAN_FIREWALL_FIXTURE=1.")
        if len(sys.argv) != 3 or sys.argv[1] not in ("prepare", "overlap", "narrow", "rollback", "restore", "inspect"):
            raise RuntimeError("Only fixed fixture operations are allowed.")
        fixture = Fixture(json.loads(sys.argv[2]))
        print(json.dumps(fixture.operate(sys.argv[1]), sort_keys=True))
    except Exception as error:
        if fixture is not None and fixture.state is not None and sys.argv[1] != "inspect":
            try:
                fixture.state["diagnostics"] = {"error": str(error), "observed": fixture.snapshot()}
                fixture.save()
            except Exception:
                pass  # A lost lease must not permit even a diagnostic write.
        print(str(error) + "; retained baseline/journal: " + str(ROOT), file=sys.stderr)
        sys.exit(1)
