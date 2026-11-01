"""One SQLite clone cleanup source action."""

from sqlite_clone_common import copy_database, main


def apply(i):
    copy_database({"operation": "cleanup", "directory": i["source"]["directory"]})
    return {"ready": True}


if __name__ == "__main__":
    main(apply)
