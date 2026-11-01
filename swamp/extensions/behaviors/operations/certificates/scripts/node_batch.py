"""One Node's admitted leaves for one native request, publish, trust or inspect step."""

import json
import os
import pathlib
import re
import subprocess
import sys

from local import account, valid
from publish import publish
from request import request
from trust import trust


def refusal(error):
    code = str(error)
    return {
        "error_code": code
        if re.fullmatch(r"certificate\.[a-z_]+", code)
        else "certificate.action_failed"
    }


def run(action, payload, requests_file=None):
    leaves = payload["leaves"]
    if (
        not 1 <= len(leaves) <= 256
        or any(
            leaf["node_id"] != leaves[0]["node_id"]
            or leaf["root_hash"] != leaves[0]["root_hash"]
            for leaf in leaves
        )
        or len({leaf["scope"] for leaf in leaves}) != len(leaves)
    ):
        raise ValueError("certificate.node_batch_invalid")
    if action == "trust":
        return trust(leaves[0])
    records = []
    signatures = {
        (item["node_id"], item["scope"]): item
        for item in payload.get("certificates", [])
    }
    for leaf in leaves:
        try:
            if action == "request":
                records.append(
                    dict(node_id=leaf["node_id"], scope=leaf["scope"], **request(leaf))
                )
            elif action == "publish":
                # The existing primitive first recognizes an already-current pair.
                signed = signatures.get((leaf["node_id"], leaf["scope"]), {})
                os.environ["ORBIT_CERTIFICATE_SIGNED"] = json.dumps(signed)
                publish(leaf)
            elif action == "inspect":
                _, root, caddy = account(leaf)
                if not valid(root, caddy, leaf):
                    raise ValueError("certificate.presence_mismatch")
                records.append(
                    {
                        **{key: leaf[key] for key in ("node_id", "scope", "version")},
                        "present": True,
                    }
                )
            else:
                raise ValueError("certificate.action_invalid")
        except (
            OSError,
            ValueError,
            KeyError,
            TypeError,
            subprocess.CalledProcessError,
        ) as error:
            return {
                **refusal(error),
                "node_id": leaf["node_id"],
                "scope": leaf["scope"],
            }
    if action == "request":
        result = {"requests": records}
        path = pathlib.Path(requests_file)
        path.write_text(json.dumps(result))
        path.chmod(0o644)  # Only public CSRs; official SSH copy returns them to209.
        return result
    return {"leaves": records} if action == "inspect" else {"applied": True}


if __name__ == "__main__":
    try:
        raw = sys.stdin.read(16_777_217)
        if len(raw) > 16_777_216:
            raise ValueError("certificate.node_batch_invalid")
        result = run(
            sys.argv[1], json.loads(raw), sys.argv[2] if len(sys.argv) == 3 else None
        )
    except (
        OSError,
        ValueError,
        KeyError,
        TypeError,
        subprocess.CalledProcessError,
    ) as error:
        result = refusal(error)
    if "error_code" in result:
        print(json.dumps(result), file=sys.stderr)
    print(json.dumps(result))
    sys.exit(1 if "error_code" in result else 0)
