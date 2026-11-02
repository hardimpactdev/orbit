"""Pinned Bitwarden CLI process fixture; private bytes never enter arguments."""

import json
import os
import sys
from pathlib import Path

assert sys.argv[1:4] == ["--yes", "--package=@bitwarden/cli@2026.6.0", "bw"]
args = sys.argv[4:]
assert "ORBIT_VAULT_CONFIGURATION" not in os.environ
path = Path(os.environ["ORBIT_BW_FIXTURE_STATE"])
state = json.loads(path.read_text())
state["commands"].append(args)
path.write_text(json.dumps(state))
if state.get("fail") == args[0] or (state.get("readonly") and args[0] == "create"):
    print(os.environ["FIXTURE_CLIENT_SECRET"], file=sys.stderr)
    sys.exit(1)
if args[0] == "login":
    assert os.environ["BW_CLIENTSECRET"] == os.environ["FIXTURE_CLIENT_SECRET"]
elif args[0] == "unlock":
    assert os.environ["BW_PASSWORD"] == os.environ["FIXTURE_MASTER_PASSWORD"]
    print(os.environ["FIXTURE_SESSION"])
    (Path(os.environ["BITWARDENCLI_APPDATA_DIR"]) / "data.json").write_text(
        json.dumps(
            {
                "user_account_kdfConfig_kdfConfig": {
                    "kdfType": state.get("kdf", 0),
                    "iterations": 600000,
                }
            }
        )
    )
    sys.exit(0)
elif args[0] == "list":
    assert os.environ["BW_SESSION"] == os.environ["FIXTURE_SESSION"]
    print(json.dumps(state[args[1]]))
    sys.exit(0)
elif args[0] == "status":
    print('{"userId":"account","userEmail":"setup@example.test"}')
    sys.exit(0)
elif args[0] == "create":
    assert "setup-private" not in sys.stdin.read()
    print('{"id":"setup-probe"}')
    sys.exit(0)
print("{}")
