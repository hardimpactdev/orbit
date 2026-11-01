"""One SQLite clone local action."""

from sqlite_clone_common import copy_database, main


def apply(i):
    s, t = i["source"], i["target"]
    copy_database(
        {
            "operation": "local",
            "checkout": s["checkout"],
            "source": s["source"],
            "target_checkout": t["checkout"],
            "target": t["target"],
        }
    )
    return {"backend": "active"}


if __name__ == "__main__":
    main(apply)
