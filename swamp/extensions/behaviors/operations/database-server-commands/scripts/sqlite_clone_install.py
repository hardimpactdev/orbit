"""One SQLite clone install action."""

from sqlite_clone_common import copy_database, json, main, sys


def apply(i):
    t = i["target"]
    ref = json.loads(sys.argv[2])
    copy_database(
        {
            "operation": "install",
            "target_checkout": t["checkout"],
            "target": t["target"],
            "incoming": t["incoming"],
            "bytes": ref["bytes"],
            "sha256": ref["sha256"],
            "mode": ref["mode"],
        }
    )
    return {"backend": "active"}


if __name__ == "__main__":
    main(apply)
