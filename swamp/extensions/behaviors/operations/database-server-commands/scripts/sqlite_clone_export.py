"""One SQLite clone export action."""

from sqlite_clone_common import copy_database, main


def apply(i):
    s = i["source"]
    return copy_database(
        {
            "operation": "export",
            "checkout": s["checkout"],
            "source": s["source"],
            "directory": s["directory"],
        }
    )


if __name__ == "__main__":
    main(apply)
