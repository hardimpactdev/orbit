"""Official CLI process boundary for isolated native workflow tests."""

import base64
import hashlib
import json
import os
import sys
from pathlib import Path

assert sys.argv[1:4] == ["--yes", "--package=@bitwarden/cli@2026.6.0", "bw"]
args = sys.argv[4:]
state_path = Path(os.environ["ORBIT_BW_FIXTURE_STATE"])
state = json.loads(state_path.read_text())
state.setdefault("commands", []).append(args)
assert "ORBIT_VAULT_CONFIGURATION" not in os.environ
if state.get("fail_command") == args[0]:
    state_path.write_text(json.dumps(state))
    print(os.environ["FIXTURE_ITEM_SECRET"], file=sys.stderr)
    sys.exit(1)
if args[0] == "config":
    output = {}
elif args[0] == "login":
    assert os.environ["BW_CLIENTSECRET"] == os.environ["FIXTURE_CLIENT_SECRET"]
    output = {}
elif args[0] == "unlock":
    assert args == ["unlock", "--passwordenv", "BW_PASSWORD", "--raw"]
    assert os.environ["BW_PASSWORD"] == os.environ["FIXTURE_MASTER_PASSWORD"]
    state_path.write_text(json.dumps(state))
    print(os.environ["FIXTURE_SESSION"])
    sys.exit(0)
elif args[0] == "sync" or args[0] == "logout":
    output = {}
else:
    assert os.environ["BW_SESSION"] == os.environ["FIXTURE_SESSION"]
    if args[0] == "list":
        output = []
        for row in state["ciphers"]:
            if row["deleted"] != ("--trash" in args):
                continue
            output.append(
                {
                    "id": row["id"],
                    "organizationId": row["organization_id"],
                    "collectionIds": row["collection_ids"],
                    "revisionDate": row["revision"],
                    "deletedDate": "deleted" if row["deleted"] else None,
                    "fields": [
                        {"name": "orbit.source", "value": row["source"]},
                        {
                            "name": "password",
                            "value": os.environ["FIXTURE_ITEM_SECRET"],
                        },
                    ],
                }
            )
    elif args[0] == "delete":
        assert args[1] == "item" and args[3] == "--permanent"
        state["deletes"].append(args[2])
        state["ciphers"] = [row for row in state["ciphers"] if row["id"] != args[2]]
        output = {}
    else:
        assert args[0] in ("create", "edit") and args[1] == "item"
        payload = json.loads(base64.b64decode(sys.stdin.buffer.read()))
        fields = {field["name"]: field for field in payload["fields"]}
        assert fields["password"]["type"] == 1
        assert fields["password"]["value"] == os.environ["FIXTURE_ITEM_SECRET"]
        assert payload["type"] == 2 and payload["secureNote"] == {"type": 0}
        identity = args[2] if args[0] == "edit" else "new-cipher"
        state["writes"].append(
            {"source": fields["orbit.source"]["value"], "id": identity}
        )
        state["password_sha256"] = hashlib.sha256(
            fields["password"]["value"].encode()
        ).hexdigest()
        output = {"id": identity, "revisionDate": "new-revision", **payload}
        if state.get("fail_write"):
            state_path.write_text(json.dumps(state))
            print(os.environ["FIXTURE_ITEM_SECRET"], file=sys.stderr)
            sys.exit(1)
state_path.write_text(json.dumps(state))
print(json.dumps(output))
