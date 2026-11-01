"""Cleanup the exact legacy snapshot descriptor; no discovery or target removal."""

from sqlite_seed_common import main
from sqlite_source_cleanup import clean


def apply(i):
    clean(i["cleanup"])
    return {"backend": "absent"}


if __name__ == "__main__":
    main(apply)
