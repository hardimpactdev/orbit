"""Existing Instance lower API, with its fixed program shipped as a file."""

import json
import os
import signal
import sys

from sqlite_clone_common import Refused, copy_database, require
from sqlite_seed_common import Unconfirmed, source_seed, target_seed

if __name__ == "__main__":
    action = sys.argv[1]
    family = "instance.sqlite_copy" if action == "copy" else "instance.sqlite_seed"
    try:
        os.umask(0o077)
        signal.alarm(int(sys.argv[2]))
        raw = sys.stdin.buffer.read(65537)
        require(len(raw) <= 65536)
        data = json.loads(raw)
        require(isinstance(data, dict))
        result = {
            "copy": copy_database,
            "seedSource": source_seed,
            "seedTarget": target_seed,
        }[action](data)
        print(json.dumps({"ok": True, "data": result}))
    except Unconfirmed:
        print('{"ok":false,"error_code":"instance.sqlite_seed_unconfirmed"}')
    except (
        Refused,
        PermissionError,
        FileNotFoundError,
        KeyError,
        TypeError,
        ValueError,
    ):
        print(json.dumps({"ok": False, "error_code": family + "_refused"}))
    except Exception:  # noqa: BLE001 -- keep filesystem diagnostics out of public outputs.
        print(json.dumps({"ok": False, "error_code": family + "_failed"}))
