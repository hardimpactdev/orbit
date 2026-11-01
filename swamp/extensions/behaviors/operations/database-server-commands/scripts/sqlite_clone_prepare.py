"""One SQLite clone prepare action."""

from sqlite_clone_common import (
    copy_database,
    inside,
    lstat,
    main,
    os,
    path,
    re,
    regular,
    require,
)


def apply(i):
    t = i["target"]
    copy_database(
        {
            "operation": "prepare",
            "target_checkout": t["checkout"],
            "target": t["target"],
        }
    )
    selected = inside(t["checkout"], t["incoming"])
    require(
        selected.parent == path(t["target"]).parent
        and re.fullmatch(r"\.orbit-sqlite-[a-f0-9]{32}", selected.name)
    )
    if lstat(selected) is not None:
        regular(selected, os.geteuid(), mode=0o600, links=1)
    else:
        fd = os.open(
            selected, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600
        )
        os.close(fd)
    return {"ready": True}


if __name__ == "__main__":
    main(apply)
