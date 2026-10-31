"""Invoke the official Bitwarden CLI; no HTTP or cryptography implementation."""

import json
import os
import pathlib
import subprocess
import sys


def configuration():
    raw = json.loads(os.environ["ORBIT_VAULT_CONFIGURATION"])
    intent = json.loads(os.environ["ORBIT_VAULT_INTENT"])
    for name in ("url", "client_id", "organization_id", "collection_id"):
        if raw[name] != intent[name]:
            raise ValueError("vault.intent_changed")
    return raw, intent


def session_file():
    return pathlib.Path(os.environ["ORBIT_VAULT_DIRECTORY"]) / "session"


def cli(*args, payload=None, authenticate=None):
    config, intent = configuration()
    env = dict(os.environ)
    env.pop("ORBIT_VAULT_CONFIGURATION", None)
    env["BITWARDENCLI_APPDATA_DIR"] = os.environ["ORBIT_VAULT_DIRECTORY"]
    env["BW_NOINTERACTION"] = "true"
    env["NODE_EXTRA_CA_CERTS"] = intent["ca_file"]
    if authenticate == "login":
        env["BW_CLIENTID"] = config["client_id"]
        env["BW_CLIENTSECRET"] = config["client_secret"]
    elif authenticate == "unlock":
        env["BW_PASSWORD"] = config["master_password"]
    elif session_file().exists():
        env["BW_SESSION"] = session_file().read_text()
    result = subprocess.run(
        ["npx", "--yes", "--package=@bitwarden/cli@2026.6.0", "bw", *args],
        input=payload,
        stdout=subprocess.PIPE,
        stderr=subprocess.DEVNULL,
        env=env,
        check=True,
    )
    return result.stdout


def emit(action):
    try:
        print(json.dumps(action(), separators=(",", ":")))
    except Exception:  # noqa: BLE001 - CLI errors must never expose plaintext.
        # CLI/SDK errors may contain plaintext item fields or a session key.
        print('{"error_code":"vault.sync_failed"}')
        sys.exit(1)
