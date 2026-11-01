"""Complete the captured residue phase with the canonical host policies."""

import grp
import json
import pwd
import signal
import subprocess
import sys
from pathlib import Path

from canonical_certificate import remove

HERE = Path(__file__).parent


def policy(name, payload):
    result = subprocess.run(
        [sys.executable, "-B", str(HERE / ("canonical_" + name + ".py"))],
        input=json.dumps(payload),
        text=True,
        capture_output=True,
        check=True,
    )
    output = json.loads(result.stdout)
    if "error_code" in output:
        raise ValueError(output["error_code"])
    return output


def clean(data):
    if not data["rows"]:
        return {"changed": False}
    account = pwd.getpwnam(data["user"])
    if data["php"] is not None:
        group = grp.getgrgid(account.pw_gid).gr_name
        configurations = {
            version: text.replace("__orbit_converge_group__", group).replace(
                "/__orbit_converge_home__", account.pw_dir
            )
            for version, text in data["php"].items()
        }
        policy("php", {"configurations": configurations})
    # Legacy order is per residue, not all certificates followed by all rules.
    for row in data["rows"]:
        prefix = "route-" + str(row["route_id"])
        if "certificates" in row["steps"]:
            for scope in data["certificates"]:
                if scope == prefix or scope.startswith(prefix + "-"):
                    remove(
                        {
                            "scope": scope,
                            "user": data["user"],
                            "home": account.pw_dir,
                            "in_use": False,
                        }
                    )
        if "firewall" in row["steps"]:
            for rule in data["firewall"]:
                if rule["route_id"] == row["route_id"]:
                    policy("firewall", {"mode": "route", **rule})
    if data["ingress"] is not None:
        policy("firewall", {"mode": "rules", **data["ingress"]})
    return {"changed": True}


def expired(_signal, _frame):
    print("route-residue phase exceeded its 180-second budget", file=sys.stderr)
    raise SystemExit(124)


if __name__ == "__main__":
    signal.signal(signal.SIGTERM, expired)
    try:
        print(json.dumps(clean(json.load(sys.stdin))))
    except (
        KeyError,
        ValueError,
        OSError,
        TypeError,
        subprocess.SubprocessError,
    ) as error:
        code = str(error)
        if not code.startswith(("certificate.", "php.", "firewall.", "node.")):
            code = "route.residue_cleanup_failed"
        print(json.dumps({"error_code": code}))
        print(code, file=sys.stderr)
        raise SystemExit(1) from None
