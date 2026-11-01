"""One SQLite clone cleanup target action."""

from sqlite_clone_common import (
    inside,
    lstat,
    main,
    os,
    path,
    re,
    regular,
    require,
    walk,
)


def apply(i):
    t = i["target"]
    selected = inside(t["checkout"], t["incoming"])
    require(
        selected.parent == path(t["target"]).parent
        and re.fullmatch(r"\.orbit-sqlite-[a-f0-9]{32}", selected.name)
    )
    if walk(selected.parent, missing_ok=True) is None:
        return {"ready": True}
    if lstat(selected) is not None:
        regular(selected, os.geteuid(), mode=0o600, links=1)
        selected.unlink()
    return {"ready": True}


if __name__ == "__main__":
    main(apply)
