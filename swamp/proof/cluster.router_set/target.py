"""Read public Caddy/DNS/certificate evidence on one disposable topology Node."""

import hashlib
import ipaddress
import json
import pwd
import subprocess
import sys
from pathlib import Path


def command(argv):
    return subprocess.run(
        argv, check=True, capture_output=True, text=True, timeout=20
    ).stdout


def digest(value):
    return hashlib.sha256(value.encode()).hexdigest()


def hosts(value):
    found = set()
    if isinstance(value, dict):
        found.update(
            value.get("host", []) if isinstance(value.get("host"), list) else []
        )
        for child in value.values():
            found.update(hosts(child))
    elif isinstance(value, list):
        for child in value:
            found.update(hosts(child))
    return found


def read(payload):
    state = command(
        ["/usr/bin/systemctl", "show", "caddy", "--property=ActiveState", "--value"]
    ).strip()
    config = Path("/etc/caddy/Caddyfile")
    result = {
        "active": state == "active",
        "caddy": "",
        "loaded_matches_disk": False,
        "hosts": [],
    }
    if config.exists():
        result["caddy"] = config.read_text()
        if result["active"]:
            loaded = json.loads(
                command(
                    [
                        "/usr/bin/curl",
                        "--noproxy",
                        "*",
                        "--fail",
                        "--silent",
                        "--show-error",
                        "--max-time",
                        "10",
                        "http://127.0.0.1:2019/config/",
                    ]
                )
            )
            adapted = json.loads(
                command(
                    [
                        "/usr/bin/caddy",
                        "adapt",
                        "--config",
                        str(config),
                        "--adapter",
                        "caddyfile",
                    ]
                )
            )
            result["loaded_matches_disk"] = loaded == adapted
            result["hosts"] = sorted(hosts(loaded))
    answer = command(
        ["/usr/bin/dig", "+time=2", "+tries=1", "+short", payload["domain"], "A"]
    )
    result["dns"] = sorted(
        {str(ipaddress.IPv4Address(line)) for line in answer.splitlines() if line}
    )
    result["leaves"] = []
    for leaf in payload.get("leaves", []):
        directory = (
            Path(pwd.getpwnam(leaf["user"]).pw_dir)
            / ".orbit/certificates"
            / leaf["scope"]
            / "current"
        )
        root = directory / "root.pem"
        certificate = (
            Path("/etc/caddy/orbit-certificates") / leaf["scope"] / "current/cert.pem"
        )
        command(
            [
                "/usr/bin/openssl",
                "verify",
                "-CAfile",
                str(root),
                "-verify_hostname",
                leaf["domain"],
                str(certificate),
            ]
        )
        result["leaves"].append(
            {
                "scope": leaf["scope"],
                "root_hash": digest(root.read_text()),
                "verified": True,
            }
        )
    result["cleanup"] = []
    for item in payload.get("cleanup", []):
        paths = [
            Path("/etc/caddy/orbit-certificates") / item["scope"],
            Path(pwd.getpwnam(item["user"]).pw_dir)
            / ".orbit/certificates"
            / item["scope"],
        ]
        result["cleanup"].append(
            {
                "scope": item["scope"],
                "absent": all(
                    not path.exists() and not path.is_symlink() for path in paths
                ),
            }
        )
    for name, path in {
        "dns_records": "/etc/dnsmasq.d/orbit-records.conf",
        "dns_catalog": "/var/lib/orbit/private-dns/catalog.json",
    }.items():
        result[name] = Path(path).read_text() if Path(path).exists() else ""
    if payload.get("https_address") is not None:
        result["https"] = command(
            [
                "/usr/bin/curl",
                "--noproxy",
                "*",
                "--fail",
                "--silent",
                "--show-error",
                "--max-time",
                "10",
                "--cacert",
                "/usr/local/share/ca-certificates/orbit-managed-root-ca.crt",
                "--resolve",
                payload["domain"] + ":443:" + payload["https_address"],
                "https://" + payload["domain"] + "/",
            ]
        )
    return result


if __name__ == "__main__":
    print(json.dumps(read(json.loads(sys.argv[1]))))
