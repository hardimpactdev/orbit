"""Prove the admitted source before target creation."""

from mysql_clone_common import main, source_exists


def apply(i, m, source):
    source_exists(i, m, source)
    return {"ready": True}


if __name__ == "__main__":
    main(apply)
