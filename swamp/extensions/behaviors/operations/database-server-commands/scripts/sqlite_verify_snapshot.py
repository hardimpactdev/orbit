"""Verify one placed private download before native file projection or transfer."""

import json
import sys

from sqlite_clone_common import checked_payload

if __name__ == "__main__":
    try:
        ref = json.loads(sys.argv[2])
        checked_payload(sys.argv[1], ref["bytes"], ref["sha256"], private=True)
        print('{"state":"complete"}')
    except Exception:  # noqa: BLE001 -- private SQLite diagnostics stay out of results.
        print('{"error_code":"database.sqlite_snapshot_invalid"}')
        raise SystemExit(1) from None
