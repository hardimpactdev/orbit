"""Verify a setup with the official CLI; stdout contains public metadata only."""

import base64
import json
import os
import subprocess
import sys
from pathlib import Path

from bw import cli, configuration, session_file


class Refusal(Exception):
    pass


def select(rows, wanted, code):
    matches = [row for row in rows if wanted in (row["id"], row["name"])]
    if len(matches) != 1:
        raise Refusal(code)
    return matches[0]


def setup():
    _, intent = configuration()
    directory = Path(os.environ["ORBIT_VAULT_DIRECTORY"])
    directory.mkdir(mode=0o700, exist_ok=True)
    cli("config", "server", intent["url"])
    cli("login", "--apikey", authenticate="login")
    session = cli(
        "unlock", "--passwordenv", "BW_PASSWORD", "--raw", authenticate="unlock"
    )
    descriptor = os.open(session_file(), os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600)
    with os.fdopen(descriptor, "wb") as stream:
        stream.write(session.strip())
    status = json.loads(cli("status"))
    # Pinned bw 2026.6.0 stores the public account KDF in this disk-state key.
    cache = json.loads((directory / "data.json").read_text())
    kdf = cache["user_" + status["userId"] + "_kdfConfig_kdfConfig"]
    if (
        kdf["kdfType"] != 0
        or type(kdf["iterations"]) is not int
        or not 1 <= kdf["iterations"] <= 2_000_000
    ):
        raise Refusal("vault.kdf_unsupported")
    organization = select(
        json.loads(cli("list", "organizations")),
        intent["organization"],
        "vault.organization_missing",
    )
    collection = select(
        [
            row
            for row in json.loads(cli("list", "collections"))
            if row["organizationId"] == organization["id"]
        ],
        intent["collection"],
        "vault.collection_missing",
    )
    previous = intent.get("previous")
    if (
        intent["published"]
        and previous
        and (
            previous["organization_id"] != organization["id"]
            or previous["collection_id"] != collection["id"]
        )
    ):
        raise Refusal("vault.items_published")
    email = status["userEmail"]
    # CLI collection exports omit readOnly. A harmless Secure Note proves write access.
    payload = {
        "type": 2,
        "secureNote": {"type": 0},
        "name": "Orbit setup check",
        "organizationId": organization["id"],
        "collectionIds": [collection["id"]],
    }
    try:
        probe = json.loads(
            cli(
                "create", "item", payload=base64.b64encode(json.dumps(payload).encode())
            )
        )
    except subprocess.CalledProcessError:
        raise Refusal("vault.collection_read_only") from None
    (directory / "probe").write_text(probe["id"])
    # Reject metadata which could echo a supplied credential before it reaches native history.
    result = {
        "backend": "configured",
        "email": email,
        "organization_id": organization["id"],
        "organization_name": organization["name"],
        "collection_id": collection["id"],
        "collection_name": collection["name"],
    }
    private, _ = configuration()
    if any(
        not isinstance(value, str)
        or len(value.encode()) > 1024
        or any(
            secret and secret in value
            for secret in (private["client_secret"], private["master_password"])
        )
        for value in result.values()
    ):
        raise Refusal("vault.result_invalid")
    return {"outcome": "succeeded", "data": result}


if __name__ == "__main__":
    code = None
    try:
        print(json.dumps(setup(), separators=(",", ":")))
    except Refusal as error:
        code = str(error)
    except subprocess.CalledProcessError:
        code = "vault.login_failed"
    except Exception:  # noqa: BLE001 - CLI diagnostics may contain a credential.
        code = "vault.unreachable"
    if code is not None:
        print(code, file=sys.stderr)
        intent = json.loads(os.environ["ORBIT_VAULT_INTENT"])
        sys.exit(intent["refusal_exit_codes"][code])
