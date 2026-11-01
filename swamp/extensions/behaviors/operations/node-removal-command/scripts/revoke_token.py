"""Reduce the departing Node's access through one official exact-name revoke."""

import json
import re
import signal
import subprocess
import sys

CLI = "/home/orbit/.local/bin/swamp"
REPOSITORY = "/home/orbit/apps/orbit-monorepo-staging/checkout/swamp"


def revoke(kind, node_id):
    if (
        kind not in ("worker", "access")
        or not re.fullmatch(r"[1-9][0-9]*", node_id)
        or node_id == "209"
    ):
        raise ValueError("node.token_revoke_identity_invalid")
    name = "orbit-node-" + node_id + ("-access" if kind == "access" else "")
    result = subprocess.run(
        [CLI, kind, "token", "revoke", name, "--repo-dir", REPOSITORY, "--json"],
        capture_output=True,
        text=True,
        check=False,
    )
    try:
        if result.returncode == 0:
            receipt = json.loads(result.stdout)
            if (
                isinstance(receipt, dict)
                and receipt.get("name") == name
                and receipt.get("state") == "revoked"
                and isinstance(receipt.get("alreadyRevoked"), bool)
            ):
                return {
                    "name": name,
                    "state": "revoked",
                    "already_revoked": receipt["alreadyRevoked"],
                }
        if result.returncode == 1 and not result.stdout:
            label = "Enrollment" if kind == "worker" else "Server"
            absent = {
                "error": f"{label} token '{name}' not found. Use 'swamp {kind} token list' to see existing tokens."
            }
            if json.loads(result.stderr) == absent:
                return {"name": name, "state": "absent"}
    except (ValueError, TypeError):
        pass
    raise RuntimeError(
        f"node.token_revoke_unproven: {kind} {name} exit {result.returncode}"
    )


def timed_out(_signal, _frame):
    print(
        "node.token_revoke_timeout: exact-name revocation exceeded 30 seconds",
        file=sys.stderr,
    )
    raise SystemExit(124)


if __name__ == "__main__":
    signal.signal(signal.SIGTERM, timed_out)
    try:
        print(json.dumps(revoke(*sys.argv[1:])))
    except (ValueError, RuntimeError, OSError) as error:
        print(str(error), file=sys.stderr)
        raise SystemExit(1) from None
